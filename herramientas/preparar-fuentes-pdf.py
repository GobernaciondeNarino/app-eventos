#!/usr/bin/env python3
"""Prepara las tipografías con que se escriben los certificados en PDF.

Los certificados (3.21) llevan la letra incrustada: así se ven iguales en
cualquier computador y en cualquier impresora, tenga o no la fuente instalada.
La familia es Montserrat, de licencia libre (SIL Open Font License 1.1, ver
app/Recursos/fuentes/OFL.txt), que además es la del logo de la Cumbre.

Lo que hace este guion, una sola vez y no en el servidor:

  1. Descarga cuatro pesos de Montserrat de su repositorio oficial.
  2. Se queda solo con los caracteres de Windows-1252 —el español entero:
     tildes, eñe, diéresis, «comillas», el signo de euro—, que son los que el
     PDF puede escribir con la codificación estándar. Una fuente completa pesa
     450 KB; recortada, unas 40.
  3. Quita las tablas de composición tipográfica (GSUB, GPOS), que un PDF no
     lee, y pasa el interletrado de GPOS a una tabla «kern» clásica, que el
     generador de PHP sí sabe leer: sin él, pares como «AT» o «LT» quedan
     separados de más en los títulos en mayúsculas.

Uso, desde la raíz del proyecto:

    pip install fonttools
    python3 herramientas/preparar-fuentes-pdf.py

Escribe los .ttf en app/Recursos/fuentes/. Solo hay que volver a ejecutarlo
para cambiar de familia o de pesos; el servidor no lo necesita.
"""
import io
import os
import shutil
import urllib.request

from fontTools import subset
from fontTools.ttLib import TTFont, newTable
from fontTools.ttLib.tables._k_e_r_n import KernTable_format_0

RAIZ = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DESTINO = os.path.join(RAIZ, "app", "Recursos", "fuentes")
ORIGEN = "https://raw.githubusercontent.com/JulietaUla/Montserrat/master/"
PESOS = ["Regular", "SemiBold", "Bold", "ExtraBold"]

# Un par con menos de esto, en milésimas de eme, no se nota ni impreso: se
# deja fuera para que la tabla quepa en una sola subtabla del formato 0.
MINIMO_KERN = 10


def cp1252():
    """Los puntos de código Unicode que Windows-1252 sabe escribir."""
    puntos = set()
    for b in range(0x20, 0x100):
        try:
            puntos.add(ord(bytes([b]).decode("cp1252")))
        except UnicodeDecodeError:
            pass
    return puntos


def pares_de_kern(fuente, glifos):
    """Aplana el interletrado del GPOS (formatos 1 y 2) a pares de glifos."""
    pares = {}
    gpos = fuente["GPOS"].table
    for consulta in gpos.LookupList.Lookup:
        for sub in consulta.SubTable:
            if consulta.LookupType == 9:
                sub = sub.ExtSubTable
            if getattr(sub, "LookupType", 2) != 2 or not hasattr(sub, "Coverage"):
                continue
            cubiertos = sub.Coverage.glyphs
            if sub.Format == 1:
                for g1, conjunto in zip(cubiertos, sub.PairSet):
                    if g1 not in glifos:
                        continue
                    for registro in conjunto.PairValueRecord:
                        v = getattr(registro.Value1, "XAdvance", 0) if registro.Value1 else 0
                        if v and registro.SecondGlyph in glifos:
                            pares.setdefault((g1, registro.SecondGlyph), v)
            elif sub.Format == 2:
                clases1 = sub.ClassDef1.classDefs
                clases2 = sub.ClassDef2.classDefs
                for g1 in cubiertos:
                    if g1 not in glifos:
                        continue
                    fila = sub.Class1Record[clases1.get(g1, 0)].Class2Record
                    for g2 in glifos:
                        valor = fila[clases2.get(g2, 0)].Value1
                        v = getattr(valor, "XAdvance", 0) if valor else 0
                        if v:
                            pares.setdefault((g1, g2), v)
    return {k: v for k, v in pares.items() if abs(v) >= MINIMO_KERN}


def preparar(peso, puntos):
    with urllib.request.urlopen(ORIGEN + "fonts/ttf/Montserrat-" + peso + ".ttf") as r:
        crudo = r.read()

    original = TTFont(io.BytesIO(crudo))
    mapa = original.getBestCmap()
    glifos = {mapa[u] for u in puntos if u in mapa}
    pares = pares_de_kern(original, glifos)

    opciones = subset.Options()
    opciones.layout_features = []
    opciones.name_IDs = ["*"]
    opciones.name_languages = ["*"]
    opciones.notdef_outline = True
    opciones.glyph_names = False
    opciones.drop_tables += ["DSIG", "GSUB", "GPOS", "GDEF"]
    recortadora = subset.Subsetter(opciones)
    recortadora.populate(unicodes=sorted(puntos))
    fuente = TTFont(io.BytesIO(crudo))
    recortadora.subset(fuente)

    kern = newTable("kern")
    kern.version = 0
    tabla = KernTable_format_0()
    tabla.version = 0
    tabla.coverage = 1
    tabla.format = 0
    tabla.kernTable = pares
    kern.kernTables = [tabla]
    fuente["kern"] = kern

    salida = os.path.join(DESTINO, "Montserrat-" + peso + ".ttf")
    fuente.save(salida)
    print("%-28s %6d bytes, %4d glifos, %5d pares de kern"
          % (os.path.relpath(salida, RAIZ), os.path.getsize(salida),
             len(fuente.getGlyphOrder()), len(pares)))


def main():
    os.makedirs(DESTINO, exist_ok=True)
    puntos = cp1252()
    for peso in PESOS:
        preparar(peso, puntos)
    with urllib.request.urlopen(ORIGEN + "OFL.txt") as r, \
            open(os.path.join(DESTINO, "OFL.txt"), "wb") as f:
        shutil.copyfileobj(r, f)
    print("app/Recursos/fuentes/OFL.txt   la licencia, que viaja con las fuentes")


if __name__ == "__main__":
    main()

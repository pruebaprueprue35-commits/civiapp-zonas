"""Uso:
    python -m zonas generar --municipios datos/municipios.ndjson --radios datos/radios \
        --salida dist --version 2026.10.25 [--provincias 90,06] [--barreras datos/barreras.geojsonl] \
        [--lugares datos/lugares.geojsonl] [--fuentes datos/fuentes.json] [--zip]
    python -m zonas verificar dist
"""
from __future__ import annotations

import argparse
import hashlib
import json
import os
import sys
import zipfile

from .generar import SCHEMA, generar


def _cargar_params(path):
    with open(path, encoding="utf-8") as fh:
        return json.load(fh)


def cmd_generar(a):
    params = _cargar_params(a.parametros)
    provs = {p.strip().zfill(2) for p in a.provincias.split(",")} if a.provincias and a.provincias != "todas" else None
    fuentes = []
    if a.fuentes and os.path.exists(a.fuentes):
        with open(a.fuentes, encoding="utf-8") as fh:
            fuentes = json.load(fh)
    man = generar(a.municipios, a.radios, a.salida, a.version, params, provs, a.barreras, a.lugares, fuentes,
                  base_url=a.base_url)
    escribir_resumen(a.salida, man)
    if a.zip:
        empaquetar(a.salida, man)
    t = man["totales"]
    fmt = lambda n: f"{n:,}".replace(",", ".")
    print(f"Listo: {t['provincias']} provincias, {fmt(t['municipios'])} municipios, {fmt(t['zonas'])} zonas "
          f"({fmt(t['zonas_para_revisar'])} para revisar), población {fmt(t['poblacion'])}")
    errores = verificar(a.salida)
    if errores:
        print("\n".join(errores), file=sys.stderr)
        return 1
    return 0


def escribir_resumen(out_dir, man):
    """RESUMEN.md legible para la publicación (notas del release)."""
    t = man["totales"]
    fmt = lambda n: f"{n:,}".replace(",", ".")
    lines = [f"# Zonas CiviApp {man['version']}", "",
             f"{t['provincias']} provincias · {fmt(t['municipios'])} municipios · {fmt(t['zonas'])} zonas "
             f"({fmt(t['zonas_para_revisar'])} para revisar) · población cubierta {fmt(t['poblacion'])} · "
             f"radios fuera de municipios {fmt(t['radios_sin_municipio'])}", "",
             "| Provincia | Municipios | Con radios | Zonas | Para revisar | Población | Radios fuera de municipios |",
             "| --- | ---: | ---: | ---: | ---: | ---: | ---: |"]
    for p in man["provincias"]:
        lines.append(f"| {p['nombre']} ({p['georef_id']}) | {p['municipios']} | {p['municipios_con_radios']} | "
                     f"{fmt(p['zonas'])} | {p['zonas_para_revisar']} | {fmt(p['poblacion'])} | {p.get('radios_sin_municipio', 0)} |")
    lines += ["", man["atribucion"], ""]
    with open(os.path.join(out_dir, "RESUMEN.md"), "w", encoding="utf-8") as fh:
        fh.write("\n".join(lines))


def empaquetar(out_dir, man):
    """ZIP para subir a mano en WordPress: manifiesto + partes + cobertura."""
    name = os.path.join(out_dir, f"civiapp-zonas-{man['version']}.zip")
    files = ["manifest.json", man["cobertura"]["archivo"]] + [p["archivo"] for pr in man["provincias"] for p in pr["partes"]]
    with zipfile.ZipFile(name, "w", compression=zipfile.ZIP_STORED) as z:
        for f in files:
            info = zipfile.ZipInfo(f, date_time=(2026, 1, 1, 0, 0, 0))
            with open(os.path.join(out_dir, f), "rb") as fh:
                z.writestr(info, fh.read())
    return name


def verificar(out_dir) -> list:
    """Controles del paquete: esquema, hashes, líneas, una zona por radio, municipios completos."""
    errores = []
    try:
        with open(os.path.join(out_dir, "manifest.json"), encoding="utf-8") as fh:
            man = json.load(fh)
    except Exception as e:  # noqa: BLE001
        return [f"manifest ilegible: {e}"]
    if man.get("schema") != SCHEMA:
        errores.append("schema inesperado")
    import gzip
    vistos_uid = set()
    for pr in man.get("provincias", []):
        radios_vistos = set()
        munis = set()
        zonas = 0
        for parte in pr["partes"]:
            path = os.path.join(out_dir, parte["archivo"])
            with open(path, "rb") as fh:
                data = fh.read()
            if hashlib.sha256(data).hexdigest() != parte["sha256"]:
                errores.append(f"hash distinto en {parte['archivo']}")
                continue
            lines = gzip.decompress(data).decode("utf-8").splitlines()
            if len(lines) != parte["lineas"]:
                errores.append(f"líneas distintas en {parte['archivo']}")
            actual = None
            for ln in lines:
                o = json.loads(ln)
                if o["tipo"] == "municipio":
                    actual = o["municipio_georef"]
                    if actual in munis:
                        errores.append(f"municipio {actual} partido entre partes")
                    munis.add(actual)
                elif o["tipo"] == "zona":
                    zonas += 1
                    if o["municipio_georef"] != actual:
                        errores.append(f"zona {o['uid']} fuera de su municipio")
                    if o["uid"] in vistos_uid:
                        errores.append(f"uid repetido {o['uid']}")
                    vistos_uid.add(o["uid"])
                    for c in o["radios"]:
                        if c in radios_vistos:
                            errores.append(f"radio {c} en dos zonas")
                        radios_vistos.add(c)
                    g = o.get("geometry") or {}
                    if g.get("type") not in ("Polygon", "MultiPolygon"):
                        errores.append(f"zona {o['uid']} sin polígono")
        if zonas != pr["zonas"]:
            errores.append(f"provincia {pr['georef_id']}: zonas {zonas} != {pr['zonas']}")
        if len(munis) != pr["municipios"]:
            errores.append(f"provincia {pr['georef_id']}: municipios {len(munis)} != {pr['municipios']}")
    return errores


def main(argv=None):
    ap = argparse.ArgumentParser(prog="zonas")
    sub = ap.add_subparsers(dest="cmd", required=True)
    g = sub.add_parser("generar")
    g.add_argument("--municipios", required=True)
    g.add_argument("--radios", required=True, help="carpeta con un archivo por provincia: 90.geojsonl")
    g.add_argument("--salida", required=True)
    g.add_argument("--version", required=True)
    g.add_argument("--provincias", default="todas")
    g.add_argument("--barreras")
    g.add_argument("--lugares")
    g.add_argument("--fuentes")
    g.add_argument("--parametros", default=os.path.join(os.path.dirname(__file__), "..", "config", "parametros.json"))
    g.add_argument("--zip", action="store_true")
    g.add_argument("--base-url", default="", help="URL base donde quedarán publicadas las partes")
    v = sub.add_parser("verificar")
    v.add_argument("salida")
    a = ap.parse_args(argv)
    if a.cmd == "generar":
        return cmd_generar(a)
    errs = verificar(a.salida)
    print("\n".join(errs) if errs else "Paquete correcto")
    return 1 if errs else 0


if __name__ == "__main__":
    sys.exit(main())

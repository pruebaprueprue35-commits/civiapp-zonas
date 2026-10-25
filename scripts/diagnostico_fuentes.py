"""Diagnóstico de fuentes: prueba cada dirección, muestra tamaños, formato y columnas reales.

Corre en GitHub Actions y deja un informe en Markdown (para ajustar config/fuentes.json sin adivinar).
Uso: python scripts/diagnostico_fuentes.py --salida diagnostico.md
"""
from __future__ import annotations

import argparse
import gzip
import io
import json
import os
import traceback
import urllib.request

UA = "civiapp-zonas/1.0 (+https://civiapp.com.ar)"

CANDIDATOS = {
    "georef_municipios": [
        "https://apis.datos.gob.ar/georef/api/v2.0/municipios.ndjson",
        "https://apis.datos.gob.ar/georef/api/v2.0/municipios.geojson",
        "https://apis.datos.gob.ar/georef/api/municipios.ndjson",
        "https://apis.datos.gob.ar/georef/api/municipios.geojson",
        "https://infra.datos.gob.ar/georef/municipios.ndjson",
        "https://infra.datos.gob.ar/georef/municipios.geojson",
        "https://apis.datos.gob.ar/georef/api/municipios?provincia=90&max=3&campos=completo&formato=geojson",
    ],
    "source_coop": [
        "https://data.source.coop/nlebovits/censo-argentino/catalog.json",
        "https://data.source.coop/nlebovits/censo-argentino/2022/catalog.json",
        "https://data.source.coop/nlebovits/censo-argentino/2022/collection.json",
        "https://data.source.coop/nlebovits/censo-argentino/2022/radios.parquet",
        "https://data.source.coop/nlebovits/censo-argentino/2022/census-data.parquet",
        "https://data.source.coop/nlebovits/censo-argentino/2022/metadata.parquet",
    ],
    "osm": [
        "https://download.geofabrik.de/south-america/argentina-latest.osm.pbf",
    ],
}


def probe(url, max_bytes=65536):
    req = urllib.request.Request(url, headers={"User-Agent": UA, "Range": f"bytes=0-{max_bytes - 1}"})
    with urllib.request.urlopen(req, timeout=60) as r:
        data = r.read(max_bytes)
        total = r.headers.get("Content-Range", "").split("/")[-1] or r.headers.get("Content-Length", "?")
        return r.status, r.headers.get("Content-Type", ""), total, r.geturl(), data


def fetch(url, path):
    req = urllib.request.Request(url, headers={"User-Agent": UA})
    with urllib.request.urlopen(req, timeout=900) as r, open(path, "wb") as fh:
        while True:
            chunk = r.read(1 << 20)
            if not chunk:
                break
            fh.write(chunk)
    return os.path.getsize(path)


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--salida", default="diagnostico.md")
    ap.add_argument("--tmp", default="_diag")
    a = ap.parse_args()
    os.makedirs(a.tmp, exist_ok=True)
    out = ["# Diagnóstico de fuentes", ""]

    def w(s=""):
        out.append(s)
        print(s, flush=True)

    for grupo, urls in CANDIDATOS.items():
        w(f"## {grupo}")
        for url in urls:
            try:
                st, ct, total, final, data = probe(url)
                w(f"- OK {st} `{url}` tipo={ct} tamaño={total} final={final}")
                head = data[:1500]
                if data[:2] == b"\x1f\x8b":
                    try:
                        head = gzip.GzipFile(fileobj=io.BytesIO(data)).read(1500)
                    except Exception:  # noqa: BLE001
                        pass
                if b"PAR1" == data[:4]:
                    w("  - es Parquet")
                elif "json" in ct or head.strip()[:1] in (b"{", b"["):
                    txt = head.decode("utf-8", "replace")
                    w("  - inicio: `" + txt[:700].replace("`", "'").replace("\n", " ⏎ ") + "`")
            except Exception as e:  # noqa: BLE001
                w(f"- FALLA `{url}`: {e}")
        w()

    # GeoRef: ¿trae geometría el NDJSON? contar líneas y claves.
    w("## GeoRef municipios: contenido")
    for url in CANDIDATOS["georef_municipios"][:4]:
        try:
            p = os.path.join(a.tmp, "muni")
            n = fetch(url, p)
            w(f"- `{url}` descargado: {n:,} bytes")
            with open(p, "rb") as fh:
                first = fh.read(3000)
            w("  - primeros bytes: `" + first[:1200].decode("utf-8", "replace").replace("`", "'").replace("\n", " ⏎ ") + "`")
            lines = 0
            con_geom = 0
            claves = None
            with open(p, "r", encoding="utf-8", errors="replace") as fh:
                for ln in fh:
                    ln = ln.strip()
                    if not ln:
                        continue
                    lines += 1
                    if lines <= 3:
                        try:
                            o = json.loads(ln)
                            claves = sorted(o.keys()) if isinstance(o, dict) else type(o).__name__
                            if isinstance(o, dict) and (o.get("geometria") or o.get("geometry")):
                                con_geom += 1
                        except Exception:  # noqa: BLE001
                            pass
            w(f"  - líneas: {lines}; claves de la primera: {claves}; con geometría (de las 3 primeras): {con_geom}")
            break
        except Exception as e:  # noqa: BLE001
            w(f"- FALLA `{url}`: {e}")
    w()

    # source.coop: esquema real de los Parquet.
    w("## Radios 2022 (source.coop): esquema")
    try:
        import pyarrow.parquet as pq
        for name in ("radios.parquet", "census-data.parquet", "metadata.parquet"):
            url = "https://data.source.coop/nlebovits/censo-argentino/2022/" + name
            try:
                p = os.path.join(a.tmp, name)
                n = fetch(url, p)
                f = pq.ParquetFile(p)
                w(f"- `{name}`: {n:,} bytes, filas={f.metadata.num_rows:,}")
                w(f"  - esquema: `{f.schema_arrow}`".replace("\n", " ⏎ ")[:3000])
                md = f.schema_arrow.metadata or {}
                if b"geo" in md:
                    w("  - metadatos geo: `" + md[b"geo"].decode()[:600] + "`")
                t = f.read_row_group(0).slice(0, 5).to_pandas()
                for c in t.columns:
                    if t[c].dtype == object:
                        t[c] = t[c].map(lambda v: (v[:40] + b"..") if isinstance(v, (bytes, bytearray)) else v)
                w("  - primeras filas:\n\n```\n" + t.to_string()[:3000] + "\n```")
                if name == "census-data.parquet":
                    df = f.read().to_pandas()
                    for c in df.columns:
                        if df[c].dtype == object and df[c].nunique() < 2000:
                            vals = df[c].value_counts().head(40)
                            w(f"  - valores de `{c}` ({df[c].nunique()} distintos):\n\n```\n{vals.to_string()[:4000]}\n```")
                    num = [c for c in df.columns if str(df[c].dtype).startswith(("int", "float"))]
                    w(f"  - columnas numéricas: {num}; sumas: {[int(df[c].sum()) for c in num]}")
            except Exception as e:  # noqa: BLE001
                w(f"- FALLA `{name}`: {e}")
                w("```\n" + traceback.format_exc()[-1500:] + "\n```")
    except ImportError as e:
        w(f"- sin pyarrow: {e}")
    with open(a.salida, "w", encoding="utf-8") as fh:
        fh.write("\n".join(out) + "\n")


if __name__ == "__main__":
    main()

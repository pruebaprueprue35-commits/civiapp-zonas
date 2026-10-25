"""Descarga y normaliza las fuentes públicas para el generador de zonas.

Corre en GitHub Actions (que tiene internet). Deja en `datos/`:
  municipios.ndjson            municipios de GeoRef con geometría
  radios/<prov>.geojsonl       radios censales 2022 con "codigo" y "poblacion", un archivo por provincia
  barreras.geojsonl            avenidas principales, vías de tren, ríos y canales (OSM)
  lugares.geojsonl             nombres de barrios y parajes (OSM)
  fuentes.json                 qué se descargó, de dónde, cuándo y con qué hash

Requiere: geopandas, pyarrow, shapely (pip) y osmium-tool (apt) para OSM.
Uso: python scripts/descargar_fuentes.py --config config/fuentes.json --salida datos [--sin-osm] [--diagnostico]
"""
from __future__ import annotations

import argparse
import datetime as dt
import hashlib
import json
import os
import shutil
import subprocess
import sys
import urllib.request

UA = "civiapp-zonas/1.0 (+https://civiapp.com.ar)"


def log(msg):
    print(msg, flush=True)


def descargar(urls, destino, minimo_bytes=1000):
    """Prueba cada URL en orden; devuelve (url_usada, sha256, bytes)."""
    errores = []
    for url in urls:
        try:
            log(f"Descargando {url}")
            req = urllib.request.Request(url, headers={"User-Agent": UA})
            h = hashlib.sha256()
            n = 0
            with urllib.request.urlopen(req, timeout=600) as r, open(destino, "wb") as fh:
                while True:
                    chunk = r.read(1 << 20)
                    if not chunk:
                        break
                    fh.write(chunk)
                    h.update(chunk)
                    n += len(chunk)
            if n < minimo_bytes:
                raise RuntimeError(f"archivo demasiado chico ({n} bytes)")
            log(f"  ok: {n:,} bytes")
            return url, h.hexdigest(), n
        except Exception as e:  # noqa: BLE001
            errores.append(f"{url}: {e}")
            log(f"  falló: {e}")
    raise SystemExit("No se pudo descargar ninguna URL:\n" + "\n".join(errores))


def normalizar_municipios(path, destino):
    """Deja un NDJSON con id, nombre, provincia y geometría. Acepta NDJSON de GeoRef o GeoJSON."""
    sys.path.insert(0, os.path.join(os.path.dirname(__file__), ".."))
    from zonas.entrada import iter_features, pick
    from zonas.generar import CLAVES_MUNICIPIO
    n = 0
    sin_geom = 0
    with open(destino, "w", encoding="utf-8") as out:
        for f in iter_features(path):
            p = f.get("properties") or {}
            g = f.get("geometry")
            if not g or g.get("type") not in ("Polygon", "MultiPolygon"):
                sin_geom += 1
                continue
            rec = {
                "id": str(pick(p, CLAVES_MUNICIPIO["id"])),
                "nombre": pick(p, CLAVES_MUNICIPIO["nombre"]),
                "provincia_id": str(pick(p, CLAVES_MUNICIPIO["provincia_id"]) or "").zfill(2),
                "provincia_nombre": pick(p, CLAVES_MUNICIPIO["provincia_nombre"]),
                "geometria": g,
            }
            out.write(json.dumps(rec, ensure_ascii=False) + "\n")
            n += 1
    log(f"Municipios con polígono: {n} (sin geometría: {sin_geom})")
    if n < 500:
        raise SystemExit("La fuente de municipios no trae polígonos suficientes; revisar config/fuentes.json")
    return n


def elegir_poblacion(df, clave, total_pais, tolerancia=0.03):
    """Devuelve una serie {codigo_radio: poblacion}.

    Tabla ancha: la columna numérica cuyo total coincide con la población del país.
    Tabla larga (una fila por radio y variable): la variable cuyo total coincide.
    """
    import pandas as pd
    lo, hi = total_pais * (1 - tolerancia), total_pais * (1 + tolerancia)
    num_cols = [c for c in df.columns if c != clave and pd.api.types.is_numeric_dtype(df[c])]
    var_cols = [c for c in df.columns if c != clave and not pd.api.types.is_numeric_dtype(df[c])]
    candidatos = []
    for c in num_cols:
        s = df.groupby(clave)[c].sum() if df[clave].duplicated().any() else df.set_index(clave)[c]
        tot = float(s.sum())
        if lo <= tot <= hi:
            candidatos.append((abs(tot - total_pais), c, None, s))
    if not candidatos and var_cols and num_cols:
        for vc in var_cols:
            if df[vc].nunique() > 5000:
                continue
            for valc in num_cols:
                sums = df.groupby(vc)[valc].sum()
                for var, tot in sums.items():
                    if lo <= float(tot) <= hi:
                        s = df[df[vc] == var].groupby(clave)[valc].sum()
                        candidatos.append((abs(float(tot) - total_pais), valc, (vc, var), s))
    if not candidatos:
        raise SystemExit("No encontré una columna/variable cuyo total sea la población del país. "
                         "Correr con --diagnostico y fijar la variable en config/fuentes.json.")
    candidatos.sort(key=lambda t: (t[0], str(t[1]), str(t[2])))
    _, col, var, serie = candidatos[0]
    log(f"Población tomada de: columna={col} variable={var} total={int(serie.sum()):,}")
    return serie.round().astype("int64"), {"columna": col, "variable": list(var) if var else None,
                                            "total": int(serie.sum())}


def normalizar_radios(cfg, tmp, salida, diagnostico=False):
    import geopandas as gpd
    import pandas as pd

    rcfg = cfg["radios"]
    fuentes = []
    gpath = os.path.join(tmp, "radios_geom" + os.path.splitext(rcfg["geometria"]["urls"][0])[1])
    url, sha, n = descargar(rcfg["geometria"]["urls"], gpath)
    fuentes.append({"id": "radios_geometria", "url": url, "sha256": sha, "bytes": n})
    gdf = gpd.read_parquet(gpath) if gpath.endswith(".parquet") else gpd.read_file(gpath)
    if gdf.crs is not None and gdf.crs.to_epsg() != 4326:
        gdf = gdf.to_crs(4326)
    gclave = next((c for c in rcfg["geometria"]["clave"] if c in gdf.columns), None)
    if diagnostico or not gclave:
        log(f"Columnas de radios: {list(gdf.columns)}\n{gdf.head(3)}")
    if not gclave:
        raise SystemExit("No encontré la columna de código de radio en la geometría.")
    gdf[gclave] = gdf[gclave].astype(str).str.strip()

    pob = None
    meta_pob = None
    if any(c in gdf.columns for c in ("poblacion", "personas", "pob_total", "total_pob")):
        col = next(c for c in ("poblacion", "personas", "pob_total", "total_pob") if c in gdf.columns)
        pob = gdf.set_index(gclave)[col]
        meta_pob = {"columna": col, "variable": None, "total": int(pob.sum())}
    elif rcfg.get("poblacion", {}).get("urls"):
        ppath = os.path.join(tmp, "radios_pob" + os.path.splitext(rcfg["poblacion"]["urls"][0])[1])
        url, sha, n = descargar(rcfg["poblacion"]["urls"], ppath)
        fuentes.append({"id": "radios_poblacion", "url": url, "sha256": sha, "bytes": n})
        df = pd.read_parquet(ppath) if ppath.endswith(".parquet") else pd.read_csv(ppath)
        pclave = next((c for c in rcfg["poblacion"]["clave"] if c in df.columns), None)
        if diagnostico or not pclave:
            log(f"Columnas de población: {list(df.columns)}\n{df.head(5)}")
            for c in df.columns:
                if df[c].dtype == object and df[c].nunique() < 300:
                    log(f"  valores de {c}: {sorted(map(str, df[c].unique()))[:60]}")
        if not pclave:
            raise SystemExit("No encontré la columna de código de radio en la tabla de población.")
        df[pclave] = df[pclave].astype(str).str.strip()
        pob, meta_pob = elegir_poblacion(df, pclave, rcfg["poblacion_total_pais"])
    else:
        raise SystemExit("No hay fuente de población configurada.")

    gdf["poblacion"] = gdf[gclave].map(pob).fillna(0).astype("int64")
    cubiertos = int((gdf[gclave].isin(pob.index)).sum())
    log(f"Radios: {len(gdf):,}; con población: {cubiertos:,}; población total: {int(gdf['poblacion'].sum()):,}")
    os.makedirs(os.path.join(salida, "radios"), exist_ok=True)
    gdf["prov"] = gdf[gclave].str[:2]
    por_prov = {}
    for prov, sub in gdf.groupby("prov"):
        path = os.path.join(salida, "radios", f"{prov}.geojsonl")
        with open(path, "w", encoding="utf-8") as fh:
            for cod, p, geom in zip(sub[gclave], sub["poblacion"], sub.geometry):
                if geom is None or geom.is_empty:
                    continue
                fh.write(json.dumps({"type": "Feature", "properties": {"codigo": cod, "poblacion": int(p)},
                                     "geometry": geom.__geo_interface__}) + "\n")
        por_prov[prov] = {"radios": int(len(sub)), "poblacion": int(sub["poblacion"].sum())}
    return fuentes, {"radios": int(len(gdf)), "con_poblacion": cubiertos, "poblacion": meta_pob, "provincias": por_prov}


def osm(cfg, tmp, salida):
    if not shutil.which("osmium"):
        log("osmium no está instalado: se generan zonas sin barreras ni nombres de barrio.")
        return []
    pbf = os.path.join(tmp, "argentina.osm.pbf")
    url, sha, n = descargar([cfg["osm"]["url"]], pbf, minimo_bytes=10_000_000)
    for nombre, filtro, tipos in (("barreras", cfg["osm"]["barreras"], "linestring"),
                                  ("lugares", cfg["osm"]["lugares"], "point")):
        filt = os.path.join(tmp, f"{nombre}.osm.pbf")
        subprocess.run(["osmium", "tags-filter", pbf, *filtro.split(), "-o", filt, "--overwrite"], check=True)
        subprocess.run(["osmium", "export", filt, "-f", "geojsonseq", f"--geometry-types={tipos}",
                        "-o", os.path.join(salida, f"{nombre}.geojsonl"), "--overwrite"], check=True)
    return [{"id": "osm", "url": url, "sha256": sha, "bytes": n}]


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--config", default="config/fuentes.json")
    ap.add_argument("--salida", default="datos")
    ap.add_argument("--sin-osm", action="store_true")
    ap.add_argument("--diagnostico", action="store_true")
    a = ap.parse_args()
    with open(a.config, encoding="utf-8") as fh:
        cfg = json.load(fh)
    os.makedirs(a.salida, exist_ok=True)
    tmp = os.path.join(a.salida, "_tmp")
    os.makedirs(tmp, exist_ok=True)
    fuentes = []
    raw = os.path.join(tmp, "municipios_raw")
    url, sha, n = descargar(cfg["municipios"]["urls"], raw)
    fuentes.append({"id": "municipios", "nombre": cfg["municipios"]["nombre"], "licencia": cfg["municipios"]["licencia"],
                    "url": url, "sha256": sha, "bytes": n})
    nm = normalizar_municipios(raw, os.path.join(a.salida, "municipios.ndjson"))
    f_radios, resumen_radios = normalizar_radios(cfg, tmp, a.salida, a.diagnostico)
    for f in f_radios:
        f.update(nombre=cfg["radios"]["nombre"], licencia=cfg["radios"]["licencia"])
    fuentes += f_radios
    if not a.sin_osm:
        for f in osm(cfg, tmp, a.salida):
            f.update(nombre=cfg["osm"]["nombre"], licencia=cfg["osm"]["licencia"])
            fuentes.append(f)
    ahora = dt.datetime.now(dt.timezone.utc).isoformat(timespec="seconds")
    for f in fuentes:
        f["descargado_utc"] = ahora
    with open(os.path.join(a.salida, "fuentes.json"), "w", encoding="utf-8") as fh:
        json.dump(fuentes, fh, ensure_ascii=False, indent=1)
    with open(os.path.join(a.salida, "resumen_fuentes.json"), "w", encoding="utf-8") as fh:
        json.dump({"municipios": nm, "radios": resumen_radios}, fh, ensure_ascii=False, indent=1)
    shutil.rmtree(tmp, ignore_errors=True)
    log("Fuentes listas.")


if __name__ == "__main__":
    main()

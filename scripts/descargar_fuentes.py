"""Descarga y normaliza las fuentes públicas para el generador de zonas.

Corre en GitHub Actions (que tiene internet). Deja en `datos/`:
  municipios.ndjson            municipios de GeoRef con geometría
  radios/<prov>.geojsonl       radios censales 2022 con "codigo" y "poblacion", un archivo por provincia
  barreras.geojsonl            avenidas principales, vías de tren, ríos y canales (OSM)
  lugares.geojsonl             nombres de barrios y parajes (OSM)
  fuentes.json                 qué se descargó, de dónde, cuándo y con qué hash

Requiere: pyarrow, shapely, pandas (pip) y osmium-tool (apt) para OSM.
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


def _leer_geoparquet(path, clave_cands, pob_cands, diagnostico):
    """Lee GeoParquet con pyarrow + shapely (sin depender de la versión de metadatos que acepte geopandas)."""
    import pyarrow.parquet as pq
    import shapely
    pf = pq.ParquetFile(path)
    cols = pf.schema_arrow.names
    if diagnostico:
        log(f"Columnas de radios: {cols}")
    clave = next((c for c in clave_cands if c in cols), None)
    pobc = next((c for c in pob_cands if c in cols), None)
    geomc = "geometry" if "geometry" in cols else next((c for c in cols if c.lower() in ("geom", "wkb_geometry")), None)
    if not clave or not geomc:
        raise SystemExit(f"No encontré código de radio o geometría en {cols}")
    meta = (pf.schema_arrow.metadata or {}).get(b"geo")
    if meta:
        geo = json.loads(meta)
        crs = (geo.get("columns", {}).get(geomc, {}) or {}).get("crs")
        if crs not in (None, "OGC:CRS84") and "4326" not in json.dumps(crs):
            log(f"Aviso: CRS declarado {str(crs)[:200]}; se verifica por rango de coordenadas.")
    out = []
    for rg in range(pf.num_row_groups):
        t = pf.read_row_group(rg, columns=[c for c in (clave, pobc, geomc) if c])
        codes = t.column(clave).to_pylist()
        pops = t.column(pobc).to_pylist() if pobc else [None] * len(codes)
        geoms = shapely.from_wkb(t.column(geomc).to_pylist())
        for cod, pob, g in zip(codes, pops, geoms):
            out.append((str(cod).strip(), pob, g))
    return out, pobc


def normalizar_radios(cfg, tmp, salida, diagnostico=False):
    import shapely
    rcfg = cfg["radios"]
    fuentes = []
    gpath = os.path.join(tmp, "radios_geom" + os.path.splitext(rcfg["geometria"]["urls"][0])[1])
    url, sha, n = descargar(rcfg["geometria"]["urls"], gpath)
    fuentes.append({"id": "radios_geometria", "url": url, "sha256": sha, "bytes": n})
    rows, pobc = _leer_geoparquet(gpath, rcfg["geometria"]["clave"], rcfg["geometria"].get("poblacion", []), diagnostico)
    pob_map = None
    meta_pob = {"columna": pobc, "variable": None}
    if not pobc:
        import pandas as pd
        ppath = os.path.join(tmp, "radios_pob.parquet")
        url, sha, n = descargar(rcfg["poblacion"]["urls"], ppath)
        fuentes.append({"id": "radios_poblacion", "url": url, "sha256": sha, "bytes": n})
        df = pd.read_parquet(ppath)
        pclave = next((c for c in rcfg["poblacion"]["clave"] if c in df.columns), None)
        df[pclave] = df[pclave].astype(str).str.strip()
        serie, meta_pob = elegir_poblacion(df, pclave, rcfg["poblacion_total_pais"])
        pob_map = serie.to_dict()
    # Verificar coordenadas en grados (Argentina): si no, el archivo viene proyectado y no se puede usar así.
    xs = []
    for _, _, g in rows[:500]:
        if g is not None and not g.is_empty:
            b = g.bounds
            xs.append(b[0])
    if xs and not all(-80 <= x <= -20 for x in xs):
        raise SystemExit("Las coordenadas de los radios no están en grados WGS84; revisar la fuente.")
    os.makedirs(os.path.join(salida, "radios"), exist_ok=True)
    handles = {}
    resumen = {}
    total = 0
    con_pob = 0
    try:
        for cod, pob, g in rows:
            if g is None or g.is_empty:
                continue
            if pob_map is not None:
                pob = pob_map.get(cod)
            if pob is not None:
                con_pob += 1
            p = int(pob or 0)
            total += p
            prov = cod[:2]
            if prov not in handles:
                handles[prov] = open(os.path.join(salida, "radios", f"{prov}.geojsonl"), "w", encoding="utf-8")
                resumen[prov] = {"radios": 0, "poblacion": 0}
            if not g.is_valid:
                g = shapely.make_valid(g)
                if g.geom_type == "GeometryCollection":
                    polys = [x for x in g.geoms if x.geom_type in ("Polygon", "MultiPolygon")]
                    g = shapely.union_all(polys) if polys else g
            handles[prov].write(json.dumps({"type": "Feature", "properties": {"codigo": cod, "poblacion": p},
                                            "geometry": shapely.geometry.mapping(g)}) + "\n")
            resumen[prov]["radios"] += 1
            resumen[prov]["poblacion"] += p
    finally:
        for h in handles.values():
            h.close()
    meta_pob["total"] = total
    log(f"Radios: {len(rows):,}; con población: {con_pob:,}; población total: {total:,}")
    tot_pais = cfg["radios"].get("poblacion_total_pais")
    if tot_pais and not (0.9 * tot_pais <= total <= 1.1 * tot_pais):
        log(f"AVISO: la población total ({total:,}) se aleja más de 10% del Censo 2022 ({tot_pais:,}).")
    return fuentes, {"radios": len(rows), "con_poblacion": con_pob, "poblacion": meta_pob, "provincias": resumen}


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

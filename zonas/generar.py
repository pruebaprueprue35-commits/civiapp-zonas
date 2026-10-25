"""Generación de zonas por provincia y armado del paquete `civiapp-zonas/1`."""
from __future__ import annotations

import csv
import gzip
import hashlib
import io
import json
import math
import os
from typing import Iterable

from . import __version__, geo, grafo, nombres, regiones
from .entrada import iter_features, pick

SCHEMA = "civiapp-zonas/1"
PART_MAX_BYTES = 4 * 1024 * 1024   # tamaño máximo sin comprimir de cada parte
PART_MAX_ZONAS = 400

CLAVES_MUNICIPIO = {
    "id": ["id", "georef_id", "codigo", "in1", "gid"],
    "nombre": ["nombre", "nam", "name", "fna"],
    "provincia_id": ["provincia_id", "provincia.id", "provincia_georef"],
    "provincia_nombre": ["provincia_nombre", "provincia.nombre"],
}
CLAVES_RADIO = {
    "codigo": ["codigo", "cod_2022", "link", "cod_radio", "id_geo", "id"],
    "poblacion": ["poblacion", "personas", "pob_total", "total_pob", "pob", "total_personas", "population"],
}


class Municipio:
    __slots__ = ("id", "nombre", "provincia_id", "provincia_nombre", "tipo_unidad", "polys", "bbox", "area")

    def __init__(self, f):
        p = f.get("properties") or {}
        self.id = str(pick(p, CLAVES_MUNICIPIO["id"]) or "").strip()
        self.nombre = str(pick(p, CLAVES_MUNICIPIO["nombre"]) or "").strip()
        self.provincia_id = str(pick(p, CLAVES_MUNICIPIO["provincia_id"]) or self.id[:2]).strip().zfill(2)
        self.provincia_nombre = str(pick(p, CLAVES_MUNICIPIO["provincia_nombre"]) or "").strip()
        self.tipo_unidad = str(p.get("tipo_unidad") or "municipio").strip() or "municipio"
        self.polys = geo.polygons_of(f.get("geometry") or {})
        self.bbox = geo.bbox(self.polys)
        self.area = geo.total_area_deg(self.polys)


def leer_municipios(path: str, provincias: set | None = None) -> list:
    out = []
    for f in iter_features(path):
        m = Municipio(f)
        if not m.id or not m.polys:
            continue
        if provincias and m.provincia_id not in provincias:
            continue
        out.append(m)
    out.sort(key=lambda m: m.id)
    return out


class _BBoxGrid:
    def __init__(self, cell=0.1):
        self.cell = cell
        self.cells: dict = {}

    def add(self, b, item):
        c = self.cell
        for gx in range(math.floor(b[0] / c), math.floor(b[2] / c) + 1):
            for gy in range(math.floor(b[1] / c), math.floor(b[3] / c) + 1):
                self.cells.setdefault((gx, gy), []).append(item)

    def at(self, x, y):
        return self.cells.get((math.floor(x / self.cell), math.floor(y / self.cell)), [])

    def in_bbox(self, b):
        c = self.cell
        seen = []
        ids = set()
        for gx in range(math.floor(b[0] / c), math.floor(b[2] / c) + 1):
            for gy in range(math.floor(b[1] / c), math.floor(b[3] / c) + 1):
                for it in self.cells.get((gx, gy), ()):
                    if id(it) not in ids:
                        ids.add(id(it))
                        seen.append(it)
        return seen


def asignar_radios(municipios: list, radios_path: str, prov_id: str | None = None):
    """Asigna cada radio al municipio que contiene su punto interior. Devuelve
    ({municipio_id: [Radio]}, [radios sin municipio], [radios en varios municipios])."""
    grid = _BBoxGrid()
    for m in municipios:
        grid.add(m.bbox, m)
    por_muni: dict = {}
    sin_muni = []
    ambiguos = []
    for f in iter_features(radios_path):
        p = f.get("properties") or {}
        codigo = str(pick(p, CLAVES_RADIO["codigo"]) or "").strip()
        polys = geo.polygons_of(f.get("geometry") or {})
        if not codigo or not polys:
            continue
        if prov_id and not codigo.startswith(prov_id):
            continue
        pob = pick(p, CLAVES_RADIO["poblacion"])
        try:
            pob = int(float(pob)) if pob is not None else 0
        except (TypeError, ValueError):
            pob = 0
        r = grafo.Radio(codigo, pob, polys)
        x, y = r.point
        hits = [m for m in grid.at(x, y)
                if m.bbox[0] <= x <= m.bbox[2] and m.bbox[1] <= y <= m.bbox[3] and geo.point_in_polys(x, y, m.polys)]
        if not hits:
            sin_muni.append(r)
            continue
        if len(hits) > 1:
            hits.sort(key=lambda m: (m.area, m.id))  # el más chico: ejidos dentro de ejidos
            ambiguos.append((r.codigo, [m.id for m in hits]))
        por_muni.setdefault(hits[0].id, []).append(r)
    for lst in por_muni.values():
        lst.sort(key=lambda r: r.codigo)
    return por_muni, sin_muni, ambiguos


def leer_lineas(path: str | None, bbox: tuple | None = None) -> list:
    """Barreras: lista de polilíneas en grados, filtradas por caja."""
    if not path or not os.path.exists(path):
        return []
    out = []
    for f in iter_features(path):
        g = f.get("geometry") or {}
        t = g.get("type")
        lines = [g.get("coordinates")] if t == "LineString" else (g.get("coordinates") if t == "MultiLineString" else [])
        for ln in lines or []:
            pts = [(float(a), float(b)) for a, b, *_ in ln]
            if len(pts) < 2:
                continue
            if bbox:
                xs = [p[0] for p in pts]
                ys = [p[1] for p in pts]
                if max(xs) < bbox[0] or min(xs) > bbox[2] or max(ys) < bbox[1] or min(ys) > bbox[3]:
                    continue
            out.append(pts)
    return out


def leer_lugares(path: str | None, bbox: tuple | None = None) -> list:
    if not path or not os.path.exists(path):
        return []
    out = []
    for f in iter_features(path):
        g = f.get("geometry") or {}
        if g.get("type") != "Point":
            continue
        p = f.get("properties") or {}
        name = (p.get("name") or p.get("nombre") or "").strip()
        place = (p.get("place") or p.get("tipo") or "").strip()
        if not name or place not in nombres.PRIORIDAD_LUGAR:
            continue
        x, y = float(g["coordinates"][0]), float(g["coordinates"][1])
        if bbox and not (bbox[0] <= x <= bbox[2] and bbox[1] <= y <= bbox[3]):
            continue
        out.append((x, y, name[:60], nombres.PRIORIDAD_LUGAR[place]))
    return out


def _uid(muni_id: str, codigos: Iterable) -> str:
    h = hashlib.sha1((muni_id + ":" + ",".join(sorted(codigos))).encode("utf-8")).hexdigest()
    return h[:16]


def zonas_de_municipio(m: Municipio, radios: list, barreras: list, lugares: list, params: dict) -> tuple:
    """Devuelve (registro_municipio, [registros_zona])."""
    flags_muni = []
    if not radios:
        # Sin radios censales: el municipio entero es una zona, sin población conocida.
        c = geo.centroid(m.polys)
        proj = geo.LocalProjection(*c)
        cx, cy = proj.fwd(*c)
        lz = [(pri, math.hypot(proj.fwd(x, y)[0] - cx, proj.fwd(x, y)[1] - cy), name) for (x, y, name, pri) in lugares
              if m.bbox[0] <= x <= m.bbox[2] and m.bbox[1] <= y <= m.bbox[3] and geo.point_in_polys(x, y, m.polys)]
        zona = {
            "tipo": "zona", "uid": _uid(m.id, ["municipio"]), "municipio_georef": m.id,
            "provincia_georef": m.provincia_id, "codigo": "Z01", "nombre": "Zona única",
            "direccion": "Municipio", "lugar": "", "barrios": nombres.barrios(lz), "poblacion": None, "radios": [], "radios_count": 0,
            "area_km2": round(geo.area_m2(m.polys, proj) / 1e6, 3),
            "centroide": [round(c[0], 6), round(c[1], 6)],
            "bbox": [round(v, 6) for v in m.bbox],
            "calidad": {"compacidad": None, "contigua": True, "disuelta": True},
            "flags": ["sin_radios"], "geometry": geo.to_geojson(m.polys),
        }
        return _muni_record(m, [zona], 0, None, ["sin_radios"]), [zona]

    tot = sum(r.poblacion for r in radios)
    if tot > 0:
        c0 = (sum(r.centroid[0] * r.poblacion for r in radios) / tot, sum(r.centroid[1] * r.poblacion for r in radios) / tot)
    else:
        c0 = (sum(r.centroid[0] for r in radios) / len(radios), sum(r.centroid[1] for r in radios) / len(radios))
    proj = geo.LocalProjection(*c0)
    bgrid = None
    if barreras:
        bgrid = geo.SegmentGrid(cell=200.0)
        for ln in barreras:
            pts = [proj.fwd(*p) for p in ln]
            for i in range(len(pts) - 1):
                bgrid.add(pts[i], pts[i + 1])
    graph = grafo.build_graph(radios, proj, bgrid, params.get("radio_barrera_m", 25.0), params.get("ajuste_m", 2.0))
    grupos = regiones.regionalize(radios, graph, params)

    zonas = []
    for g in grupos:
        miembros = [radios[i] for i in g]
        codigos = [r.codigo for r in miembros]
        flags = []
        disuelta = geo.dissolve([r.polys for r in miembros])
        suma = sum(geo.total_area_deg(r.polys) for r in miembros)
        if disuelta is None or (suma > 0 and abs(geo.total_area_deg(disuelta) - suma) / suma > 0.005):
            polys = [rings for r in miembros for rings in r.polys]
            disuelta_ok = False
            flags.append("geometria_sin_disolver")
        else:
            polys = disuelta
            disuelta_ok = True
        area = geo.area_m2(polys, proj)
        per = geo.perimeter_m(polys, proj) if disuelta_ok else 0.0
        compac = round(4 * math.pi * area / (per * per), 3) if per > 0 else None
        contigua = regiones.is_contiguous(g, graph)
        pob = sum(r.poblacion for r in miembros)
        if not contigua:
            flags.append("no_contigua")
        if compac is not None and compac < params.get("compacidad_min", 0.12):
            flags.append("poco_compacta")
        if len(grupos) > 1 and pob < params.get("poblacion_min", 6000) * 0.66:
            flags.append("poblacion_baja")
        if pob > params.get("poblacion_objetivo", 9000) * 2.5 and len(grupos) < params.get("max_zonas", 40):
            flags.append("poblacion_alta")
        gs = set(g)
        cruces = sum(e["barrier"] for i in g for j, e in graph[i].items() if j in gs) / 2.0
        if pob > 0:
            cx = sum(r.xy[0] * r.poblacion for r in miembros) / pob
            cy = sum(r.xy[1] * r.poblacion for r in miembros) / pob
        else:
            cx = sum(r.xy[0] for r in miembros) / len(miembros)
            cy = sum(r.xy[1] for r in miembros) / len(miembros)
        cen = geo.centroid(polys)
        b = geo.bbox(polys)
        lugares_z = []
        for (x, y, name, pri) in lugares:
            if b[0] <= x <= b[2] and b[1] <= y <= b[3] and geo.point_in_polys(x, y, polys):
                px, py = proj.fwd(x, y)
                lugares_z.append((pri, math.hypot(px - cx, py - cy), name))
        zonas.append({
            "tipo": "zona", "uid": _uid(m.id, codigos), "municipio_georef": m.id,
            "provincia_georef": m.provincia_id, "poblacion": pob, "radios": codigos,
            "radios_count": len(codigos), "area_km2": round(area / 1e6, 3),
            "centroide": [round(cen[0], 6), round(cen[1], 6)], "bbox": [round(v, 6) for v in b],
            "barrera_cruzada_m": round(cruces, 1),
            "calidad": {"compacidad": compac, "contigua": contigua, "disuelta": disuelta_ok},
            "flags": flags, "geometry": geo.to_geojson(polys),
            "xy": (cx, cy), "lugares": lugares_z, "barrios": nombres.barrios(lugares_z),
        })
    nombres.nombrar(zonas)
    for z in zonas:
        z.pop("xy", None)
        z.pop("lugares", None)
    zonas.sort(key=lambda z: z["codigo"])
    # Orden de claves estable para hashes reproducibles.
    ordered = []
    for z in zonas:
        ordered.append({k: z[k] for k in (
            "tipo", "uid", "municipio_georef", "provincia_georef", "codigo", "nombre", "direccion", "lugar", "barrios",
            "poblacion", "radios_count", "area_km2", "centroide", "bbox", "calidad", "flags",
            "barrera_cruzada_m", "radios", "geometry") if k in z})
    if any(z["flags"] for z in ordered):
        flags_muni.append("zonas_para_revisar")
    return _muni_record(m, ordered, len(radios), tot, flags_muni), ordered


def _muni_record(m, zonas, n_radios, pob, flags):
    return {
        "tipo": "municipio", "municipio_georef": m.id, "nombre": m.nombre, "tipo_unidad": m.tipo_unidad,
        "provincia_georef": m.provincia_id, "provincia_nombre": m.provincia_nombre,
        "poblacion": pob, "radios_count": n_radios, "zonas_count": len(zonas),
        "zonas_para_revisar": sum(1 for z in zonas if z["flags"]),
        "bbox": [round(v, 6) for v in m.bbox], "flags": flags,
    }


def _dump(obj) -> str:
    return json.dumps(obj, ensure_ascii=False, separators=(",", ":"))


def _gz_bytes(text: str) -> bytes:
    buf = io.BytesIO()
    with gzip.GzipFile(fileobj=buf, mode="wb", mtime=0, compresslevel=9) as gz:
        gz.write(text.encode("utf-8"))
    return buf.getvalue()


def escribir_provincia(out_dir: str, prov_id: str, prov_nombre: str, bloques: list) -> dict:
    """`bloques`: lista de (registro_municipio, [zonas]). Escribe partes NDJSON.gz alineadas a municipios."""
    os.makedirs(out_dir, exist_ok=True)
    parts = []
    buf = []
    size = 0
    nz = 0

    def flush():
        nonlocal buf, size, nz
        if not buf:
            return
        name = f"prov-{prov_id}-{len(parts) + 1:03d}.ndjson.gz"
        data = _gz_bytes("".join(buf))
        with open(os.path.join(out_dir, name), "wb") as fh:
            fh.write(data)
        parts.append({"archivo": name, "sha256": hashlib.sha256(data).hexdigest(), "bytes": len(data),
                      "lineas": len(buf)})
        buf, size, nz = [], 0, 0

    for muni, zonas in bloques:
        lines = [_dump(muni) + "\n"] + [_dump(z) + "\n" for z in zonas]
        block = sum(len(x.encode("utf-8")) for x in lines)
        if buf and (size + block > PART_MAX_BYTES or nz + len(zonas) > PART_MAX_ZONAS):
            flush()
        buf.extend(lines)
        size += block
        nz += len(zonas)
    flush()
    return {
        "georef_id": prov_id, "nombre": prov_nombre,
        "municipios": len(bloques),
        "municipios_con_radios": sum(1 for m, _ in bloques if m["radios_count"] > 0),
        "zonas": sum(m["zonas_count"] for m, _ in bloques),
        "zonas_para_revisar": sum(m["zonas_para_revisar"] for m, _ in bloques),
        "radios": sum(m["radios_count"] for m, _ in bloques),
        "poblacion": sum(m["poblacion"] or 0 for m, _ in bloques),
        "partes": parts,
    }


def generar(municipios_path: str, radios_dir: str, out_dir: str, version: str, params: dict,
            provincias: set | None = None, barreras_path: str | None = None, lugares_path: str | None = None,
            fuentes: list | None = None, log=print, base_url: str = "") -> dict:
    munis = leer_municipios(municipios_path, provincias)
    por_prov: dict = {}
    for m in munis:
        por_prov.setdefault(m.provincia_id, []).append(m)
    resumen_provs = []
    cobertura_rows = []
    sin_muni_total = 0
    for prov_id in sorted(por_prov):
        lista = por_prov[prov_id]
        prov_nombre = next((m.provincia_nombre for m in lista if m.provincia_nombre), prov_id)
        radios_path = _radios_file(radios_dir, prov_id)
        if radios_path:
            por_muni, sin_muni, ambiguos = asignar_radios(lista, radios_path, prov_id)
        else:
            por_muni, sin_muni, ambiguos = {}, [], []
        sin_muni_total += len(sin_muni)
        pb = _union_bbox([m.bbox for m in lista])
        barreras = leer_lineas(barreras_path, pb)
        lugares = leer_lugares(lugares_path, pb)
        bloques = []
        for m in lista:
            rs = por_muni.get(m.id, [])
            mb = m.bbox
            bm = [ln for ln in barreras if _line_hits_bbox(ln, mb)]
            lm = [p for p in lugares if mb[0] <= p[0] <= mb[2] and mb[1] <= p[1] <= mb[3]]
            muni_rec, zonas = zonas_de_municipio(m, rs, bm, lm, params)
            bloques.append((muni_rec, zonas))
            cobertura_rows.append([prov_id, prov_nombre, m.id, m.nombre, muni_rec["radios_count"],
                                   muni_rec["poblacion"] if muni_rec["poblacion"] is not None else "",
                                   muni_rec["zonas_count"], muni_rec["zonas_para_revisar"], " ".join(muni_rec["flags"])])
        info = escribir_provincia(out_dir, prov_id, prov_nombre, bloques)
        info["radios_sin_municipio"] = len(sin_muni)
        info["poblacion_sin_municipio"] = sum(r.poblacion for r in sin_muni)
        info["radios_en_varios_municipios"] = len(ambiguos)
        resumen_provs.append(info)
        log(f"[{prov_id}] {prov_nombre}: {info['municipios']} municipios, {info['zonas']} zonas, "
            f"{info['radios']} radios, {len(sin_muni)} radios fuera de municipios")
    cob_name = "cobertura.csv"
    with open(os.path.join(out_dir, cob_name), "w", newline="", encoding="utf-8") as fh:
        w = csv.writer(fh)
        w.writerow(["provincia_id", "provincia", "municipio_id", "municipio", "radios", "poblacion",
                    "zonas", "zonas_para_revisar", "marcas"])
        w.writerows(cobertura_rows)
    manifest = {
        "schema": SCHEMA,
        "version": version,
        "base_url": base_url,
        "generado_utc": os.environ.get("ZONAS_FECHA_UTC", ""),
        "generador": {"nombre": "civiapp-zonas", "version": __version__, "commit": os.environ.get("GITHUB_SHA", "")},
        "parametros": params,
        "fuentes": fuentes or [],
        "atribucion": "Zonas CiviApp elaboradas con radios censales INDEC Censo 2022 (cartografía Rodriguez y de Grande, CONICET), "
                      "límites de GeoRef (datos.gob.ar) y datos © colaboradores de OpenStreetMap (ODbL).",
        "provincias": resumen_provs,
        "totales": {
            "provincias": len(resumen_provs),
            "municipios": sum(p["municipios"] for p in resumen_provs),
            "zonas": sum(p["zonas"] for p in resumen_provs),
            "zonas_para_revisar": sum(p["zonas_para_revisar"] for p in resumen_provs),
            "radios": sum(p["radios"] for p in resumen_provs),
            "poblacion": sum(p["poblacion"] for p in resumen_provs),
            "radios_sin_municipio": sin_muni_total,
        },
        "cobertura": {"archivo": cob_name, "sha256": _sha_file(os.path.join(out_dir, cob_name))},
    }
    with open(os.path.join(out_dir, "manifest.json"), "w", encoding="utf-8") as fh:
        json.dump(manifest, fh, ensure_ascii=False, indent=1)
    return manifest


def _radios_file(radios_dir: str, prov_id: str):
    if not radios_dir:
        return None
    for name in (f"{prov_id}.geojsonl", f"{prov_id}.geojsonl.gz", f"{prov_id}.geojson", f"{prov_id}.geojson.gz",
                 f"{prov_id}.ndjson", f"{prov_id}.ndjson.gz"):
        p = os.path.join(radios_dir, name)
        if os.path.exists(p):
            return p
    return None


def _union_bbox(bs):
    return (min(b[0] for b in bs), min(b[1] for b in bs), max(b[2] for b in bs), max(b[3] for b in bs))


def _line_hits_bbox(ln, b):
    xs = [p[0] for p in ln]
    ys = [p[1] for p in ln]
    return not (max(xs) < b[0] or min(xs) > b[2] or max(ys) < b[1] or min(ys) > b[3])


def _sha_file(path):
    h = hashlib.sha256()
    with open(path, "rb") as fh:
        for chunk in iter(lambda: fh.read(1 << 20), b""):
            h.update(chunk)
    return h.hexdigest()

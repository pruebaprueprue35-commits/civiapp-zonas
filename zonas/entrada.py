"""Lectura de insumos normalizados (GeoJSON, GeoJSONSeq o NDJSON, opcionalmente .gz)."""
from __future__ import annotations

import gzip
import json
from typing import Iterator


def _open(path: str):
    if path.endswith(".gz"):
        return gzip.open(path, "rt", encoding="utf-8")
    return open(path, "r", encoding="utf-8")


def iter_features(path: str) -> Iterator[dict]:
    """Itera features. Una feature por línea se lee en streaming; un GeoJSON clásico se carga entero."""
    with _open(path) as fh:
        first = ""
        for line in fh:
            first = line.strip().lstrip("\x1e")
            if first:
                break
        if not first:
            return
        try:
            obj = json.loads(first)
        except json.JSONDecodeError:
            obj = None
        if obj is None:
            data = json.loads(first + "\n" + fh.read())
            yield from _features_of(data)
            return
        yield from _features_of(obj)
        for line in fh:
            line = line.strip().lstrip("\x1e")
            if line:
                yield from _features_of(json.loads(line))


def _features_of(obj) -> Iterator[dict]:
    if not isinstance(obj, dict):
        return
    t = obj.get("type")
    if t == "FeatureCollection":
        for f in obj.get("features") or []:
            yield from _features_of(f)
    elif t == "Feature":
        yield obj
    elif "geometria" in obj or "geometry" in obj:
        # NDJSON de GeoRef: la entidad trae "geometria" junto a sus atributos.
        geom = obj.get("geometry") or obj.get("geometria")
        props = {k: v for k, v in obj.items() if k not in ("geometry", "geometria")}
        yield {"type": "Feature", "properties": props, "geometry": geom}


def pick(props: dict, keys) -> object:
    """Primer valor presente entre varias claves posibles; admite rutas con punto ("provincia.id")."""
    for k in keys:
        cur = props
        ok = True
        for part in k.split("."):
            if isinstance(cur, dict) and part in cur and cur[part] not in (None, ""):
                cur = cur[part]
            else:
                ok = False
                break
        if ok:
            return cur
    return None

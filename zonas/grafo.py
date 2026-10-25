"""Grafo de vecindad entre radios censales de un municipio.

Dos radios son vecinos si comparten un tramo de borde (contigüidad "torre") o, como respaldo,
solo un vértice (esquina) o si quedan a menos de `snap_m` metros (cartografía con pequeñas
separaciones). Cada vecindad guarda cuánto borde comparten y qué parte de ese borde va sobre
una barrera (avenida principal, vía de tren, río), para que las zonas no la crucen.
"""
from __future__ import annotations

import math

from . import geo


class Radio:
    __slots__ = ("codigo", "poblacion", "polys", "centroid", "point", "xy", "area_m2", "bbox")

    def __init__(self, codigo: str, poblacion: int, polys: list):
        self.codigo = codigo
        self.poblacion = max(int(poblacion or 0), 0)
        self.polys = polys
        self.centroid = geo.centroid(polys)
        self.point = geo.interior_point(polys)
        self.bbox = geo.bbox(polys)
        self.xy = (0.0, 0.0)
        self.area_m2 = 0.0


def build_graph(radios: list, proj: geo.LocalProjection, barreras: geo.SegmentGrid | None,
                barrier_radius_m: float = 25.0, snap_m: float = 2.0) -> dict:
    """Devuelve {i: {j: {"len": m, "barrier": m}}} con índices de la lista `radios`."""
    n = len(radios)
    for r in radios:
        r.xy = proj.fwd(*r.centroid)
        r.area_m2 = geo.area_m2(r.polys, proj)

    # Aristas etiquetadas por radio, con nodado para uniones en T.
    tagged = []
    for idx, r in enumerate(radios):
        for a, b in geo.iter_edges(r.polys):
            ka, kb = geo.rkey(a), geo.rkey(b)
            if ka != kb:
                tagged.append((ka, kb, idx))
    split = _node_tagged(tagged)

    edge_owners: dict = {}
    vertex_owners: dict = {}
    for a, b, idx in split:
        key = (a, b) if a <= b else (b, a)
        edge_owners.setdefault(key, set()).add(idx)
        vertex_owners.setdefault(a, set()).add(idx)
        vertex_owners.setdefault(b, set()).add(idx)

    graph: dict = {i: {} for i in range(n)}

    def link(i, j, length, barrier):
        e = graph[i].get(j)
        if e is None:
            e = {"len": 0.0, "barrier": 0.0}
            graph[i][j] = e
            graph[j][i] = e
        e["len"] += length
        e["barrier"] += barrier

    for (a, b), owners in edge_owners.items():
        if len(owners) < 2:
            continue
        pa, pb = proj.fwd(*a), proj.fwd(*b)
        length = math.dist(pa, pb)
        mid = ((pa[0] + pb[0]) / 2, (pa[1] + pb[1]) / 2)
        on_barrier = bool(barreras and barreras.near(mid, barrier_radius_m))
        owners = sorted(owners)
        for x in range(len(owners)):
            for y in range(x + 1, len(owners)):
                link(owners[x], owners[y], length, length if on_barrier else 0.0)

    # Esquinas: comparten solo un vértice.
    for v, owners in vertex_owners.items():
        if len(owners) < 2:
            continue
        owners = sorted(owners)
        for x in range(len(owners)):
            for y in range(x + 1, len(owners)):
                if owners[y] not in graph[owners[x]]:
                    link(owners[x], owners[y], 0.0, 0.0)

    # Respaldo para radios aislados por pequeñas separaciones de la cartografía.
    isolated = [i for i in range(n) if not graph[i]]
    if isolated and n > 1:
        grid = geo.SegmentGrid(cell=max(snap_m * 20, 50.0))
        seg_owner = []
        for idx, r in enumerate(radios):
            for a, b in geo.iter_edges(r.polys):
                grid.add(proj.fwd(*a), proj.fwd(*b))
                seg_owner.append(idx)
        for i in isolated:
            near = set()
            for a, _ in geo.iter_edges(radios[i].polys):
                p = proj.fwd(*a)
                c = grid.cell
                gx, gy = int(math.floor(p[0] / c)), int(math.floor(p[1] / c))
                for dx in (-1, 0, 1):
                    for dy in (-1, 0, 1):
                        for s in grid.cells.get((gx + dx, gy + dy), ()):
                            j = seg_owner[s]
                            if j == i:
                                continue
                            (ax, ay), (bx, by) = grid.segs[s]
                            if geo.seg_point_dist(p[0], p[1], ax, ay, bx, by) <= snap_m:
                                near.add(j)
            for j in sorted(near):
                link(i, j, 0.0, 0.0)
    return graph


def _node_tagged(tagged):
    """Parte aristas en vértices ajenos que caen sobre ellas, conservando el radio dueño."""
    vertices = set()
    for a, b, _ in tagged:
        vertices.add(a)
        vertices.add(b)
    cell = 5e-5
    grid: dict = {}
    for v in vertices:
        grid.setdefault((math.floor(v[0] / cell), math.floor(v[1] / cell)), []).append(v)
    out = []
    for a, b, idx in tagged:
        x0, x1 = sorted((a[0], b[0]))
        y0, y1 = sorted((a[1], b[1]))
        dx, dy = b[0] - a[0], b[1] - a[1]
        d2 = dx * dx + dy * dy
        cands = []
        for gx in range(math.floor(x0 / cell), math.floor(x1 / cell) + 1):
            for gy in range(math.floor(y0 / cell), math.floor(y1 / cell) + 1):
                for v in grid.get((gx, gy), ()):
                    if v == a or v == b:
                        continue
                    if geo.seg_point_dist(v[0], v[1], a[0], a[1], b[0], b[1]) <= 1e-9:
                        t = ((v[0] - a[0]) * dx + (v[1] - a[1]) * dy) / d2
                        if 0 < t < 1:
                            cands.append((t, v))
        if not cands:
            out.append((a, b, idx))
            continue
        cands.sort()
        prev = a
        for _, v in cands:
            if v != prev:
                out.append((prev, v, idx))
                prev = v
        if prev != b:
            out.append((prev, b, idx))
    return out


def components(nodes, graph) -> list:
    nodes = set(nodes)
    seen = set()
    comps = []
    for s in sorted(nodes):
        if s in seen:
            continue
        stack = [s]
        seen.add(s)
        comp = []
        while stack:
            u = stack.pop()
            comp.append(u)
            for v in graph[u]:
                if v in nodes and v not in seen:
                    seen.add(v)
                    stack.append(v)
        comps.append(sorted(comp))
    return comps

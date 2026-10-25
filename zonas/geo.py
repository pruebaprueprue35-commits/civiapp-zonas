"""Geometría plana mínima para el generador de zonas.

Sin dependencias externas: funciona igual en la tablet, en este entorno y en GitHub Actions.
Coordenadas de entrada en grados WGS84 (lon, lat), como GeoJSON. Para medir (áreas,
distancias, compacidad) se proyecta a metros con una proyección equirectangular local,
suficiente a escala de un municipio.
"""
from __future__ import annotations

import math
from typing import Iterable

COORD_DECIMALS = 7  # ~1 cm: clave para reconocer vértices compartidos entre radios
OUT_DECIMALS = 6    # ~10 cm en la salida
M_PER_DEG_LAT = 110_540.0
M_PER_DEG_LON_EQ = 111_320.0


def rkey(pt) -> tuple:
    return (round(pt[0], COORD_DECIMALS), round(pt[1], COORD_DECIMALS))


def polygons_of(geom: dict) -> list:
    """Devuelve una lista de polígonos; cada polígono es una lista de anillos [(x, y), ...] cerrados."""
    if not geom:
        return []
    t = geom.get("type")
    c = geom.get("coordinates")
    if t == "Polygon":
        polys = [c]
    elif t == "MultiPolygon":
        polys = c
    else:
        return []
    out = []
    for poly in polys:
        rings = []
        for ring in poly:
            pts = [(float(p[0]), float(p[1])) for p in ring]
            if len(pts) >= 3 and pts[0] != pts[-1]:
                pts.append(pts[0])
            if len(pts) >= 4:
                rings.append(pts)
        if rings:
            out.append(rings)
    return out


def ring_signed_area(ring) -> float:
    """Área con signo (positiva = antihoraria) en unidades de las coordenadas."""
    s = 0.0
    for i in range(len(ring) - 1):
        x1, y1 = ring[i]
        x2, y2 = ring[i + 1]
        s += x1 * y2 - x2 * y1
    return s / 2.0


def orient(ring, ccw: bool):
    a = ring_signed_area(ring)
    if (a > 0) != ccw and a != 0:
        return list(reversed(ring))
    return ring


def bbox(polys) -> tuple:
    xs, ys = [], []
    for rings in polys:
        for x, y in rings[0]:
            xs.append(x)
            ys.append(y)
    if not xs:
        return (0.0, 0.0, 0.0, 0.0)
    return (min(xs), min(ys), max(xs), max(ys))


class LocalProjection:
    """Equirectangular local alrededor de (lon0, lat0), en metros."""

    def __init__(self, lon0: float, lat0: float):
        self.lon0 = lon0
        self.lat0 = lat0
        self.kx = M_PER_DEG_LON_EQ * math.cos(math.radians(lat0))
        self.ky = M_PER_DEG_LAT

    def fwd(self, lon: float, lat: float) -> tuple:
        return ((lon - self.lon0) * self.kx, (lat - self.lat0) * self.ky)

    def inv(self, x: float, y: float) -> tuple:
        return (x / self.kx + self.lon0, y / self.ky + self.lat0)

    def ring(self, ring):
        return [self.fwd(x, y) for x, y in ring]


def area_m2(polys, proj: LocalProjection) -> float:
    total = 0.0
    for rings in polys:
        for i, ring in enumerate(rings):
            a = abs(ring_signed_area(proj.ring(ring)))
            total += a if i == 0 else -a
    return max(total, 0.0)


def perimeter_m(polys, proj: LocalProjection) -> float:
    total = 0.0
    for rings in polys:
        for ring in rings:
            pr = proj.ring(ring)
            for i in range(len(pr) - 1):
                total += math.dist(pr[i], pr[i + 1])
    return total


def centroid(polys) -> tuple:
    """Centroide de área en grados (aprox. válida a escala local)."""
    ax = ay = at = 0.0
    for rings in polys:
        for i, ring in enumerate(rings):
            a = ring_signed_area(ring)
            if a == 0:
                continue
            cx = cy = 0.0
            for j in range(len(ring) - 1):
                x1, y1 = ring[j]
                x2, y2 = ring[j + 1]
                f = x1 * y2 - x2 * y1
                cx += (x1 + x2) * f
                cy += (y1 + y2) * f
            cx /= 6.0 * a
            cy /= 6.0 * a
            w = abs(a) if i == 0 else -abs(a)
            ax += cx * w
            ay += cy * w
            at += w
    if at == 0:
        b = bbox(polys)
        return ((b[0] + b[2]) / 2, (b[1] + b[3]) / 2)
    return (ax / at, ay / at)


def point_in_rings(x: float, y: float, rings) -> bool:
    """Par-impar sobre todos los anillos: correcto con huecos sin importar la orientación."""
    inside = False
    for ring in rings:
        n = len(ring)
        j = n - 1
        for i in range(n):
            xi, yi = ring[i]
            xj, yj = ring[j]
            if (yi > y) != (yj > y):
                xint = (xj - xi) * (y - yi) / (yj - yi) + xi
                if x < xint:
                    inside = not inside
            j = i
    return inside


def point_in_polys(x: float, y: float, polys) -> bool:
    return any(point_in_rings(x, y, rings) for rings in polys)


def interior_point(polys) -> tuple:
    """Un punto garantizado dentro del polígono (centroide si cae adentro; si no, barrido horizontal)."""
    c = centroid(polys)
    if point_in_polys(c[0], c[1], polys):
        return c
    # Barrido: línea horizontal por varias alturas; tomar el punto medio del tramo interior más largo.
    b = bbox(polys)
    best = None
    best_len = -1.0
    for k in range(1, 20):
        y = b[1] + (b[3] - b[1]) * k / 20.0
        xs = []
        for rings in polys:
            for ring in rings:
                for i in range(len(ring) - 1):
                    (x1, y1), (x2, y2) = ring[i], ring[i + 1]
                    if (y1 > y) != (y2 > y):
                        xs.append(x1 + (y - y1) * (x2 - x1) / (y2 - y1))
        xs.sort()
        for i in range(0, len(xs) - 1, 2):
            if xs[i + 1] - xs[i] > best_len:
                best_len = xs[i + 1] - xs[i]
                best = ((xs[i] + xs[i + 1]) / 2.0, y)
    return best if best else c


def seg_point_dist(px, py, ax, ay, bx, by) -> float:
    dx, dy = bx - ax, by - ay
    if dx == 0 and dy == 0:
        return math.hypot(px - ax, py - ay)
    t = ((px - ax) * dx + (py - ay) * dy) / (dx * dx + dy * dy)
    t = max(0.0, min(1.0, t))
    return math.hypot(px - (ax + t * dx), py - (ay + t * dy))


class SegmentGrid:
    """Índice espacial simple de segmentos (en metros) para consultas de cercanía."""

    def __init__(self, cell: float = 100.0):
        self.cell = cell
        self.cells: dict = {}
        self.segs: list = []

    def add(self, a, b):
        idx = len(self.segs)
        self.segs.append((a, b))
        x0, x1 = sorted((a[0], b[0]))
        y0, y1 = sorted((a[1], b[1]))
        c = self.cell
        for gx in range(int(math.floor(x0 / c)), int(math.floor(x1 / c)) + 1):
            for gy in range(int(math.floor(y0 / c)), int(math.floor(y1 / c)) + 1):
                self.cells.setdefault((gx, gy), []).append(idx)

    def near(self, p, radius: float) -> bool:
        c = self.cell
        gx0 = int(math.floor((p[0] - radius) / c))
        gx1 = int(math.floor((p[0] + radius) / c))
        gy0 = int(math.floor((p[1] - radius) / c))
        gy1 = int(math.floor((p[1] + radius) / c))
        seen = set()
        for gx in range(gx0, gx1 + 1):
            for gy in range(gy0, gy1 + 1):
                for idx in self.cells.get((gx, gy), ()):
                    if idx in seen:
                        continue
                    seen.add(idx)
                    (ax, ay), (bx, by) = self.segs[idx]
                    if seg_point_dist(p[0], p[1], ax, ay, bx, by) <= radius:
                        return True
        return False


def _normalized_rings(polys):
    """Exterior antihorario, huecos horarios (regla de la mano derecha de GeoJSON)."""
    out = []
    for rings in polys:
        out.append([orient(r, i == 0) for i, r in enumerate(rings)])
    return out


def _split_edges_at_vertices(edges):
    """Nodado: parte cada arista en los vértices de otras aristas que caen sobre ella (uniones en T)."""
    vertices = set()
    for a, b in edges:
        vertices.add(a)
        vertices.add(b)
    # Índice de vértices por celda en grados (celdas ~ 5 m).
    cell = 5e-5
    grid: dict = {}
    for v in vertices:
        grid.setdefault((math.floor(v[0] / cell), math.floor(v[1] / cell)), []).append(v)
    eps = 1e-9
    out = []
    for a, b in edges:
        x0, x1 = sorted((a[0], b[0]))
        y0, y1 = sorted((a[1], b[1]))
        cands = []
        for gx in range(math.floor(x0 / cell), math.floor(x1 / cell) + 1):
            for gy in range(math.floor(y0 / cell), math.floor(y1 / cell) + 1):
                for v in grid.get((gx, gy), ()):
                    if v == a or v == b:
                        continue
                    if seg_point_dist(v[0], v[1], a[0], a[1], b[0], b[1]) <= eps:
                        dx, dy = b[0] - a[0], b[1] - a[1]
                        t = ((v[0] - a[0]) * dx + (v[1] - a[1]) * dy) / (dx * dx + dy * dy)
                        if 0 < t < 1:
                            cands.append((t, v))
        if not cands:
            out.append((a, b))
            continue
        cands.sort()
        prev = a
        for _, v in cands:
            if v != prev:
                out.append((prev, v))
                prev = v
        if prev != b:
            out.append((prev, b))
    return out


def dissolve(polys_list) -> list | None:
    """Une polígonos que comparten bordes (radios de una misma cartografía) en anillos limpios.

    Devuelve una lista de polígonos (cada uno: [exterior, huecos...]) o None si la topología no cierra,
    en cuyo caso quien llama conserva los polígonos originales.
    """
    edges = []
    for polys in polys_list:
        for rings in _normalized_rings(polys):
            for ring in rings:
                pts = [rkey(p) for p in ring]
                for i in range(len(pts) - 1):
                    if pts[i] != pts[i + 1]:
                        edges.append((pts[i], pts[i + 1]))
    edges = _split_edges_at_vertices(edges)
    # Cancelar aristas internas: aparecen una vez en cada sentido.
    count: dict = {}
    for a, b in edges:
        count[(a, b)] = count.get((a, b), 0) + 1
    boundary = []
    for (a, b), n in count.items():
        rev = count.get((b, a), 0)
        keep = n - rev
        for _ in range(max(keep, 0)):
            boundary.append((a, b))
    if not boundary:
        return None
    outgoing: dict = {}
    for a, b in boundary:
        outgoing.setdefault(a, []).append(b)
    used = set()
    rings = []

    def angle(p, q):
        return math.atan2(q[1] - p[1], q[0] - p[0])

    for start_edge in boundary:
        if start_edge in used:
            continue
        ring = [start_edge[0], start_edge[1]]
        used.add(start_edge)
        prev, cur = start_edge
        guard = 0
        while cur != ring[0]:
            guard += 1
            if guard > len(boundary) + 5:
                return None
            options = [n for n in outgoing.get(cur, []) if (cur, n) not in used]
            if not options:
                return None
            if len(options) == 1:
                nxt = options[0]
            else:
                # Mantener la cara a la izquierda: tomar el giro más a la izquierda.
                back = angle(cur, prev)
                def left_turn(n):
                    d = angle(cur, n) - back
                    while d <= 0:
                        d += 2 * math.pi
                    return d
                nxt = min(options, key=left_turn)
            used.add((cur, nxt))
            ring.append(nxt)
            prev, cur = cur, nxt
        rings.append(ring)
    exteriors = []
    holes = []
    for r in rings:
        a = ring_signed_area(r)
        if abs(a) < 1e-14:
            continue
        (exteriors if a > 0 else holes).append(r)
    if not exteriors:
        return None
    result = [[e] for e in exteriors]
    for h in holes:
        px, py = interior_point([[list(reversed(h))]])
        placed = False
        for poly in result:
            if point_in_rings(px, py, [poly[0]]):
                poly.append(h)
                placed = True
                break
        if not placed:
            return None
    return result


def to_geojson(polys) -> dict:
    def rr(ring):
        return [[round(x, OUT_DECIMALS), round(y, OUT_DECIMALS)] for x, y in ring]
    if len(polys) == 1:
        return {"type": "Polygon", "coordinates": [rr(r) for r in polys[0]]}
    return {"type": "MultiPolygon", "coordinates": [[rr(r) for r in rings] for rings in polys]}


def total_area_deg(polys) -> float:
    t = 0.0
    for rings in polys:
        for i, r in enumerate(rings):
            a = abs(ring_signed_area(r))
            t += a if i == 0 else -a
    return t


def iter_edges(polys) -> Iterable:
    for rings in polys:
        for ring in rings:
            for i in range(len(ring) - 1):
                yield ring[i], ring[i + 1]

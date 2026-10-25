"""Agrupa radios vecinos en zonas con población equilibrada.

Regionalización determinista por crecimiento desde semillas (misma familia que max-p: regiones
contiguas con piso de población), elegida porque el mismo insumo debe dar siempre las mismas
zonas: así una nueva corrida no "baraja" los límites y los informes siguen siendo comparables.

1. Si el municipio no supera `poblacion_zona_unica`, es una sola zona.
2. Por cada componente conexo: k = población / poblacion_objetivo (con tope `max_zonas`).
3. Semillas por muestreo de máxima distancia, ponderado por población.
4. Crecimiento simultáneo tipo Dijkstra: cruzar una barrera cuesta `penalidad_barrera` veces más.
   Cada zona deja de crecer al llegar a `poblacion_max`; lo que queda se asigna a la zona vecina
   más chica. Solo se agrega un radio a través de un vecino que ya es de esa zona: zonas contiguas.
5. Varias pasadas (Lloyd): la semilla se mueve al radio más cercano al centro de población.
6. Zonas por debajo de `poblacion_min` se fusionan con la vecina con la que comparten más borde.
"""
from __future__ import annotations

import heapq
import math

from .grafo import components

DEFAULTS = {
    "poblacion_zona_unica": 8000,
    "poblacion_objetivo": 9000,
    "poblacion_min": 6000,
    "factor_max": 1.5,
    "max_zonas": 40,
    "penalidad_barrera": 6.0,
    "penalidad_esquina": 2.0,
    "iteraciones": 4,
}


def _edge_cost(radios, graph, u, v, params):
    e = graph[u][v]
    d = math.dist(radios[u].xy, radios[v].xy) + 1.0
    frac = (e["barrier"] / e["len"]) if e["len"] > 0 else 0.0
    cost = d * (1.0 + params["penalidad_barrera"] * frac)
    if e["len"] == 0:
        cost *= params["penalidad_esquina"]
    return cost


def _pop(radios, nodes):
    return sum(radios[i].poblacion for i in nodes)


def _seeds(radios, comp, k):
    """Muestreo de máxima distancia, determinista; la primera semilla, cerca del centro de población."""
    tot = _pop(radios, comp) or len(comp)
    cx = sum(radios[i].xy[0] * (radios[i].poblacion or 1) for i in comp) / (tot if _pop(radios, comp) else len(comp))
    cy = sum(radios[i].xy[1] * (radios[i].poblacion or 1) for i in comp) / (tot if _pop(radios, comp) else len(comp))
    first = min(comp, key=lambda i: (math.dist(radios[i].xy, (cx, cy)), radios[i].codigo))
    seeds = [first]
    mind = {i: math.dist(radios[i].xy, radios[first].xy) for i in comp}
    while len(seeds) < k:
        cand = max(comp, key=lambda i: (mind[i] * math.sqrt(radios[i].poblacion + 1), radios[i].codigo))
        if cand in seeds:
            break
        seeds.append(cand)
        for i in comp:
            d = math.dist(radios[i].xy, radios[cand].xy)
            if d < mind[i]:
                mind[i] = d
    return seeds


def _grow(radios, graph, comp, seeds, params, pmax):
    comp_set = set(comp)
    assign = {}
    pops = [0] * len(seeds)
    heap = []
    for z, s in enumerate(seeds):
        heapq.heappush(heap, (0.0, radios[s].codigo, z, s))
    deferred = []
    while heap:
        cost, _, z, u = heapq.heappop(heap)
        if u in assign:
            continue
        if pops[z] >= pmax and pops[z] > 0:
            deferred.append(u)
            continue
        assign[u] = z
        pops[z] += radios[u].poblacion
        for v in graph[u]:
            if v in comp_set and v not in assign:
                heapq.heappush(heap, (cost + _edge_cost(radios, graph, u, v, params), radios[v].codigo, z, v))
    # Lo que quedó sin zona: a la zona vecina con menos población (manteniendo contigüidad).
    changed = True
    while changed:
        changed = False
        for u in sorted(comp_set - set(assign), key=lambda i: radios[i].codigo):
            options = sorted({assign[v] for v in graph[u] if v in assign})
            if options:
                z = min(options, key=lambda z: (pops[z], z))
                assign[u] = z
                pops[z] += radios[u].poblacion
                changed = True
    return assign


def _score(radios, assign, k, target):
    pops = [0] * k
    for u, z in assign.items():
        pops[z] += radios[u].poblacion
    dev = sum(((p - target) / target) ** 2 for p in pops)
    # Dispersión espacial: suma de distancias al centro de cada zona (zonas compactas puntúan mejor).
    cents = []
    for z in range(k):
        mem = [u for u, zz in assign.items() if zz == z]
        if not mem:
            cents.append((0.0, 0.0))
            continue
        cents.append((sum(radios[u].xy[0] for u in mem) / len(mem), sum(radios[u].xy[1] for u in mem) / len(mem)))
    spread = sum(math.dist(radios[u].xy, cents[z]) for u, z in assign.items()) / max(len(assign), 1)
    return dev * 1000.0 + spread / 1000.0


def _recenter(radios, assign, k):
    seeds = []
    for z in range(k):
        mem = [u for u, zz in assign.items() if zz == z]
        if not mem:
            continue
        tot = _pop(radios, mem)
        w = (lambda u: radios[u].poblacion) if tot else (lambda u: 1)
        den = tot if tot else len(mem)
        cx = sum(radios[u].xy[0] * w(u) for u in mem) / den
        cy = sum(radios[u].xy[1] * w(u) for u in mem) / den
        seeds.append(min(mem, key=lambda u: (math.dist(radios[u].xy, (cx, cy)), radios[u].codigo)))
    return seeds


def _merge_small(radios, graph, groups, pmin, max_groups=None):
    """Fusiona grupos chicos (o el excedente sobre `max_groups`) con el vecino con el que comparten
    más borde, prefiriendo borde que no vaya sobre una barrera."""
    while len(groups) > 1:
        pops = [_pop(radios, g) for g in groups]
        small = [i for i, p in enumerate(pops) if p < pmin]
        if not small and (max_groups is None or len(groups) <= max_groups):
            break
        if not small:
            small = list(range(len(groups)))
        owner = {}
        for gi, g in enumerate(groups):
            for u in g:
                owner[u] = gi
        merged = False
        for i in sorted(small, key=lambda i: (pops[i], min(radios[u].codigo for u in groups[i]))):
            shared: dict = {}
            for u in groups[i]:
                for v, e in graph[u].items():
                    j = owner.get(v)
                    if j is not None and j != i:
                        libre = max(e["len"] - e["barrier"], 0.0)
                        a, b = shared.get(j, (0.0, 0.0))
                        shared[j] = (a + libre, b + e["len"] + 0.001)
            if not shared:
                continue  # aislado: se intenta con el siguiente
            j = max(shared, key=lambda j: (shared[j][0] > 0, shared[j][0], shared[j][1], -pops[j], -j))
            groups[j] = sorted(set(groups[j]) | set(groups[i]))
            del groups[i]
            merged = True
            break
        if not merged:
            break
    return groups


def regionalize(radios, graph, params=None) -> list:
    """Devuelve una lista de zonas; cada zona es una lista ordenada de índices de radios."""
    p = dict(DEFAULTS)
    if params:
        p.update({k: v for k, v in params.items() if k in DEFAULTS})
    all_nodes = list(range(len(radios)))
    if not all_nodes:
        return []
    total = _pop(radios, all_nodes)
    if total <= p["poblacion_zona_unica"]:
        return [sorted(all_nodes, key=lambda i: radios[i].codigo)]

    target = p["poblacion_objetivo"]
    k_total = max(1, min(p["max_zonas"], round(total / target)))
    target = max(target, total / k_total)  # con tope de zonas, cada una crece
    pmax = target * p["factor_max"]
    pmin = min(p["poblacion_min"], target * 0.66)

    # Grafo "cortado": sin las vecindades que van mayormente sobre una barrera.
    # Las esquinas (contacto en un solo punto) tampoco unen bloques: suelen ser cruces de avenidas.
    cut = {u: {v: e for v, e in nb.items() if e["len"] > 0 and e["barrier"] / e["len"] < 0.5}
           for u, nb in graph.items()}

    comps = components(all_nodes, graph)
    groups = []
    orphans = []  # componentes chicos sin contacto (islas, enclaves)
    for comp in comps:
        if _pop(radios, comp) < pmin and len(comps) > 1:
            orphans.append(comp)
            continue
        for block in components(comp, cut):
            bp = _pop(radios, block)
            k = max(1, min(p["max_zonas"], round(bp / target)))
            if bp < pmin or k == 1:
                groups.append(sorted(block))
                continue
            seeds = _seeds(radios, block, k)
            best = None
            best_score = None
            for _ in range(p["iteraciones"]):
                assign = _grow(radios, cut, block, seeds, p, pmax)
                k_eff = len(seeds)
                sc = _score(radios, assign, k_eff, target)
                if best is None or sc < best_score:
                    best, best_score = (assign, k_eff), sc
                new_seeds = _recenter(radios, assign, k_eff)
                if new_seeds == seeds:
                    break
                seeds = new_seeds
            assign, k_eff = best
            for zi in range(k_eff):
                g = sorted(u for u, z in assign.items() if z == zi)
                if g:
                    groups.append(g)
    if groups:
        groups = _merge_small(radios, graph, groups, pmin, p["max_zonas"])
    if not groups:
        return [sorted({u for c in orphans for u in c}, key=lambda i: radios[i].codigo)]
    # Islas: a la zona más cercana (queda marcada como no contigua).
    for comp in orphans:
        cx = sum(radios[u].xy[0] for u in comp) / len(comp)
        cy = sum(radios[u].xy[1] for u in comp) / len(comp)
        best_z = min(range(len(groups)), key=lambda z: (min(math.dist((cx, cy), radios[u].xy) for u in groups[z]), z))
        groups[best_z] = sorted(set(groups[best_z]) | set(comp))
    return [sorted(z, key=lambda i: radios[i].codigo) for z in groups]


def is_contiguous(nodes, graph) -> bool:
    return len(components(nodes, graph)) <= 1

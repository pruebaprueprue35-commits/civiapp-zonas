"""Nombres legibles para las zonas: dirección respecto del centro + barrio de referencia."""
from __future__ import annotations

import math

DIRECCIONES = ["Este", "Noreste", "Norte", "Noroeste", "Oeste", "Sudoeste", "Sur", "Sudeste"]
PRIORIDAD_LUGAR = {"suburb": 0, "quarter": 1, "neighbourhood": 2, "hamlet": 3, "village": 4, "locality": 5}


def direccion(dx: float, dy: float, dist: float, radio_centro: float) -> str:
    if dist <= radio_centro:
        return "Centro"
    ang = math.degrees(math.atan2(dy, dx)) % 360.0
    return DIRECCIONES[int(((ang + 22.5) % 360.0) // 45.0)]


def nombrar(zonas: list) -> None:
    """`zonas`: dicts con "xy" (centro en metros respecto del centro de población del municipio),
    "lugares" (lista de (prioridad, distancia, nombre)) y "poblacion". Completa "nombre",
    "direccion", "lugar" y "codigo" de forma determinista y sin repetir nombres."""
    if not zonas:
        return
    if len(zonas) == 1:
        z = zonas[0]
        z.update(direccion="Municipio", lugar=_mejor_lugar(z), codigo="Z01", nombre="Zona única")
        return
    dists = sorted(math.hypot(*z["xy"]) for z in zonas)
    radio_centro = max(dists[0] * 1.01, dists[len(dists) // 4] * 0.9) if len(dists) >= 4 else dists[0] * 1.01
    for z in zonas:
        x, y = z["xy"]
        z["direccion"] = direccion(x, y, math.hypot(x, y), radio_centro)
        z["lugar"] = _mejor_lugar(z)
    # Código: de norte a sur y de oeste a este (estable para el mismo resultado).
    orden = sorted(zonas, key=lambda z: (-round(z["xy"][1], -2), round(z["xy"][0], -2), z["uid"]))
    for i, z in enumerate(orden, 1):
        z["codigo"] = f"Z{i:02d}"
    # Nombres: "Zona Norte"; si se repite, agregar el barrio; si aún se repite, numerar.
    por_dir: dict = {}
    for z in orden:
        por_dir.setdefault(z["direccion"], []).append(z)
    usados = set()
    for d, grupo in por_dir.items():
        base = "Zona Centro" if d == "Centro" else f"Zona {d}"
        if len(grupo) == 1:
            z = grupo[0]
            z["nombre"] = base + (f" · {z['lugar']}" if z["lugar"] else "")
            usados.add(z["nombre"])
            continue
        grupo.sort(key=lambda z: (math.hypot(*z["xy"]), z["uid"]))
        for i, z in enumerate(grupo, 1):
            nombre = f"{base} · {z['lugar']}" if z["lugar"] else f"{base} {i}"
            n = 2
            cand = nombre
            while cand in usados:
                cand = f"{nombre} {n}"
                n += 1
            z["nombre"] = cand
            usados.add(cand)


def _mejor_lugar(z) -> str:
    lugares = z.get("lugares") or []
    if not lugares:
        return ""
    lugares = sorted(lugares, key=lambda t: (t[0], t[1], t[2]))
    return lugares[0][2]

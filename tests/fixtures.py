"""Datos sintéticos con la forma de los insumos reales (no son datos reales).

Provincia 90 ("Tucumán de prueba"):
- 900001 "Ciudad Grande": cuadrícula 12x12 radios de ~0,0015° (~150 m) con una avenida vertical
  en la mitad (barrera), un río horizontal, una isla de 2 radios separada y uniones en T.
- 900002 "Pueblo Chico": 3x3 radios con 4.000 habitantes en total → una sola zona.
- 900003 "Comuna Sin Radios": sin radios → zona única con marca sin_radios.
Provincia 06 ("Buenos Aires de prueba"):
- 060001 "Partido Mediano": 6x6 radios con ~30.000 habitantes.
"""
from __future__ import annotations

import json
import os
import random


def _sq(x0, y0, x1, y1):
    return [[x0, y0], [x1, y0], [x1, y1], [x0, y1], [x0, y0]]


def _radio(cod, pob, ring):
    return {"type": "Feature", "properties": {"cod_2022": cod, "personas": pob},
            "geometry": {"type": "Polygon", "coordinates": [ring]}}


def build(dir_):
    rnd = random.Random(7)
    os.makedirs(os.path.join(dir_, "radios"), exist_ok=True)
    s = 0.0015
    munis = []
    radios90 = []
    # Ciudad Grande: origen (-65.20, -26.80)
    ox, oy = -65.20, -26.80
    n = 12
    munis.append({"type": "Feature", "properties": {"id": "900001", "nombre": "Ciudad Grande",
                  "provincia": {"id": "90", "nombre": "Tucumán de prueba"}},
                  "geometry": {"type": "Polygon", "coordinates": [_sq(ox - 0.001, oy - 0.001, ox + n * s + 0.001 + 0.02, oy + n * s + 0.001)]}})
    k = 0
    for i in range(n):
        for j in range(n):
            k += 1
            x0, y0 = ox + i * s, oy + j * s
            cod = f"90001{k:04d}"
            pob = rnd.randint(500, 1400)
            if i == 3 and j == 3:
                # Unión en T: este radio tiene un vértice extra en el medio de su borde derecho,
                # que el vecino no tiene.
                ring = [[x0, y0], [x0 + s, y0], [x0 + s, y0 + s / 2], [x0 + s, y0 + s], [x0, y0 + s], [x0, y0]]
                radios90.append(_radio(cod, pob, ring))
            else:
                radios90.append(_radio(cod, pob, _sq(x0, y0, x0 + s, y0 + s)))
    # Isla separada (dos radios) dentro del municipio pero sin contacto.
    ix = ox + n * s + 0.01
    radios90.append(_radio("900019001", 300, _sq(ix, oy, ix + s, oy + s)))
    radios90.append(_radio("900019002", 250, _sq(ix + s, oy, ix + 2 * s, oy + s)))
    # Pueblo Chico
    px, py = -65.40, -26.90
    munis.append({"type": "Feature", "properties": {"id": "900002", "nombre": "Pueblo Chico",
                  "provincia": {"id": "90", "nombre": "Tucumán de prueba"}},
                  "geometry": {"type": "Polygon", "coordinates": [_sq(px - 0.001, py - 0.001, px + 3 * s + 0.001, py + 3 * s + 0.001)]}})
    k = 0
    for i in range(3):
        for j in range(3):
            k += 1
            radios90.append(_radio(f"90002{k:04d}", 444, _sq(px + i * s, py + j * s, px + (i + 1) * s, py + (j + 1) * s)))
    # Comuna sin radios
    munis.append({"type": "Feature", "properties": {"id": "900003", "nombre": "Comuna Sin Radios",
                  "provincia": {"id": "90", "nombre": "Tucumán de prueba"}},
                  "geometry": {"type": "Polygon", "coordinates": [_sq(-65.6, -27.0, -65.55, -26.95)]}})
    # Provincia 06
    bx, by = -58.50, -34.60
    munis.append({"type": "Feature", "properties": {"id": "060001", "nombre": "Partido Mediano",
                  "provincia": {"id": "06", "nombre": "Buenos Aires de prueba"}},
                  "geometry": {"type": "Polygon", "coordinates": [_sq(bx - 0.001, by - 0.001, bx + 6 * s + 0.001, by + 6 * s + 0.001)]}})
    radios06 = []
    k = 0
    for i in range(6):
        for j in range(6):
            k += 1
            radios06.append(_radio(f"06001{k:04d}", 830, _sq(bx + i * s, by + j * s, bx + (i + 1) * s, by + (j + 1) * s)))
    with open(os.path.join(dir_, "municipios.ndjson"), "w") as fh:
        for f in munis:
            # Formato GeoRef NDJSON: atributos + "geometria".
            o = dict(f["properties"])
            o["geometria"] = f["geometry"]
            fh.write(json.dumps(o) + "\n")
    with open(os.path.join(dir_, "radios", "90.geojsonl"), "w") as fh:
        for f in radios90:
            fh.write(json.dumps(f) + "\n")
    with open(os.path.join(dir_, "radios", "06.geojson"), "w") as fh:
        json.dump({"type": "FeatureCollection", "features": radios06}, fh)
    # Barrera: avenida vertical en la mitad de Ciudad Grande; río horizontal a 1/3.
    mid_x = ox + 6 * s
    third_y = oy + 4 * s
    barreras = [
        {"type": "Feature", "properties": {"highway": "primary"},
         "geometry": {"type": "LineString", "coordinates": [[mid_x, oy - 0.002], [mid_x, oy + n * s + 0.002]]}},
        {"type": "Feature", "properties": {"waterway": "river"},
         "geometry": {"type": "LineString", "coordinates": [[ox - 0.002, third_y], [ox + n * s + 0.002, third_y]]}},
    ]
    with open(os.path.join(dir_, "barreras.geojsonl"), "w") as fh:
        for f in barreras:
            fh.write(json.dumps(f) + "\n")
    lugares = [
        {"type": "Feature", "properties": {"name": "San Cayetano", "place": "neighbourhood"},
         "geometry": {"type": "Point", "coordinates": [ox + 2 * s, oy + 10 * s]}},
        {"type": "Feature", "properties": {"name": "Barrio Norte", "place": "suburb"},
         "geometry": {"type": "Point", "coordinates": [ox + 9 * s, oy + 10 * s]}},
        {"type": "Feature", "properties": {"name": "Kiosco", "shop": "kiosk"},
         "geometry": {"type": "Point", "coordinates": [ox + 9 * s, oy + 2 * s]}},
    ]
    with open(os.path.join(dir_, "lugares.geojsonl"), "w") as fh:
        for f in lugares:
            fh.write(json.dumps(f) + "\n")
    return {"barrera_x": mid_x, "rio_y": third_y, "ox": ox, "oy": oy, "s": s, "n": n}

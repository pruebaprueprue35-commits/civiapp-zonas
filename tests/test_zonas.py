import gzip
import hashlib
import json
import math
import os
import shutil
import sys
import tempfile
import unittest

sys.path.insert(0, os.path.join(os.path.dirname(__file__), ".."))

from tests import fixtures  # noqa: E402
from zonas import geo, grafo, regiones  # noqa: E402
from zonas.__main__ import empaquetar, verificar  # noqa: E402
from zonas.generar import generar  # noqa: E402

PARAMS = json.load(open(os.path.join(os.path.dirname(__file__), "..", "config", "parametros.json")))


def leer_paquete(out):
    man = json.load(open(os.path.join(out, "manifest.json")))
    recs = []
    for pr in man["provincias"]:
        for p in pr["partes"]:
            with gzip.open(os.path.join(out, p["archivo"]), "rt") as fh:
                recs += [json.loads(l) for l in fh]
    return man, recs


class TestGeo(unittest.TestCase):
    def test_dissolve_two_squares(self):
        a = [[[(0, 0), (1, 0), (1, 1), (0, 1), (0, 0)]]]
        b = [[[(1, 0), (2, 0), (2, 1), (1, 1), (1, 0)]]]
        d = geo.dissolve([a, b])
        self.assertEqual(len(d), 1)
        self.assertAlmostEqual(geo.total_area_deg(d), 2.0)
        self.assertEqual(len(d[0]), 1)

    def test_dissolve_hole(self):
        sq = lambda x, y: [[[(x, y), (x + 1, y), (x + 1, y + 1), (x, y + 1), (x, y)]]]
        ring = [sq(i, j) for i in range(3) for j in range(3) if (i, j) != (1, 1)]
        d = geo.dissolve(ring)
        self.assertEqual(len(d), 1)
        self.assertEqual(len(d[0]), 2, "debe tener un hueco")
        self.assertAlmostEqual(geo.total_area_deg(d), 8.0)
        self.assertFalse(geo.point_in_polys(1.5, 1.5, d))
        self.assertTrue(geo.point_in_polys(0.5, 0.5, d))

    def test_dissolve_corner_touch(self):
        a = [[[(0, 0), (1, 0), (1, 1), (0, 1), (0, 0)]]]
        b = [[[(1, 1), (2, 1), (2, 2), (1, 2), (1, 1)]]]
        d = geo.dissolve([a, b])
        self.assertIsNotNone(d)
        self.assertAlmostEqual(geo.total_area_deg(d), 2.0)

    def test_dissolve_t_junction(self):
        a = [[[(0, 0), (1, 0), (1, 0.5), (1, 1), (0, 1), (0, 0)]]]
        b = [[[(1, 0), (2, 0), (2, 1), (1, 1), (1, 0)]]]
        d = geo.dissolve([a, b])
        self.assertEqual(len(d), 1)
        self.assertEqual(len(d[0]), 1)
        self.assertAlmostEqual(geo.total_area_deg(d), 2.0)

    def test_interior_point_concave(self):
        u = [[[(0, 0), (3, 0), (3, 3), (2, 3), (2, 1), (1, 1), (1, 3), (0, 3), (0, 0)]]]
        x, y = geo.interior_point(u)
        self.assertTrue(geo.point_in_polys(x, y, u))


class TestPipeline(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.tmp = tempfile.mkdtemp()
        cls.info = fixtures.build(cls.tmp)
        cls.out = os.path.join(cls.tmp, "dist")
        cls.man = generar(os.path.join(cls.tmp, "municipios.ndjson"), os.path.join(cls.tmp, "radios"), cls.out,
                          "test-1", PARAMS, None, os.path.join(cls.tmp, "barreras.geojsonl"),
                          os.path.join(cls.tmp, "lugares.geojsonl"), log=lambda *_: None)
        cls.man_doc, cls.recs = leer_paquete(cls.out)

    @classmethod
    def tearDownClass(cls):
        shutil.rmtree(cls.tmp)

    def zonas(self, muni):
        return [r for r in self.recs if r["tipo"] == "zona" and r["municipio_georef"] == muni]

    def test_paquete_valido(self):
        self.assertEqual(verificar(self.out), [])
        self.assertEqual(self.man["totales"]["provincias"], 2)
        self.assertEqual(self.man["totales"]["municipios"], 4)

    def test_cada_radio_una_zona(self):
        todos = [c for r in self.recs if r["tipo"] == "zona" for c in r["radios"]]
        self.assertEqual(len(todos), len(set(todos)))
        self.assertEqual(len(todos), 144 + 2 + 9 + 36)

    def test_pueblo_chico_una_zona(self):
        z = self.zonas("900002")
        self.assertEqual(len(z), 1)
        self.assertEqual(z[0]["nombre"], "Zona única")
        self.assertEqual(z[0]["poblacion"], 444 * 9)

    def test_comuna_sin_radios(self):
        z = self.zonas("900003")
        self.assertEqual(len(z), 1)
        self.assertIn("sin_radios", z[0]["flags"])
        self.assertIsNone(z[0]["poblacion"])

    def test_ciudad_grande_equilibrada_y_contigua(self):
        z = self.zonas("900001")
        total = sum(x["poblacion"] for x in z)
        self.assertGreaterEqual(len(z), 10)
        for x in z:
            if "no_contigua" in x["flags"]:
                # Solo la zona que absorbió la isla puede ser no contigua.
                self.assertTrue(any(c.startswith("900019") for c in x["radios"]))
            self.assertGreater(x["poblacion"], 3000, x)
            self.assertLess(x["poblacion"], 9000 * 2.2, x)
        self.assertEqual(total, sum(r["poblacion"] or 0 for r in self.recs
                                    if r["tipo"] == "municipio" and r["municipio_georef"] == "900001"))

    def test_barreras_respetadas(self):
        """La mayoría de las zonas no cruza la avenida ni el río."""
        z = self.zonas("900001")
        bx, ry, ox, oy, s = self.info["barrera_x"], self.info["rio_y"], self.info["ox"], self.info["oy"], self.info["s"]
        cruzan = 0
        for x in z:
            lados_x = set()
            lados_y = set()
            for c in x["radios"]:
                k = int(c[5:]) - 1
                if c.startswith("900019"):
                    continue
                i, j = divmod(k, 12)
                lados_x.add((ox + (i + 0.5) * s) < bx)
                lados_y.add((oy + (j + 0.5) * s) < ry)
            if len(lados_x) > 1 or len(lados_y) > 1:
                cruzan += 1
        self.assertLessEqual(cruzan, max(1, len(z) // 5), f"{cruzan} de {len(z)} zonas cruzan barreras")

    def test_nombres_unicos_y_codigos(self):
        z = self.zonas("900001")
        nombres = [x["nombre"] for x in z]
        self.assertEqual(len(nombres), len(set(nombres)))
        self.assertEqual(sorted(x["codigo"] for x in z), [f"Z{i:02d}" for i in range(1, len(z) + 1)])
        self.assertTrue(any("Barrio Norte" in n or "San Cayetano" in n for n in nombres))
        self.assertFalse(any("Kiosco" in n for n in nombres))

    def test_barrios_de_cada_zona(self):
        z = self.zonas("900001")
        todos = [b for x in z for b in x.get("barrios", [])]
        self.assertIn("San Cayetano", todos)
        self.assertIn("Barrio Norte", todos)
        self.assertNotIn("Kiosco", todos)
        for x in z:  # cada barrio que da nombre a la zona figura en su lista
            if x["lugar"]:
                self.assertIn(x["lugar"], x["barrios"])

    def test_geometria_disuelta(self):
        z = self.zonas("900001")
        disueltas = sum(1 for x in z if x["calidad"]["disuelta"])
        self.assertGreaterEqual(disueltas, len(z) - 1)

    def test_punto_en_zona(self):
        """Cada centro de radio cae en exactamente una zona del municipio."""
        z = self.zonas("900001")
        polys = [(x["uid"], geo.polygons_of(x["geometry"])) for x in z]
        ox, oy, s = self.info["ox"], self.info["oy"], self.info["s"]
        for i in range(12):
            for j in range(12):
                px, py = ox + (i + 0.5) * s, oy + (j + 0.5) * s
                hits = [u for u, p in polys if geo.point_in_polys(px, py, p)]
                self.assertEqual(len(hits), 1, (i, j, hits))

    def test_reproducible(self):
        out2 = os.path.join(self.tmp, "dist2")
        generar(os.path.join(self.tmp, "municipios.ndjson"), os.path.join(self.tmp, "radios"), out2,
                "test-1", PARAMS, None, os.path.join(self.tmp, "barreras.geojsonl"),
                os.path.join(self.tmp, "lugares.geojsonl"), log=lambda *_: None)
        m1 = json.load(open(os.path.join(self.out, "manifest.json")))
        m2 = json.load(open(os.path.join(out2, "manifest.json")))
        h1 = [p["sha256"] for pr in m1["provincias"] for p in pr["partes"]]
        h2 = [p["sha256"] for pr in m2["provincias"] for p in pr["partes"]]
        self.assertEqual(h1, h2)

    def test_zip_y_filtro_provincia(self):
        out3 = os.path.join(self.tmp, "dist3")
        man = generar(os.path.join(self.tmp, "municipios.ndjson"), os.path.join(self.tmp, "radios"), out3,
                      "test-2", PARAMS, {"06"}, log=lambda *_: None)
        self.assertEqual([p["georef_id"] for p in man["provincias"]], ["06"])
        z = empaquetar(out3, man)
        import zipfile
        with zipfile.ZipFile(z) as zf:
            self.assertIn("manifest.json", zf.namelist())
            self.assertEqual(len(zf.namelist()), 2 + len(man["provincias"][0]["partes"]))

    def test_verificar_detecta_corrupcion(self):
        out4 = os.path.join(self.tmp, "dist4")
        shutil.copytree(self.out, out4)
        man = json.load(open(os.path.join(out4, "manifest.json")))
        f = os.path.join(out4, man["provincias"][0]["partes"][0]["archivo"])
        with open(f, "rb") as fh:
            data = bytearray(fh.read())
        data[-5] ^= 0xFF
        with open(f, "wb") as fh:
            fh.write(bytes(data))
        self.assertTrue(any("hash" in e for e in verificar(out4)))


class TestRegionesBorde(unittest.TestCase):
    def test_sin_poblacion(self):
        rs = [grafo.Radio(f"9000100{i}", 0, [[[(i, 0), (i + 1, 0), (i + 1, 1), (i, 1), (i, 0)]]]) for i in range(5)]
        proj = geo.LocalProjection(0, 0)
        g = grafo.build_graph(rs, proj, None)
        zs = regiones.regionalize(rs, g, PARAMS)
        self.assertEqual(len(zs), 1)

    def test_tope_de_zonas(self):
        rs = []
        for i in range(30):
            for j in range(30):
                rs.append(grafo.Radio(f"9{i:03d}{j:03d}", 2000, [[[(i * .001, j * .001), ((i + 1) * .001, j * .001),
                                                                    ((i + 1) * .001, (j + 1) * .001), (i * .001, (j + 1) * .001),
                                                                    (i * .001, j * .001)]]]))
        proj = geo.LocalProjection(0.015, 0.015)
        g = grafo.build_graph(rs, proj, None)
        p = dict(PARAMS)
        zs = regiones.regionalize(rs, g, p)
        self.assertLessEqual(len(zs), p["max_zonas"])
        self.assertGreaterEqual(len(zs), p["max_zonas"] - 5)
        for z in zs:
            self.assertTrue(regiones.is_contiguous(z, g))


if __name__ == "__main__":
    unittest.main()

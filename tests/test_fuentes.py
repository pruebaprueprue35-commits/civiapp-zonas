import os
import sys
import unittest

sys.path.insert(0, os.path.join(os.path.dirname(__file__), ".."))
sys.path.insert(0, os.path.join(os.path.dirname(__file__), "..", "scripts"))

try:
    import pandas as pd
except ImportError:  # pragma: no cover
    pd = None

import descargar_fuentes as dfu  # noqa: E402


@unittest.skipIf(pd is None, "pandas no instalado")
class TestPoblacion(unittest.TestCase):
    def test_tabla_larga(self):
        # 4 radios; la variable "P01" suma la población del "país" de prueba (1000), "V01" no.
        df = pd.DataFrame({
            "id_geo": ["900010101"] * 2 + ["900010102"] * 2 + ["900010103"] * 2 + ["900010104"] * 2,
            "codigo_variable": ["P01", "V01"] * 4,
            "conteo": [250, 80, 300, 90, 200, 70, 250, 60],
        })
        s, meta = dfu.elegir_poblacion(df, "id_geo", 1000)
        self.assertEqual(int(s["900010102"]), 300)
        self.assertEqual(meta["variable"], ["codigo_variable", "P01"])

    def test_tabla_ancha(self):
        df = pd.DataFrame({"link": ["a", "b"], "hogares": [100, 120], "personas": [480, 520]})
        s, meta = dfu.elegir_poblacion(df, "link", 1000)
        self.assertEqual(meta["columna"], "personas")

    def test_sin_coincidencia(self):
        df = pd.DataFrame({"link": ["a"], "x": [5]})
        with self.assertRaises(SystemExit):
            dfu.elegir_poblacion(df, "link", 1000)


class TestMunicipios(unittest.TestCase):
    def test_normaliza_georef_ndjson(self):
        import json
        import tempfile
        from tests import fixtures
        t = tempfile.mkdtemp()
        fixtures.build(t)
        src = os.path.join(t, "municipios.ndjson")
        # Multiplicar para superar el mínimo de 500 municipios.
        lines = open(src).read().splitlines()
        big = os.path.join(t, "big.ndjson")
        with open(big, "w") as fh:
            for k in range(130):
                for ln in lines:
                    o = json.loads(ln)
                    o["id"] = f"{o['id']}{k:03d}"
                    fh.write(json.dumps(o) + "\n")
        out = os.path.join(t, "norm.ndjson")
        n = dfu.normalizar_municipios(big, out)
        self.assertEqual(n, 520)
        o = json.loads(open(out).readline())
        self.assertEqual(o["provincia_id"], "90")
        self.assertIn("geometria", o)


if __name__ == "__main__":
    unittest.main()

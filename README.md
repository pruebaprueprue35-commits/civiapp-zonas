# CiviApp Zonas — generador nacional

Arma las **zonas de todos los municipios de Argentina** a partir de los radios censales del Censo 2022 y las publica para que el plugin de WordPress **CiviApp Zonas** las descargue y cargue solo.

## Qué hace

1. **Descarga fuentes públicas** (`scripts/descargar_fuentes.py`):
   - Municipios con su límite oficial: GeoRef (datos.gob.ar).
   - Radios censales 2022 con población: INDEC, cartografía corregida de Rodriguez y de Grande (CONICET).
   - Avenidas principales, vías de tren, ríos y nombres de barrios: OpenStreetMap (Geofabrik).
   - Si la tabla de población viene "larga" (una fila por variable), elige sola la variable cuyo total nacional coincide con la población del Censo 2022 (46.044.703 ± 3 %).
2. **Genera las zonas** (`python -m zonas generar`), provincia por provincia:
   - Cada radio va al municipio que contiene su punto interior.
   - Municipios con hasta 8.000 habitantes: una sola zona.
   - El resto: se agrupan radios vecinos en zonas de ~9.000 habitantes (mínimo 6.000, tope 40 zonas por municipio), sin cruzar avenidas principales, vías ni ríos cuando se puede.
   - Algoritmo determinista (mismos datos → mismas zonas → mismos archivos): crecimiento desde semillas con varias pasadas de reajuste, y fusión de zonas chicas con la vecina que comparte más borde.
   - Nombre: dirección respecto del centro + barrio de OpenStreetMap ("Zona Norte · San Cayetano"). Código Z01, Z02… de norte a sur.
   - Control de calidad por zona: partes separadas, forma muy alargada, poca o mucha población, sin datos censales. Esas quedan "para revisar".
   - Municipios sin radios: el municipio entero es una zona, marcada `sin_radios`.
3. **Empaqueta** (`civiapp-zonas/1`): `manifest.json` + partes `prov-XX-NNN.ndjson.gz` (cada parte trae municipios completos) + `cobertura.csv` + `RESUMEN.md` + un ZIP para subir a mano.
4. **Verifica** (`python -m zonas verificar dist`): hashes, una zona por radio, municipios completos.

## Cómo se usa desde la tablet (GitHub)

1. Este repositorio en GitHub: **Actions → Generar zonas → Run workflow**. Elegí `todas` o códigos de provincia (por ejemplo `90` para Tucumán).
2. Tarda entre 20 y 90 minutos. Al terminar publica una **versión (release)** con las zonas.
3. En WordPress, el plugin **CiviApp Zonas** la detecta solo (todos los días) o al tocar **Buscar e importar zonas**. Dirección a poner en Ajustes del plugin:
   `https://github.com/pruebaprueprue35-commits/civiapp-zonas/releases/latest/download/manifest.json` (ya viene configurada en el plugin 1.0.1)
4. Cada 3 meses se vuelve a generar sola para tomar cambios de GeoRef, INDEC y OpenStreetMap.

## Ajustes (`config/parametros.json`)

| Parámetro | Valor | Qué controla |
| --- | --- | --- |
| `poblacion_zona_unica` | 8000 | Hasta esta población, el municipio es una sola zona |
| `poblacion_objetivo` | 9000 | Población buscada por zona |
| `poblacion_min` | 6000 | Zonas más chicas se fusionan |
| `factor_max` | 1.5 | Una zona deja de crecer al pasar objetivo × factor |
| `max_zonas` | 40 | Tope de zonas por municipio (en ciudades grandes las zonas crecen) |
| `penalidad_barrera` | 6 | Cuánto cuesta cruzar una avenida, vía o río |
| `radio_barrera_m` | 25 | Distancia para considerar que un borde va sobre una barrera |
| `compacidad_min` | 0.12 | Debajo de esto la zona se marca "forma muy alargada" |

Cambiar parámetros cambia las zonas: hacerlo antes de usarlas en informes, no entre meses.

## Probar en una computadora

```sh
python -m unittest discover -s tests -v      # sin dependencias (pandas opcional)
pip install -r requirements-descarga.txt
python scripts/descargar_fuentes.py --salida datos
python -m zonas generar --municipios datos/municipios.ndjson --radios datos/radios \
  --barreras datos/barreras.geojsonl --lugares datos/lugares.geojsonl \
  --salida dist --version 2026.10.25 --provincias 90 --zip
python -m zonas verificar dist
```

## Atribución obligatoria

Zonas CiviApp elaboradas con radios censales INDEC Censo 2022 (cartografía Rodriguez y de Grande, CONICET, CC BY-SA), límites de GeoRef (datos.gob.ar) y datos © colaboradores de OpenStreetMap (ODbL). La atribución viaja en cada manifiesto y el plugin la muestra.

## Pendiente de verificar con la primera corrida real

El entorno donde se construyó no tenía acceso a las fuentes oficiales. Antes de usar las zonas en informes, revisar en el `RESUMEN.md` de la primera versión:
- que la población total sume cerca de 46 millones y cada provincia tenga radios;
- `radios fuera de municipios`: en provincias donde los municipios no cubren todo el territorio (comunas rurales) es esperable;
- si la columna o variable de población que eligió el descargador es la correcta (queda escrita en `resumen_fuentes.json`).

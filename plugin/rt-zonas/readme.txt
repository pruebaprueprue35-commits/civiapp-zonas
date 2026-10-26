=== CiviApp Zonas ===
Versión: 1.0.1
Requiere WordPress: 6.0 · PHP: 7.4 · Base: MySQL/MariaDB con InnoDB
Licencia: GPL-2.0-or-later

Zonas de todos los municipios de Argentina, generadas automáticamente desde los radios
censales del Censo 2022. Se descargan y cargan solas, se actualizan por versión y ubican
cada punto (por ejemplo, el GPS de un reclamo) en su zona y en su sector de 200 m.

== Cómo se carga solo ==

1. Al activar el plugin busca, en este orden:
   a) un paquete incluido en la carpeta datos/ del plugin;
   b) la última versión publicada en GitHub. Viene configurada de fábrica:
      https://github.com/pruebaprueprue35-commits/civiapp-zonas/releases/latest/download/manifest.json
      (se puede cambiar en Ajustes o en wp-config.php con define( 'RTZ_MANIFEST_URL', 'https://...' ); ).
2. Importa por tandas: cada minuto con WP-Cron y, mientras tengas abierta la pantalla
   Ubicaciones RT → Zonas, cada pocos segundos. No hace falta tráfico en el sitio.
3. Cada municipio se activa completo o no se activa: nunca queda a medias.
4. Todos los días busca versiones nuevas (se puede apagar en Ajustes).

== Pantallas (Ubicaciones RT → Zonas) ==

* Estado: versión cargada, avance, botones Buscar e importar, Reintentar y Vincular.
* Revisar zonas: por provincia y municipio, mapa y lista; las zonas en naranja tienen algo
  para revisar (partes separadas, forma alargada, poca o mucha población, sin datos censales).
  Se puede renombrar una zona: el nombre se conserva en versiones futuras mientras la zona
  tenga los mismos radios.
* Probar ubicación: pegás latitud y longitud y te dice zona y sector.
* Ajustes: dirección de descarga, actualización automática, provincias, subida manual de ZIP.

== Para otros plugins (RT Reportes, Ads, Motor 100) ==

  rtz_ubicar( $lat, $lng, $localidad_id = 0 )
    → estado ok | sin_zona | ambiguo | fuera_de_municipio | punto_invalido, zona, sector
  rtz_zonas_de_municipio( $localidad_id )            // id de Ubicaciones RT
  rtz_zonas_de_municipio( '900203', 'georef' )       // código oficial GeoRef
  rtz_zona( $id )             // también zonas viejas: los reclamos guardan el id
  rtz_zona_geometria( $id )   // GeoJSON
  do_action( 'rtz_version_activada', $version_id, $version, $estado )

API pública (datos territoriales, sin datos personales, con límite por IP):
  GET /wp-json/civiapp-zonas/v1/ubicar?lat=-26.77&lng=-65.21
  GET /wp-json/civiapp-zonas/v1/zonas?localidad=11   (o ?municipio=CODIGO_GEOREF)

== Datos y atribución ==

Zonas CiviApp elaboradas con radios censales INDEC Censo 2022 (cartografía Rodriguez y
de Grande, CONICET, CC BY-SA), límites de GeoRef (datos.gob.ar) y datos © colaboradores de
OpenStreetMap (ODbL). Mapa de fondo: © colaboradores de OpenStreetMap; si no carga, la
pantalla dibuja las zonas sin mapa de fondo.

== Qué nunca hace ==

* No borra zonas: cada importación es una versión; las viejas quedan inactivas.
* No escribe en tablas de RT Platform Core: solo lee el catálogo para vincular municipios.
* Al desinstalar no borra los datos (salvo que se marque explícitamente).

== Pruebas ==

tests/run-tests.php: 49 pruebas de integración en WordPress real (carga sola, vínculo con
Core, ubicación de puntos, versión nueva remota, parte dañada, candado, ZIP malicioso).

== Changelog ==

= 1.1.2 =
* Comprobación de tablas sin distinguir mayúsculas (prefijo con mayúsculas en MySQL con lower_case_table_names=1).

= 1.1.1 =
* Activación de una versión: cada sentencia y el COMMIT se comprueban; si algo falla, la versión anterior sigue activa entera.
* Desinstalar ya no borra nada (se quitó la opción «borrar al quitar»).
* La versión del paquete no puede salir de su carpeta («..»); límites de manifiesto, partes, registros, tamaño y vértices;
  validación de polígonos (anillos cerrados, coordenadas numéricas en Argentina).
* Exporta también las zonas inactivas que usan los reclamos del paquete.
* Pruebas: tests/test-auditoria-zonas.php (24).

= 1.1.0 =
* Cada zona dice qué barrios la integran (lugares de OpenStreetMap dentro del polígono y barrios del
  catálogo RT por nombre o por reclamos ubicados): `rtz_barrios_de_zona( $id )`, `rtz_barrios_texto( $id )`
  y columna «Barrios que la integran» en Revisar zonas.
* Municipios marcados como municipio o departamento (provincias sin municipios en GeoRef).
* Lee paquetes 1.0 (sin esos campos) sin errores.
* Proveedor de datos para Descargas CiviApp: zonas con polígono, barrios y versión.

= 1.0.1 =
* Primera versión pública.

CANCHA CAMPEONATOS v0.4.0 — complemento modular
Requiere tema CANCHA Glass v1.2.0, WordPress >=6.4, PHP >=8.0.
Actualizar desde v0.3.0 mediante Plugins > Añadir > Subir ZIP > Reemplazar, sin borrar el plugin ni las entradas de WordPress. Realizar copia de seguridad y probar en staging.

GESTIÓN (todas las opciones aparecen dentro del menú CANCHA)
1. CANCHA > Guía de campeonatos: flujo de creación y enlaces a las fichas.
2. Crear campeonato: nombre, descripción, modalidad, organizador, sede, fechas, reglamento y reglas de puntuación 3-1-0 ajustables.
3. Crear equipos: escudo, campeonato, ciudad, cancha, colores, fundación, web.
4. Crear jugadores: fotografía, club, dorsal, posición, nacionalidad, pierna hábil. Evitar datos de menores sin autorizaciones y no publicar domicilios/documentos.
5. Crear directivos y cuerpo técnico: foto, club o campeonato, función y año de inicio.
6. Crear temporadas históricas: campeonato, edición, campeón, subcampeón y fecha.
7. Crear partidos: campeonato, local, visitante, jornada, fecha, sede, árbitro y, tras cargar equipos, jugadores, titularidad, minutos, goles, asistencias y tarjetas.
8. CANCHA > Jornadas: cargar y validar marcadores en bloque. Solo partidos finales publicados cuentan para tabla y estadísticas.
9. Informar al usuario genera aviso PUBLICO en página de campeonato, no un correo ni un push; requiere validación previa de resultados.
10. CANCHA > Importar CSV: altas masivas de equipos o jugadores, limitado por archivo y filas. Revisar la guía y formato disponible en ese panel.

PÁGINAS Y FICHAS
- Ficha de campeonato: datos institucionales, tabla de posiciones, calendario e historial de campeones, staff.
- Club: escudo, directiva, plantel, resultados y estadísticas básicas G-E-P, goles a favor/en contra, puntos.
- Jugador: perfil deportivo, titularidades, PJ, goles, asistencias, tarjetas y minutos registrados en partidos finales.
- Partido: marcador, sede, fecha, árbitro y planilla individual (si existe).
- Edición histórica: campeón y subcampeón.
- Buscar desde CANCHA: el buscador nativo encuentra entidades publicadas por título/contenido.

SHORTCODES
[cancha_campeonatos] [cancha_tabla campeonato="123"] [cancha_partidos campeonato="123" jornada="1"]
[cancha_plantel equipo="123"] [cancha_jugador id="123"] [cancha_equipo id="123"]
[cancha_historial campeonato="123"] [cancha_directorio] [cancha_goleadores campeonato="123"]

LÍMITES ACTUALES (NO CONFUNDIR CON SPORTSPRESS NI ANWP)
- Plantel asociado al club actual; no historial de transferencias ni plantel específico de cada temporada.
- No elimina partidos históricos ni migra viejos metadatos; no hay llaves de eliminación directa, grupos, playoffs, promedio de descenso, sanciones automáticas, reglas multietapa ni sustituciones cronológicas.
- La tabla 3-1-0 funciona como liga todos-contra-todos; no contempla puntos descontados manuales.
- No se generan automáticamente fixtures, notificaciones, pronósticos ni puntos GamiPress.
- Las estadísticas se generan de las planillas registradas en partidos finales y publicados, no de APIs externas.
- Para volúmenes muy grandes se necesitará paginación/caché de consultas estadísticas y una revisión de rendimiento real.

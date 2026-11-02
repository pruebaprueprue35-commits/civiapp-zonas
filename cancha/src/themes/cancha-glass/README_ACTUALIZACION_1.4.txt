CANCHA Glass 1.4.0 — Partidos por día y navegación móvil

REQUISITOS: WordPress y complementos CANCHA existentes; PHP 8.0+ para CANCHA Predicciones.
INSTALACIÓN: reemplazar el ZIP del tema instalado; no borrar el tema anterior ni usuarios. Verificar en staging antes de producción.

CAMBIOS:
- Portada centrada en Partidos, selección de fecha: anteayer / ayer / hoy / mañana / pasado mañana y calendario; desplazar para otras fechas.
- Listados separados por competición, con amateur publicado y encuentros oficiales retornados por la API.
- Botón claro/oscuro visible también en teléfonos y dentro de Más.
- Barra inferior: Partidos, Amateur, Predicciones, Novedades, Más. El último abre menú real.
- CANCHA > Apariencia y menús: preferencia inicial claro/oscuro y barra de herramientas de WordPress en el frontend (Todos/Suscriptores/No ocultar).
- Sponsor grande y secundarios conservados desde CANCHA Ads. No se alteran anuncios existentes.
- El campo de asociación correcto de partido amateur es _cancha_comp_id.

DEPENDENCIAS RECOMENDADAS:
- CANCHA Fútbol Oficial 1.1.0 ofrece cof_get_calendar_day() para consultar encuentros de un día.
- CANCHA Predicciones 0.2.0 incorpora la posibilidad de pronosticar partidos oficiales futuros si disponen de ID y horario confirmado, además de amateur.

LIMITACIONES: TheSportsDB gratuito devuelve un número limitado de eventos en algunas consultas; CANCHA no inventa encuentros ausentes. Un proveedor más completo puede requerir API-Football y una cuenta con cobertura de las ligas/temporada deseadas.
La franja «Acceder / Registrarse» que se muestre POR ENCIMA de CANCHA en visitantes NO es necesariamente la barra #wpadminbar: comprobar qué plugin, widget o header externo la genera antes de quitarla.
El registro requiere términos legales definitivos y la configuración de correo; no se bypassan requisitos.

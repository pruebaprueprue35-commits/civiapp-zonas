# CANCHA v5 — cambios aplicados en reportetalitense.com.ar (2026-11-02)

Respaldo previo: `Herramientas → Respaldo CANCHA` (ZIP con tema, complementos `cancha-*` y opciones; secretos omitidos), descargado antes de cualquier cambio.
Ningún usuario, punto, torneo, partido, anuncio ni página existente fue borrado ni modificado. No se ejecutaron DELETE/DROP/TRUNCATE.

## Versiones desplegadas (Plugins/Temas → subir ZIP → «Reemplazar»)

| Componente | Antes | Después | Rollback |
|---|---|---|---|
| Tema CANCHA Glass | 1.4.0 | 1.4.1 | Subir `themes/cancha-glass` del respaldo |
| CANCHA Fútbol Oficial | 1.1.0 | 1.2.3 | Subir carpeta del respaldo |
| CANCHA · Predicciones | 0.2.0 | 0.3.0 | Ídem (tablas sin cambios de esquema) |
| CANCHA · Campeonatos | 0.4.0 | 0.5.0 | Ídem (metadatos nuevos se ignoran) |
| CANCHA · Ads | 0.5.0 | 0.6.0 | Ídem |
| CANCHA · Puesta a punto (nuevo) | — | 0.2.0 | Desactivar |

## Configuración de WordPress

| Pantalla | Opción | Antes | Después | Motivo |
|---|---|---|---|---|
| Ajustes → Generales | Zona horaria | UTC+0 | America/Argentina/Buenos_Aires | «Hoy», horarios y cierre de pronósticos estaban 3 h corridos |
| Páginas | `/partidos/` (id 190), `/amateur/` (id 191) | 404 | Publicadas (vacías, las arma el tema) | Rutas de la barra inferior |
| Páginas | «Términos y condiciones de CANCHA (BORRADOR…)» (id 212) | — | **Borrador** | Para aprobación del propietario; no publicado |
| Herramienta DEMO | Torneo «CANCHA DEMO» | — | Zonas A–D en equipos DEMO; 12 partidos «(DEMO QA)»: jornada 2 + semifinales Copa Oro/Plata | Retirar: botón «Retirar datos DEMO» (pasa a borrador) |

## Errores corregidos

1. **Predicciones oficiales nunca disponibles**: TheSportsDB envía `strTimestamp` en UTC sin sufijo; el validador exigía zona horaria → 0 partidos elegibles. Además se mostraba la hora UTC como hora argentina (19:00 aparecía 22:00). Se normaliza a `…Z`.
2. **Cobertura**: el plan gratuito devuelve ~3 partidos por día. Ahora se usa la jornada completa (`eventsround`) → p. ej. Liga Profesional 4 partidos hoy en vez de 2, Primera Nacional 13 el sábado.
3. **HTTP 429 del proveedor**: tope local de 24 consultas/min, ventanas incompletas cacheadas solo 10 min, caché del día ligada a la zona horaria.
4. **Pronóstico guardado no se marcaba** al elegir 1 o 2 (PHP convierte las claves «1»/«2» a enteros; solo X se marcaba). Corregido en los 3 formularios.
5. Pronóstico oficial de partidos nocturnos (00:15 UTC) fallaba al guardar (día UTC ≠ día local). Corregido.
6. Tras pronosticar se redirigía a la portada; ahora vuelve a la página de origen con aviso.
7. **Mezcla profesional/amateur** en Inicio y `/calendario/`: eliminada. `/partidos/` = solo profesional; `/amateur/` = experiencia propia.
8. Barra inferior: Partidos→`/partidos/`, Amateur→`/amateur/` (antes Inicio y `/campeonatos/#…`), estado activo correcto.
9. Modo claro/oscuro: se aplica antes de pintar (sin parpadeo), persiste entre páginas, botón visible a 390 px, buscador legible en oscuro.
10. Encabezado duplicado en `/futbol-oficial/` eliminado.
11. Banner publicitario desbordaba el ancho en `/partidos/`.
12. Tablas de posiciones: los partidos de eliminatoria ya no suman puntos.

## Funcionalidades nuevas

- `/amateur/`: tarjetas de torneos (estado, equipos, zonas, jornadas, progreso) y próximos partidos amateur.
- Ficha de torneo con pestañas: Resumen · Fixture por jornada (J1, J2…) · Zonas (tabla por zona) · Eliminatorias (llaves Copa Oro / Copa Plata) · Equipos · Jugadores (goleadores y planteles).
- Escritorio: campo «Zona» en equipos y «Fase / Copa / Instancia» en partidos (solo `manage_options`).
- `/predicciones/`: pestañas «Oficiales» y «Amateur», puntos separados, historial «Mis pronósticos» por origen, acceso único (sin repetir «Ingresar» en cada partido).
- Ads: nuevas ubicaciones Partidos, Amateur y Predicciones (y Novedades) con respaldo en las campañas de «Inicio · después de los módulos».

## Pendiente del propietario

- Aprobar/editar y **publicar** los términos (borrador id 212), elegirlos en CANCHA → Configuración → «Página de términos». Hasta entonces el registro y las predicciones siguen deshabilitados por diseño (requisito de consentimiento).
- Verificar el envío de correo del servidor (SMTP) con una cuenta real tras publicar los términos.
- Publicar campañas de Ads con imagen y URL (hoy solo existe un borrador).
- Cambiar la contraseña de la cuenta de prueba de administrador (fue compartida por chat) y activar 2FA.

# CANCHA Fútbol Oficial — 1.0.0

Complemento **independiente**, sin escrituras en los campeonatos amateur, usuarios, predicciones, noticias, patrocinadores ni GamiPress. Trabaja con la API desde el servidor mediante WordPress HTTP API. No crea tablas ni modifica el tema.

## Qué muestra

- Liga Profesional Argentina, Primera Nacional y Copa Argentina.
- CONMEBOL Libertadores y Sudamericana.
- Próximos encuentros, resultados y tablas de posiciones **cuando el proveedor los entregue**.
- Página de tarjetas y lista de partidos con aspecto nativo de CANCHA y vista móvil.
- Horarios locales de Argentina y aviso de datos desactualizados.
- Datos almacenados temporalmente en caché; consulta solo al abrir la competición. La portada usa solo lo ya almacenado para no gastar cuota.

## Instalación

1. Hacer respaldo de WordPress y probar en staging.
2. Ir a **Complementos → Añadir nuevo → Subir complemento** y seleccionar `CANCHA_Futbol_Oficial_v1.0.0.zip`.
3. Activar. En **CANCHA → Fútbol oficial**, elegir proveedor y temporada.
4. Para todos los campeonatos elegir **API-Football**, obtener clave gratuita en `https://dashboard.api-football.com/`, colocarla en el formulario y guardar. También puede usarse `define('CANCHA_OFICIAL_API_KEY', 'TU_CLAVE');` en `wp-config.php`. Si ya existe clave API-Football en CANCHA Core, se ofrece reutilizarla.
5. Revisar IDs y presionar **Probar** para cada competición. Los valores prellenados para API-Football son **sugerencias que deben verificarse**. Si no hay acceso a la temporada elegida, la API mostrará un aviso. Elegir otro año solo si está dentro de las condiciones de tu plan.
6. Abrir `/futbol-oficial/`: **CANCHA Glass 1.3.0** incorpora la salida del complemento sin modificar sus archivos. Si usás otro tema, poné `[cancha_oficial]` en cualquier página.
7. Para mostrar solo una competición: `[cancha_oficial_competicion codigo="libertadores"]` o `codigo="sudamericana"`, `codigo="liga_profesional"`, `codigo="primera_nacional"`, `codigo="copa_argentina"`.

## Alternativa sin clave

Podés elegir **TheSportsDB**. Su clave gratuita v1 es pública `123`, y la configuración inicial incluye cinco competiciones con IDs identificados en sus páginas oficiales: Liga Profesional `4406`, Primera Nacional `4616`, Copa Argentina `4500`, Libertadores `4501` y Sudamericana `4724`. Todos los IDs pueden corregirse en el panel. La cobertura y los límites de cada consulta deben verificarse en tu servidor y **el acceso gratuito puede ser incompleto**. No presenta resultados ficticios para rellenar espacios.

## Seguridad y aislamiento

- HTTPS, URL de host fija, SSL verificado, redirecciones deshabilitadas, límite de tamaño y tiempo de consulta.
- Clave enviada solo en el encabezado del pedido desde el servidor, no al navegador. El secreto se cifra en el sitio si se guarda mediante el panel. No entra en archivos de auditoría ni consola.
- Presupuesto de 75 consultas API-Football al día para el propio complemento, para dejar margen al plan gratuito (100/día); **otras aplicaciones que usen la misma clave consumen aparte**.
- Caché mínimo de una hora, seis horas para posiciones; último dato válido disponible por siete días si hay fallos. El administrador puede limpiar caché o probar conexión.
- No se ejecuta código arbitrario, no se crean resultados de prueba, no se modifican cuentas y no se alteran las tablas de CANCHA Campeonatos.

## Limitaciones actuales

- **No es streaming**: refresca cuando vence la caché; la palabra «En vivo» reproduce el estado reportado por el proveedor durante la última consulta.
- API-Football gratuito tiene límite de solicitudes y **temporadas**; la cobertura real de la temporada 2026 tiene que probarse con tu propia clave.
- `/fixtures` muestra una ventana próxima (14 días antes y 21 después), no toda la temporada. TheSportsDB gratuito puede devolver muy pocos eventos.
- Algunas copas no tienen una tabla de posiciones convencional.
- No sincroniza calendarios oficiales en el complemento Predicciones. Una integración futura deberá usar IDs estables y un proceso explícito, sin mezclar fixtures amateur.
- No incorpora fuentes de datos sin autorización de sus titulares ni omite licencias del proveedor.

## Desinstalación

Desactivar no altera otras tablas. El complemento conserva sus opciones mientras esté desactivado, para facilitar recuperación. Eliminarlo por completo no borra usuarios ni torneos.

## App móvil/PWA

No es necesario crear una aplicación APK separada. CANCHA Glass dispone de PWA instalable desde el navegador si se activa en CANCHA → Configuración; este complemento aparece dentro de esa aplicación y conserva la misma sesión. La PWA necesita HTTPS y navegador compatible. El complemento no cambia el service worker ni cachea páginas o resultados sensibles.

## Advertencia especial: Argentina 2026

TheSportsDB reconoce problemas de tablas para competiciones partidas como Apertura y Clausura: la tabla puede quedar congelada en una etapa. El plugin mostrará una advertencia visible y los datos siempre se atribuyen a su proveedor. En la versión gratuita también puede entregar solo un encuentro por consulta; esto NO equivale a una jornada completa.

## Sincronización programada

WP-Cron refresca partidos y posiciones cada 12 horas para las competiciones configuradas; la caché y el presupuesto de API-Football limitan el consumo. WP-Cron depende de visitas y de la configuración del hosting, por lo que no garantiza horarios exactos. El administrador puede probar manualmente cada competición.

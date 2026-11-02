CANCHA Predicciones 0.2.0 — amateur y fútbol oficial (sin apuestas)

Se conserva íntegra la tabla y las predicciones amateur anteriores.
Se crea una SEGUNDA tabla wp_cancha_predictions_official para 1/X/2 de partidos oficiales, separada de puntos amateur y de GamiPress.
Los partidos oficiales se leen automáticamente del complemento CANCHA Fútbol Oficial 1.1.0.
Los pronósticos solo están habilitados con usuario autenticado y habilitado legalmente, ID verificable, fecha exacta con zona horaria, estado pendiente y comienzo futuro.
Cada usuario solo tiene una selección por partido; puede cambiarla antes del comienzo.
Al recibir resultados finales válidos se recalculan los puntos de modo idempotente; cron dos veces al día intenta conciliar predicciones pendientes.
No premia cuando el proveedor no confirma el resultado. La ausencia/escasez de partidos es una limitación del proveedor, no una invitación a fabricar partidos.
Advertencia: en eliminatorias con alargue/penales, aclarar cómo define la competición el resultado 1/X/2 antes de habilitar la temporada.
NO modificar usuarios ni cambiar puntos GamiPress por esta integración.

INSTALACIÓN: actualizar reemplazando el plugin anterior; no desinstalarlo ni borrar tablas. Confirmar versión y DB en CANCHA > Predicciones.

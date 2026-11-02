CANCHA Predicciones v0.1.0
DEPENDENCIAS: CANCHA Glass 1.3.0, CANCHA Campeonatos 0.4.0.
Instalación: Complementos > Añadir > Subir ZIP. Activar. Se crea una tabla propia de pronósticos sin alterar usuarios, campeonatos o tablas existentes.
CONFIGURACIÓN: CANCHA > Predicciones. Puntaje inicial 10 puntos por acierto.
FUNCIONES: pronóstico 1/X/2 antes de la hora de inicio, registro verificado y aceptación de términos, modificación hasta comienzo, recalculo idempotente tras validar/corregir marcador y conciliación horaria.
NO INCLUYE: resultados oficiales externos en el motor de predicciones, premios monetarios, GamiPress (requiere adaptador reversible), predicciones de marcador exacto, ranking global persistente, correo ni push.
PRIVACIDAD: las predicciones quedan asociadas al user_id y match_id en tabla propia. No elimina usuarios ni datos al desactivar.
LÍMITE: probar en staging con registros de ejemplo antes de permitir concursos reales. Las modificaciones masivas de meta de partidos se concilian con WP-Cron; configurá un cron real si necesitás puntualidad.

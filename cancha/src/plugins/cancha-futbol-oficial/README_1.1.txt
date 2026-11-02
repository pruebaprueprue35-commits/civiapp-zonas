CANCHA Fútbol Oficial 1.1.0 — proveedor por fecha

Añade función read-only cof_get_calendar_day($competition, $yyyy_mm_dd), con caché de 45 minutos por liga y fecha.
- API-Football: fixtures?date=YYYY-MM-DD&league=...&season=...&timezone=...
- TheSportsDB: eventsday.php?d=YYYY-MM-DD&s=Soccer&l=...
- No altera partidos del sistema amateur, usuarios ni GamiPress.
- El proveedor puede no cubrir todos los eventos; TheSportsDB V1 gratuito limita eventosday y API-Football puede limitar temporadas.
- Admin: CANCHA > Fútbol oficial para probar IDs, proveedor y cobertura.
No se debe usar como feed de resultados garantizado al minuto ni mostrar resultados ficticios como reales.

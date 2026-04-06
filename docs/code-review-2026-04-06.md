# Code Review (2026-04-06)

Dieses Dokument fasst konkrete Fundstellen aus einer statischen Prüfung der PHP-Anwendung zusammen.

## Kritische Probleme

1. **Schema-Drift zwischen SQL-Installationsdatei und Runtime-Migration**  
   - `sql/schema.sql` erstellt in `orders` weiterhin `public_id` und `edit_token` inkl. Unique-Indizes.  
   - `AppRepository::ensureSchemaInitialized()` entfernt genau diese Spalten/Indizes explizit wieder, wenn sie existieren.  
   - Ergebnis: Zwei konkurrierende Wahrheiten über das DB-Schema.

2. **Nicht erreichbarer Wartungs-Codepfad („cleanup“) im Admin**  
   - Action `cleanup_daily_residual_data` wird serverseitig verarbeitet, aber in der Admin-UI existiert kein Formular/Button, der diese Action sendet.  
   - Damit ist der Pfad praktisch „tot“ (nur manuell über crafted POST erreichbar).

## Sichere Lösch-/Refactor-Kandidaten

- Alte CSS-Pattern `admin-row-*` werden in den aktuellen Templates nicht mehr referenziert.
- Fallback-Settings (`voting_end_time`, `order_end_time`, `paypal_link_active_id`) sind technisch noch eingebaut, werden aber im aktuellen Admin-Flow nicht mehr gepflegt (nur Tageswerte).

## Stil-/Strukturauffälligkeiten

- Gemischte Formatierungsstile (teils stark komprimierte Einzeiler, teils strukturiert).
- Validierungslogik für Bestellungen ist doppelt vorhanden (Index + Admin), nur teilweise extrahiert.
- In mehreren Bereichen werden einzeilige `if`-Statements ohne `{}` verwendet.

## Priorisierte Maßnahmen

- **Hoch:** `sql/schema.sql` auf den tatsächlichen Stand bringen (insb. `orders` ohne `public_id`/`edit_token`).
- **Hoch:** Cleanup-Feature entweder in UI anbinden oder kompletten Codepfad entfernen.
- **Mittel:** Gemeinsame Validierungs-/Normalisierungsfunktionen aus `public/*.php` in `src/` verschieben.
- **Mittel:** Einheitlichen Stilguide festlegen (Einrückung, Klammern, HTML/PHP-Layout).
- **Niedrig:** Veraltete CSS-Klassen (`admin-row-*`) entfernen.

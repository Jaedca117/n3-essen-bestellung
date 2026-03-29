# N3 Essen-Bestellung (Shared-Hosting-fähig)

Dieses Projekt ist als **klassische PHP-Webanwendung** aufgebaut, damit es auf typischen Webservern von Hostern (Apache/Nginx + PHP + MySQL) ohne Container laufen kann.

## Ziele des Umbaus

- Keine lokale/Container-Abhängigkeit für den Betrieb.
- Direkte Nutzung einer vorhandenen **MySQL-Datenbank**, die auch von anderen Websites verwendet wird.
- Sichere Trennung über einen konfigurierbaren **Tabellen-Präfix**.

## Voraussetzungen

- PHP 8.1+ mit `pdo_mysql`
- MySQL 5.7+ oder MariaDB 10.4+
- Webspace mit Document Root auf `public/`

## Projektstruktur

- `public/` → Webroot (nur dieser Ordner darf öffentlich erreichbar sein)
- `src/` → Anwendungslogik
- `sql/schema.sql` → Tabellen für die Anwendung
- `config.sample.php` → Konfigurationsvorlage

## Deployment auf einem Hoster

1. Dateien hochladen (FTP/Git/Deployment-Tool).
2. `config.sample.php` nach `config.php` kopieren.
3. In `config.php` DB-Zugangsdaten eintragen.
4. Wichtig: `table_prefix` setzen (z. B. `n3_essen_`), damit keine Kollision mit anderen Seiten entsteht.
5. SQL aus `sql/schema.sql` in derselben Datenbank ausführen (Tabellenname mit Präfix).
6. Document Root auf `public/` zeigen lassen.

## Datenbank in geteilter Nutzung

Da die Datenbank von mehreren Seiten genutzt wird, ist das Präfix entscheidend:

- Beispiel: `n3_essen_`
- Tabellen heißen dann z. B. `n3_essen_orders` und `n3_essen_order_items`

Damit bleibt diese Anwendung isoliert, obwohl dieselbe MySQL-Instanz genutzt wird.

## Sicherheitshinweise

- `config.php` darf **nicht** im öffentlich erreichbaren Verzeichnis liegen.
- Fehlermeldungen in Produktion deaktivieren (`display_errors = Off`).
- DB-Nutzer nur mit benötigten Rechten anlegen.

## Lokaler Start (optional)

```bash
php -S 127.0.0.1:8080 -t public
```

Danach im Browser: `http://127.0.0.1:8080`

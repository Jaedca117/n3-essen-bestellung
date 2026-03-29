# Vereins-Essen (PHP + MySQL, Shared Hosting geeignet)

Pragmatische, mobilfreundliche Webanwendung für Vereine:

- **Abstimmung** für Lieferanten bis zur konfigurierten Deadline.
- **Automatischer Wechsel** in den Bestellmodus.
- **Öffentliche Bestellliste** inkl. Summen (Gesamt/Bar/PayPal).
- **Druckansicht** für den Verantwortlichen.
- **Admin-Bereich** mit Passwort-Login (Session + CSRF-Schutz).
- **Automatischer Tages-Reset** ohne Cron/Daemon (Prüfung bei Seitenaufruf).

## Tech-Stack

- **PHP 8.1+** (serverseitiges Rendering, kein Node.js im Betrieb)
- **MySQL/MariaDB**
- **Plain CSS** (keine Build-Toolchain)

Warum passend:
- Läuft auf klassischem Webspace.
- Deployment per FTP/SFTP/Git möglich.
- Wartbar, wenig moving parts, keine Spezialinfrastruktur.

## Struktur

- `public/index.php` – Hauptseite (Abstimmung + Bestellung)
- `public/admin.php` – Admin-Login und Einstellungen
- `public/print.php` – Druckansicht
- `src/` – DB + Businesslogik
- `sql/schema.sql` – Tabellen + Standarddaten
- `config.sample.php` – Konfigurationsvorlage

## Installation (Webspace)

1. Dateien hochladen.
2. `config.sample.php` zu `config.php` kopieren und DB-Daten eintragen.
3. `sql/schema.sql` importieren.
4. Document Root auf `public/` setzen.

## Erster Admin-Zugang

- Benutzer: `admin`
- Passwort: `admin1234`

**Wichtig:** Nach dem ersten Login sofort neues Passwort setzen (direkt in DB per `password_hash()` erzeugen und in `admin_users.password_hash` aktualisieren).

## Betriebslogik

Die Anwendung bestimmt den Status aus aktueller Uhrzeit + Einstellungen:

- `voting_end_time` → Ende Abstimmung
- `order_end_time` → Ende Bestellphase
- `order_closed` → manuelles Schließen durch Admin

### Reset ohne Cron

Bei jedem Seitenaufruf wird geprüft, ob `daily_reset_time` erreicht ist und ob heute schon zurückgesetzt wurde.
Wenn nicht, werden Stimmen + Bestellungen + Tagesstatus zurückgesetzt.

## Sicherheit / Missbrauchsschutz

- Passwort-Hashing über `password_hash` / `password_verify`
- Session-Login im Admin-Bereich
- CSRF-Token im Admin-Bereich
- Serverseitige Validierung + Feldlängenlimits
- Prepared Statements (PDO)
- Einfaches Rate-Limiting (Votes/Bestellungen/Login)
- Bearbeitung/Löschung von Bestellungen nur via Bearbeitungstoken

## Hinweise für Updates

- Vor Update DB-Backup machen.
- Bei Schema-Änderungen SQL gezielt migrieren.
- `config.php` unverändert lassen, nur neue Keys ergänzen.


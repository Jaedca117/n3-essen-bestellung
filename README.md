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
3. Document Root auf `public/` setzen.

> Die Anwendung initialisiert das DB-Schema beim ersten Aufruf automatisch (inkl. Standarddaten).

## Ubuntu 22.04 Anleitung (Apache)

Die folgenden Schritte installieren die Anwendung auf einem frischen **Ubuntu 22.04** Server mit **Apache2**, **PHP 8.1** und **MariaDB**.

### 1) System aktualisieren

```bash
sudo apt update
sudo apt upgrade -y
```

### 2) Apache, PHP und MariaDB installieren

```bash
sudo apt install -y apache2 mariadb-server \
  php8.1 libapache2-mod-php8.1 php8.1-mysql php8.1-mbstring php8.1-xml php8.1-curl
```

Dienste starten und beim Boot aktivieren:

```bash
sudo systemctl enable --now apache2
sudo systemctl enable --now mariadb
```

Optional Firewall freigeben:

```bash
sudo ufw allow 'Apache Full'
sudo ufw status
```

### 3) Datenbank anlegen

MariaDB absichern (empfohlen):

```bash
sudo mysql_secure_installation
```

Datenbank, Benutzer und Rechte erstellen:

```sql
sudo mysql
CREATE DATABASE essenbestellung CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'essenuser'@'localhost' IDENTIFIED BY 'BITTE_STARKES_PASSWORT_SETZEN';
GRANT ALL PRIVILEGES ON essenbestellung.* TO 'essenuser'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

Schema-Import ist optional: Beim ersten Aufruf legt die Anwendung Tabellen und Basisdaten selbst an.

### 4) Projekt bereitstellen

Beispiel per Git:

```bash
cd /var/www
sudo git clone <REPO_URL> essenbestellung
sudo chown -R www-data:www-data /var/www/essenbestellung
```

Konfiguration erzeugen:

```bash
cd /var/www/essenbestellung
cp config.sample.php config.php
```

Danach in `config.php` die Zugangsdaten für MySQL/MariaDB eintragen.

### 5) Apache VirtualHost einrichten

Datei anlegen: `/etc/apache2/sites-available/essenbestellung.conf`

```apache
<VirtualHost *:80>
    ServerName beispiel.de
    ServerAlias www.beispiel.de

    DocumentRoot /var/www/essenbestellung/public

    <Directory /var/www/essenbestellung/public>
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog ${APACHE_LOG_DIR}/essenbestellung_error.log
    CustomLog ${APACHE_LOG_DIR}/essenbestellung_access.log combined
</VirtualHost>
```

Site aktivieren und Apache neu laden:

```bash
sudo a2ensite essenbestellung.conf
sudo a2dissite 000-default.conf
sudo systemctl reload apache2
```

### 6) Schreibrechte und Sicherheit

```bash
sudo chown -R www-data:www-data /var/www/essenbestellung
sudo find /var/www/essenbestellung -type d -exec chmod 755 {} \;
sudo find /var/www/essenbestellung -type f -exec chmod 644 {} \;
```

Wichtig:
- `config.php` niemals im Web ausliefern (liegt im Projektroot, nicht im `public/`-Ordner).
- Möglichst HTTPS aktivieren (z. B. mit Let's Encrypt / certbot).

### 7) HTTPS aktivieren (optional, empfohlen)

```bash
sudo apt install -y certbot python3-certbot-apache
sudo certbot --apache -d beispiel.de -d www.beispiel.de
```

### 8) Funktion prüfen

```bash
sudo apache2ctl configtest
sudo systemctl status apache2 --no-pager
php -v
```

Dann im Browser öffnen:
- `http://beispiel.de/` (oder direkt die Server-IP)
- Admin: `http://beispiel.de/admin.php`

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

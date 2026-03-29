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

## Simple Anleitung: Ubuntu 22.04 + Apache

> Beispiel verwendet Domain `essen.example.de` und Pfad `/var/www/n3-essen-bestellung`.

1. **Pakete installieren**

   ```bash
   sudo apt update
   sudo apt install -y apache2 mysql-server php libapache2-mod-php php-mysql
   ```

2. **Projekt auf den Server legen**

   ```bash
   sudo mkdir -p /var/www/n3-essen-bestellung
   sudo chown -R "$USER":www-data /var/www/n3-essen-bestellung
   # dann Dateien ins Verzeichnis kopieren (z. B. per git clone oder scp)
   ```

3. **Config anlegen**

   ```bash
   cd /var/www/n3-essen-bestellung
   cp config.sample.php config.php
   ```

   Danach in `config.php` Datenbank-Zugangsdaten und `table_prefix` (z. B. `n3_essen_`) eintragen.

4. **Datenbank initialisieren**

   ```bash
   mysql -u root -p
   ```

   In MySQL (Beispiel):

   ```sql
   CREATE DATABASE n3_essen CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   CREATE USER 'n3_essen_user'@'localhost' IDENTIFIED BY 'STRONG_PASSWORD';
   GRANT ALL PRIVILEGES ON n3_essen.* TO 'n3_essen_user'@'localhost';
   FLUSH PRIVILEGES;
   EXIT;
   ```

   Schema importieren:

   ```bash
   mysql -u n3_essen_user -p n3_essen < /var/www/n3-essen-bestellung/sql/schema.sql
   ```

5. **Apache VirtualHost erstellen**

   Datei `/etc/apache2/sites-available/n3-essen-bestellung.conf`:

   ```apache
   <VirtualHost *:80>
       ServerName essen.example.de
       DocumentRoot /var/www/n3-essen-bestellung/public

       <Directory /var/www/n3-essen-bestellung/public>
           AllowOverride All
           Require all granted
       </Directory>

       ErrorLog ${APACHE_LOG_DIR}/n3-essen_error.log
       CustomLog ${APACHE_LOG_DIR}/n3-essen_access.log combined
   </VirtualHost>
   ```

6. **Site aktivieren und Apache neu laden**

   ```bash
   sudo a2ensite n3-essen-bestellung.conf
   sudo a2dissite 000-default.conf
   sudo systemctl reload apache2
   ```

7. **Dateirechte setzen (minimal)**

   ```bash
   sudo chown -R www-data:www-data /var/www/n3-essen-bestellung
   sudo find /var/www/n3-essen-bestellung -type d -exec chmod 755 {} \;
   sudo find /var/www/n3-essen-bestellung -type f -exec chmod 644 {} \;
   ```

8. **Test**

   Im Browser `http://essen.example.de` öffnen.

Optional (empfohlen): Danach HTTPS mit Let's Encrypt (`certbot`) einrichten.

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

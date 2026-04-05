# Anleitung für Orgamitglieder

Diese Anleitung richtet sich an Verantwortliche, die den Tagesablauf im Admin-Bereich steuern.

## 1) Admin-Login

1. Öffne `https://dein-verein.de/admin.php`.
2. Melde dich mit Admin-Benutzer und Passwort an.
3. Ändere das Standardpasswort sofort, falls noch nicht geschehen.

## 2) Tageszeiten konfigurieren

Im Admin-Bereich kannst du folgende Zeiten setzen:

- **Abstimmungsende** (`voting_end_time`)
- **Bestellende** (`order_end_time`)
- **Reset-Zeit** (`daily_reset_time`)

Empfohlener Ablauf:
1. Abstimmung am Vormittag öffnen.
2. Bestellphase bis kurz vor der finalen Sammelbestellung laufen lassen.
3. Reset-Zeit auf einen Zeitpunkt legen, der sicher nach dem Tagesgeschäft liegt.

## 3) Lieferanten pflegen

- Lieferantenliste aktuell halten (Namen klar und eindeutig).
- Bei Ausfall eines Lieferanten den Eintrag temporär deaktivieren oder ersetzen.
- Über "Verfügbare Wochentage" kannst du Lieferanten auf bestimmte Tage einschränken (leer = jeden Tag).
- Änderungen möglichst vor Start der Abstimmung durchführen.

## 4) Laufenden Tag überwachen

- Öffentliche Liste regelmäßig prüfen.
- Auf fehlerhafte Einträge reagieren (z. B. doppelte Bestellungen).
- Bei Bedarf manuell schließen (`order_closed`), wenn keine neuen Bestellungen mehr zugelassen werden sollen.

## 5) Druckansicht nutzen

1. Öffne `https://dein-verein.de/print.php`.
2. Prüfe Bestellungen und Summen.
3. Drucke oder speichere die Ansicht als PDF für die Bestellung beim Lieferanten.

## 6) Tagesreset verstehen

Das Tool führt den Reset automatisch beim nächsten Seitenaufruf nach Erreichen der konfigurierten Reset-Zeit aus.

Beim Reset werden i. d. R. zurückgesetzt:
- Stimmen,
- Bestellungen,
- Tagesstatus.

Wichtig:
- Wenn nach der Reset-Zeit niemand die Seite aufruft, erfolgt der Reset erst beim nächsten Aufruf.

## 7) Sicherheit & Betrieb

- Admin-Zugang nur an berechtigte Personen geben.
- Starkes Passwort verwenden und regelmäßig ändern.
- Vor Updates Datenbank-Backup erstellen.
- Nach Änderungen Funktionscheck durchführen:
  - Startseite (`/`)
  - Admin (`/admin.php`)
  - Druckansicht (`/print.php`)

## 8) Kurze Notfall-Checkliste

1. Seite nicht erreichbar → Hosting/Webserver/DB prüfen.
2. Login geht nicht → Passwort-Hash in der DB prüfen.
3. Keine neuen Einträge → Uhrzeiten und `order_closed` prüfen.
4. Falsche Summen → Eingaben in der Bestellliste kontrollieren und ggf. korrigieren.

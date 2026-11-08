# MyWish 🎁

Eine kleine, selbst gehostete Wunschwelt für Freunde und Familie. PHP 8.2+, HTML, CSS, Vanilla JavaScript und geschützte JSON-Dateien. Keine Datenbank, kein Composer, kein Node, kein Cron und kein SMTP im Betrieb.

## V1

- Browser-Erstinstallation, Registrierung, Login, Abmelden, eigenes Konto und Passwort ändern, Konto löschen. Optional [mit Google anmelden](docs/GOOGLE_LOGIN.md), einschließlich ausdrücklicher Kontoverknüpfung.
- Neue Passwörter: mindestens 8 Zeichen, maximal 72 UTF-8-Bytes. Sonderzeichen sind optional. Bestehende Passwörter bleiben gültig.
- Alle angemeldeten Nutzer sehen alle Listen. Genau ein Eigentümer bearbeitet eine Liste und ihre Wünsche. Andere Nutzer können eine Einheit pro Wunsch reservieren und ihre Reservierung wieder freigeben.
- Der Eigentümer erhält **keine Reservierungsinformationen**, weder in HTML noch in JSON. Es gibt keine Adminrolle, privaten Listen, Kinderprofile oder Freigabelinks. Maßgeblich ist [Epic #3](https://github.com/JuergenSu/MyWish/issues/3); ältere Rollen-/Sharing-Anforderungen einzelner Tickets werden dadurch ersetzt.
- Listen mit Anlass, Datum, Beschreibung und Cover; Wünsche mit optionalem Shoplink, Bild, EUR-Preis und Menge (1–99).
- Optionaler Produktimport mit Vorschau (OpenGraph, JSON-LD, HTML-Titel und Amazon.de-Produktfelder). Eingaben prüfen und explizit speichern; ein manueller Wunsch funktioniert immer.
- Meine Reservierungen, Suche über Listen/Anzeigenamen, mobile Oberfläche und Formulare ohne JavaScript.
- Installationsbanner auf dem Handy: Android-Installationsdialog oder iPhone-Anleitung für den Home-Bildschirm; App-Manifest und lokale Icons, auch im Unterordner.

## Auf dem Handy installieren

Nach der Einrichtung erscheint oben in der mobilen Browseransicht ein Hinweis. Unter Android öffnet **Hinzufügen** den Installationsdialog, sobald der Browser die App als installierbar meldet. Andernfalls zeigt **So geht’s** den Weg über das Browsermenü. Auf dem iPhone: **Teilen → Zum Home-Bildschirm → Hinzufügen** (bei fehlendem Menüeintrag in Safari öffnen).

Für die Installation ist HTTPS nötig; localhost funktioniert für Entwicklung ebenfalls. Im installierten App-Modus bleibt das Banner verborgen. Schließen blendet es auf diesem Browser für sieben Tage aus; der optionale lokale Speicher enthält dafür nur einen Zeitstempel pro Installationspfad. Browser und Betriebssystem bestimmen, ob und wie eine Installation angeboten wird, und benötigen die Bestätigung des Nutzers.

`manifest.webmanifest`, `sw.js` und `assets/icons/` mitkopieren. Der Service Worker verwendet ausschließlich das Netzwerk und legt **keine Offline-Kopien** privater Daten an. Die App benötigt eine Internetverbindung. Login, Berechtigungen und Sicherheitsheader gelten unverändert. Ohne JavaScript oder bei normalen unverschlüsselten HTTP-Seiten bleibt der Installationshinweis ausgeblendet.

Auf dem Startbildschirm erscheint das weiße Geschenk auf lila Hintergrund: Android nutzt die Manifest-Icons mit 192/512 px, iPhone das eigene Apple-Touch-Icon mit 180 px. Falls bereits eine ältere Verknüpfung mit einem anderen Icon besteht, diese entfernen und die App erneut zum Startbildschirm hinzufügen.

## Fehlendes Bild ergänzen

Wenn beim Wunsch kein Bild vorhanden ist, öffnet **Bei Google nach einem Bild suchen** einen neuen Tab mit Produktname und gegebenenfalls Amazon-Artikelnummer. Die Suche startet nur durch deinen Klick. Kopiere beim gewünschten Bild die direkte Bildadresse, füge sie in **Bildadresse** ein und wähle **Bild übernehmen**. Prüfe die Vorschau und speichere danach den Wunsch.

Die Bilddatei wird serverseitig geprüft und lokal gespeichert; kein externes Hotlinking. Erlaubt sind JPEG/PNG/WebP bis 2 MB und 16 Megapixel. Private Netzwerkadressen, aktive Inhalte und unsichere Dateiprotokolle sind gesperrt. Alternativ bleibt der Datei-Upload verfügbar. Diese manuelle Google-Suche benötigt keinen API-Key und keine Google-Login-Konfiguration.

## Installation durch Kopieren

1. PHP **8.2 oder neuer** aktivieren. Pflicht: JSON, Session, Filter, Hash, OpenSSL, Standardfunktionen inklusive `getimagesizefromstring`, `flock`, `rename`. Für Import zusätzlich cURL und DOM/libxml; mbstring und intl sind optional. GD/Imagick sind nicht nötig.
2. Anwendung in einen frischen Webordner kopieren, z. B. `/` oder `/mywish/`. Entwicklungsordner `.git`, `tests`, `.github` und `docs` müssen nicht hochgeladen werden. `src`, `assets`, `auth` und `vendor` gehören zur Anwendung.
3. PHP muss `storage/` lesen/schreiben können. Möglichst nur dem PHP-Benutzer Rechte geben (Ordner 0700 bzw. passende Gruppenrechte; niemals pauschal 0777). Lokales Dateisystem mit funktionierendem `flock`/atomarem `rename` nutzen, kein ungetestetes NFS.
4. **Empfohlen:** Daten außerhalb des öffentlichen Webroots speichern. `config.example.php` nach `config.php` kopieren und `storage` auf einen vorhandenen, beschreibbaren Ordner außerhalb des Webroots setzen. Ohne eigene Konfiguration liegt Storage in `storage/`; die Dateien tragen einen PHP-Abbruchschutz, zusätzlich schützt dort `.htaccess`.
5. Im Browser über **HTTPS** `index.php` öffnen (alternativ `setup.php`). Site-Namen, erstes normales Konto und Registrierungsmodus festlegen. Setup wird atomar abgeschlossen und danach gesperrt. Bei offener Registrierung können andere sich selbst Konten anlegen. Bei geschlossener Registrierung bleibt es bei vorhandenen Konten.
6. Eine Liste und einen Wunsch erstellen. Mit einem zweiten Konto die Reservierung prüfen. Der Eigentümer darf davon nichts sehen.

Keine Rewrite-Regeln nötig. Der Basis-Pfad wird aus dem ausgeführten PHP-Skript abgeleitet; alle Links/Assets/Formulare funktionieren auch in Unterordnern. Beim Serverwechsel keine URLs in den Daten ändern. HTTPS-Erkennung nutzt `HTTPS` vom Webserver; hinter einem Reverse Proxy dort korrekt setzen, nicht beliebigen Forwarded-Headern vertrauen.

**Vor öffentlicher Freigabe:** Installation nur selbst erreichbar machen (z. B. Webspace-Verzeichnisschutz), Setup abschließen, dann Schutz entfernen. Die erste Person mit Zugriff auf eine frische Installation kann sie initialisieren. PHP-Ausführung muss für `.php` überall sichergestellt sein. Ein Server, der PHP-Quelltext ausliefert, ist grundsätzlich unsicher.

### PHP-Einstellungen

- `file_uploads=On`, `upload_max_filesize=2M`, `post_max_size=4M` oder größer. Die App begrenzt einzelne Bilder zusätzlich auf 2 MB, 6000 px pro Seite und 16 Megapixel.
- Funktionierender, nicht öffentlich erreichbarer PHP-Session-Speicher. Sessions laufen nach 2 Stunden Inaktivität ab. Cookie: HttpOnly, SameSite=Lax, bei HTTPS Secure.
- `display_errors=Off`; technische Fehlerantworten enthalten keine Stacktraces. Serverlogs schützen. `memory_limit` passend zum Datenvolumen wählen (z. B. 128M oder mehr).
- Ausgehendes Netz ist optional. cURL-Import nutzt direkte, DNS-gepinnte öffentliche IPv4-Verbindungen; wenn der Hoster das blockiert, manuell erfassen. Es gibt keine externen Browser-Tracker, CDNs oder Hotlink-Bilder.

### Apache und Nginx

Apache: Standard-PHP-Hosting genügt. `.htaccess` schützt Storage zusätzlich, ist aber keine Voraussetzung des Dateischutzes. Optional auch `/src/`, `/tools/` und `/tests/` sperren bzw. nicht deployen (außer `src`).

Nginx: PHP-FPM für vorhandene `.php`-Dateien konfigurieren (`try_files $uri =404`, korrekter `SCRIPT_FILENAME`). Den angepassten Speicherpfad zusätzlich sperren, z. B. bei Unterordnerbetrieb:

```nginx
location ^~ /mywish/storage/ { deny all; }
location ^~ /mywish/src/ { deny all; }
location ^~ /mywish/tests/ { deny all; }
location ^~ /mywish/tools/ { deny all; }
```

Die bestehenden PHP-FPM-Regeln des Hosters beibehalten. Kein pauschales `location ^~ /mywish/` anlegen, das PHP-Ausführung verhindert. `storage/state.php` muss bei direktem Aufruf 404/403 liefern und darf **keine** Daten enthalten.

## Backup, Wiederherstellung, Update

Es gibt bewusst keinen Browser-Admin und keinen Datendownload aller Konten. Der Betreiber sichert über den Webspace-Dateimanager/SFTP:

1. Für eine konsistente Sicherung `storage/state.php` als **einzelne Datei** herunterladen; dank atomarem Ersetzen enthält sie einen vollständigen Stand. Bei einem separaten Datenordner dort sichern. Bilder sind eingebettet; zusätzliche Medienkopien sind nicht nötig. `config.php` separat sichern.
2. Sicherung vertraulich behandeln: Sie enthält Anzeigenamen, Benutzernamen, Passworthashes und Reservierungen. Passwörter werden nie im Klartext gespeichert. Passworthashes sind dennoch sensible Daten.
3. Wiederherstellung zuerst in einem **frischen, zugriffsgeschützten zweiten Ordner** mit derselben App-Version testen: gesicherte Datei als `storage/state.php` kopieren, keine Einrichtung ausführen. PHP-Abbruchzeile nicht entfernen. Vorhandene Installation nicht versehentlich überschreiben.
4. Optional lokal `php tools/check-backup.php /pfad/zur/state.php` ausführen. Schema-/Referenz-/Mengenfehler und beschädigtes JSON werden abgewiesen. Auch ohne CLI wird beim ersten Aufruf vollständig validiert. Eine beschädigte Datei wird nie automatisch durch leere Daten ersetzt.
5. Erst nach erfolgreicher Prüfung und **bewusster Bestätigung durch den Betreiber**: Zugriff während Restore sperren, aktuellen Stand separat sichern, wiederhergestellte Datei per Dateimanager hochladen und umbenennen. Sessions am Hoster invalidieren oder neue Installation/Verzeichnis verwenden. Restore kann sonst ältere Kontopasswörter/Sitzungsversionen zurückbringen.

Updates: Zuerst Backup, dann Anwendungscode kopieren; **`storage/` und `config.php` erhalten**. Die Google-Erweiterung nutzt Schema 2. Ein gültiger Schema-1-Stand wird beim ersten Zugriff unter Lock erst gesichert und danach migriert; Details und Rollback in [Google-Anmeldung](docs/GOOGLE_LOGIN.md). Unbekannte ältere/neuere Schema-Versionen führen zu einer verständlichen Sperre. Code-Update und Datenstand getrennt halten. Serverwechsel: Code, Konfiguration und `state.php` mitnehmen; der Basis-Pfad wird neu erkannt.

Registrierungsmodus ändern: In `config.php` `'registration' => 'open'` oder `'closed'` setzen; `null` verwendet die ursprüngliche Setup-Einstellung. Die Änderung greift serverseitig sofort und benötigt keine Änderung der JSON-Daten. Kein Einladungsmodus in V1. Es gibt keinen E-Mail-Passwortreset; bei Passwortverlust zunächst eine noch aktive Sitzung verwenden oder der Betreiber ersetzt offline den Hash durch einen lokal mit `password_hash` erzeugten Hash und erhöht `sessionVersion` (niemals Klartext in der Datei). Niemand erhält dadurch eine Adminrolle.

## Verhaltensregeln und Grenzen

- Reservieren bedeutet **keinen Kauf**. Eine Person reserviert höchstens eine Einheit desselben Wunsches. Bei Menge 3 können drei verschiedene Nutzer reservieren.
- Listen-/Wunschlöschung entfernt zugehörige Reservierungen immer atomar. Mengenänderungen setzen **alle** Reservierungen dieses Wunsches zurück; die Regel gilt unabhängig davon, ob welche existieren. Eigentümer erhalten dadurch keine Statushinweise. Titel-/Preisänderungen behalten Reservierungen bei.
- Der Überraschungsschutz gilt für das Eigentümerkonto. Absichtliche Zweitkonten können bei offener Registrierung fremde Ansichten verwenden. Gegenüber dem Serverbetreiber ist Speicherung nicht anonym.
- Alle Listen sind für jedes angemeldete Konto sichtbar. Deshalb nur in einer vertrauenswürdigen Gruppe betreiben; keine vertraulichen Gesundheits-/Adressdaten eintragen.
- Amazon.de: Produkt-Titel, sichtbare Produktmerkmale, aktueller Preis und Hauptbild werden gezielt ausgelesen; Streich-/Empfehlungspreise und Platzhalterbilder bleiben außen vor. Tracking-/Mobil-Links mit ASIN werden vereinheitlicht. Bei 503, Captcha oder fehlenden Daten erscheint eine klare manuelle Vorschau statt einer vermeintlich erfolgreichen Übernahme. Bereits ausgefüllte Felder bleiben erhalten, Bilder eines vorherigen Imports werden nicht übernommen.
- Import ist Best-Effort, keine Preis- oder Verfügbarkeitsgarantie. Manche Shops blockieren automatisches Abrufen. V1 importiert nur öffentliche IPv4-Ziele auf Port 80/443; keine privaten Netze, SVGs oder aktiven Inhalte. Bilder werden lokal geprüft und gespeichert, nicht extern im Browser geladen.
- Kleine private Installation: Ein JSON-State inklusive Base64-Bilder wird pro Anfrage geladen/validiert; keine Zusage für große Communities oder riesige Bildbestände.
- Kontolöschung entfernt aktuelle Daten, nicht alte Betreiber-Backups. Betreiber bestimmt Speicherfrist und löscht abgelaufene Sicherungen.

## Entwicklung und Prüfungen

Nur für Entwicklung, keine Voraussetzung auf dem Webspace:

```sh
php -S 127.0.0.1:8080
php tests/unit.php
php tests/import.php
python3 tests/integration.py
# anderer PHP-Pfad: PHP_BIN=/pfad/php python3 tests/integration.py
```

Integrationstests kopieren die App in temporäre frische Root-/Unterordnerinstallationen und starten einen PHP-Testserver mit vier Workern (Linux/macOS). Produktionsdaten bleiben unberührt. PHP 8.2/8.3/8.4 wird in GitHub Actions geprüft.

Optionale Browserprüfung mit Node, Playwright, Chromium und axe-core:

```sh
npm install --no-save playwright axe-core
npx playwright install chromium
node tests/browser.cjs
node tests/install-banner.cjs
```

Browsertests erstellen eine isolierte Kopie und prüfen Einrichtung, Listen-/Wunschformulare, Reservieren, no-JS, Tastaturzugriff, axe und Overflow bei 320/360/768/1440 px. Screenshots landen in `tests/.artifacts/`. Siehe [Release-Nachweise](docs/RELEASE.md), [Sicherheitsmodell](docs/SECURITY.md), [API](docs/API.md).

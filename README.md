# MyWish 🎁

Eine kleine, selbst gehostete Wunschwelt für Freunde und Familie. PHP 8.2+, HTML, CSS, Vanilla JavaScript und private JSON-Dateien und separate Bilddateien. Keine Datenbank, kein Composer, kein Node, kein Cron und kein SMTP im Betrieb.

## V1

- Browser-Erstinstallation, Registrierung, Login, Abmelden, eigenes Konto und Passwort ändern, Konto löschen. Optional [mit Google anmelden](docs/GOOGLE_LOGIN.md), einschließlich ausdrücklicher Kontoverknüpfung.
- Neue Passwörter: mindestens 8 Zeichen, maximal 72 UTF-8-Bytes. Sonderzeichen sind optional. Bestehende Passwörter bleiben gültig.
- Jede Liste hat einen **Teilen**-Knopf mit öffentlichem Link auf Basis ihrer zufälligen ID. Empfänger sehen nur diese Liste ohne Anmeldung; zum Reservieren führt der Link zur Anmeldung und danach zurück. Auf Handys wird die native Teilen-Funktion genutzt, sonst der Link kopiert.
- Alle angemeldeten Nutzer sehen alle Listen. Genau ein Eigentümer bearbeitet eine Liste und ihre Wünsche. Andere Nutzer können eine Einheit pro Wunsch reservieren und ihre Reservierung wieder freigeben.
- Der Eigentümer erhält **keine Reservierungsinformationen**, weder in HTML noch in JSON. Es gibt keine Adminrolle, privaten Listen oder Kinderprofile. Das V1-Grundmodell aus [Epic #3](https://github.com/JuergenSu/MyWish/issues/3) wird um öffentliche Teilen-Links ergänzt.
- Listen mit Anlass, Datum, Beschreibung und Cover; Wünsche mit optionalem Shoplink, Bild, EUR-Preis und Menge (1–99).
- Optionaler Produktimport mit Vorschau (OpenGraph, Twitter Cards, JSON-LD, Produktgalerien und Amazon.de-Produktfelder). Eingaben prüfen und explizit speichern; ein manueller Wunsch funktioniert immer.
- Meine Reservierungen, Suche über Listen/Anzeigenamen, mobile Oberfläche und Formulare ohne JavaScript.
- Installationsbanner auf dem Handy: Android-Installationsdialog oder iPhone-Anleitung für den Home-Bildschirm; App-Manifest und lokale Icons, auch im Unterordner.

## Auf dem Handy installieren

Nach der Einrichtung erscheint oben in der mobilen Browseransicht ein Hinweis. Unter Android öffnet **Hinzufügen** den Installationsdialog, sobald der Browser die App als installierbar meldet. Andernfalls zeigt **So geht’s** den Weg über das Browsermenü. Auf dem iPhone: **Teilen → Zum Home-Bildschirm → Hinzufügen** (bei fehlendem Menüeintrag in Safari öffnen).

Für die Installation ist HTTPS nötig; localhost funktioniert für Entwicklung ebenfalls. Im installierten App-Modus bleibt das Banner verborgen. Schließen blendet es auf diesem Browser für sieben Tage aus; der optionale lokale Speicher enthält dafür nur einen Zeitstempel pro Installationspfad. Browser und Betriebssystem bestimmen, ob und wie eine Installation angeboten wird, und benötigen die Bestätigung des Nutzers.

`manifest.webmanifest`, `sw.js` und `assets/icons/` mitkopieren. Der Service Worker verwendet ausschließlich das Netzwerk und legt **keine Offline-Kopien** privater Daten an. Die App benötigt eine Internetverbindung. Login, Berechtigungen und Sicherheitsheader gelten unverändert. Ohne JavaScript oder bei normalen unverschlüsselten HTTP-Seiten bleibt der Installationshinweis ausgeblendet.

Auf dem Startbildschirm erscheint das weiße Geschenk auf lila Hintergrund: Android nutzt die Manifest-Icons mit 192/512 px, iPhone das eigene Apple-Touch-Icon mit 180 px. Falls bereits eine ältere Verknüpfung mit einem anderen Icon besteht, diese entfernen und die App erneut zum Startbildschirm hinzufügen.

## Fehlendes Bild ergänzen

Optional kann MyWish fehlende Produktbilder mit der [OpenAI-Bildsuche](docs/OPENAI_IMAGES.md) finden. API-Key und Modell werden in `config.php` unter `openai` hinterlegt; ein leerer Key deaktiviert die Funktion. Nach einem erfolglosen Import startet mit JavaScript eine zweite Suchanfrage. Der Button **Bild mit OpenAI suchen** funktioniert auch ohne JavaScript. Bild und Quellenlink werden erst beim Speichern des Wunsches dauerhaft übernommen.

Die Suche darf bei fehlendem exaktem Treffer auch ähnliche Produkte oder allgemeine Beispielbilder verwenden. Gibt die API nur Quellseiten zurück, versucht MyWish daraus Bilder auszulesen. Bitte den Bildvorschlag prüfen; Marke, Farbe oder Modell können abweichen.

Beim Linkimport sucht MyWish zuerst auf der Produktseite: in strukturierten Produktdaten (auch JSON-LD-Graphen und ImageObjects), OpenGraph/Twitter-Metadaten, Microdata, markierten Produktgalerien, `picture`/`srcset` und verzögert geladenen Bildern. Unterstützt werden außerdem ausdrücklich markierte Produkt-JSON-Daten und `__NEXT_DATA__.props.pageProps.product`. Erkennbare Platzhalter, Trackingbilder und Empfehlungsgalerien werden übersprungen. Ist ein Bild defekt, zu groß oder in einem ungeeigneten Format, versucht MyWish weitere Bildadressen: maximal fünf Bildversuche und insgesamt 25 Sekunden für Seite und Bilder. Pro Abruf bleiben höchstens 10 Sekunden einschließlich Weiterleitungen.

Bei Amazon.de-Produktlinks wird als letzter Bildversuch die Artikelnummer (ASIN) verwendet: `https://m.media-amazon.com/images/P/ASIN.01.L.jpg`. Das funktioniert gegebenenfalls auch bei gesperrter Produktseite, ohne API-Key oder Suchdienst. Dafür werden einer der fünf Bildversuche und fünf Sekunden des Gesamtbudgets reserviert. Die Datei muss die übliche Bildprüfung bestehen und zusätzlich mindestens 64 Pixel pro Seite haben; winzige Platzhalter werden verworfen. Das Bild wird lokal als Vorschau gespeichert und erst beim Speichern des Wunsches übernommen. Bitte prüfen, ob das vorgeschlagene Bild zum Artikel passt. Titel und Preis werden dadurch nicht ermittelt; fehlende Produktdaten bleiben entsprechend gekennzeichnet. Liefert der Bildlink kein brauchbares Bild, bleibt die manuelle Ergänzung möglich.

Die direkte ASIN-Bildadresse liefert nicht für jeden Artikel ein Produktbild. Amazon kann trotz HTTP 200 lediglich ein unsichtbares 1×1-Pixel-GIF zurückgeben, auch wenn die Adresse auf `.jpg` endet. MyWish benennt diesen Platzhalter jetzt ausdrücklich; ein HTTP-Fehler beim Bildabruf wird mit Statuscode angezeigt. Ist zugleich die Produktseite gesperrt, lässt sich aus der Artikelnummer allein kein echtes Bild ermitteln. In diesem Fall eine direkte Bildadresse vom Hersteller oder ein eigenes Bild ergänzen.

Shopseiten dürfen bis **12 MB nach Dekomprimierung** groß sein (zuvor 4 MB); einzelne Bilder weiterhin bis 2 MB. Einzelne Skriptblöcke über 2 MB werden vor der HTML-Auswertung übersprungen, damit spätere Produktdaten lesbar bleiben. Es werden keine Skripte ausgeführt und keine zusätzlichen Suchdienste benötigt. Shops, die ihre Produktdaten erst nach JavaScript-Ausführung liefern oder den Abruf mit Captcha/403/503 blockieren, können weiterhin manuelle Ergänzungen erfordern. Eine größere Größenfreigabe umgeht solche Sperren nicht.

Die manuelle Google-Bildersuche ist entfernt. Eine bekannte direkte Bildadresse lässt sich weiterhin über **Bildadresse → Bild übernehmen** laden. Die optionale OpenAI-Bildsuche bleibt verfügbar.

**Eigene Bilder und Handy-Screenshots:** JPEG, PNG und WebP bis **8 MB**, 6000 Pixel pro Seite und 16 Megapixel. Neue Uploads und importierte Bilder werden mit GD serverseitig ohne Zuschnitt auf höchstens **1200 Pixel an der längsten Seite** verkleinert; kleine Bilder werden nicht vergrößert. PNG-Transparenz und die EXIF-Ausrichtung von Handy-JPEGs bleiben erhalten. Nur die Vorschau wird gespeichert, das Original verworfen. Bei besonders großen PNG-Vorschauen wird WebP verwendet, damit die bestehende Speichergrenze von 2 MB je Bild eingehalten wird. Bereits gespeicherte Bilder bleiben erhalten. Für Downloads per Produkt-/Bildimport gelten weiterhin 2 MB pro Bild; private Netzwerkziele und aktive Inhalte bleiben gesperrt.

## Installation durch Kopieren

1. PHP **8.2 oder neuer** aktivieren. Pflicht: JSON, Session, Filter, Hash, OpenSSL, Standardfunktionen inklusive `getimagesizefromstring`, `flock`, `rename`. Für Import zusätzlich cURL und DOM/libxml; mbstring und intl sind optional. Für neue Bilder muss **GD mit JPEG-, PNG- und WebP-Unterstützung** aktiviert sein; Imagick ist nicht nötig. Ohne GD bleiben vorhandene Bilder lesbar, neue Uploads melden den fehlenden Hosting-Baustein.
2. Anwendung in einen frischen Webordner kopieren, z. B. `/` oder `/mywish/`. Entwicklungsordner `.git`, `tests`, `.github` und `docs` müssen nicht hochgeladen werden. `src`, `assets`, `auth` und `vendor` gehören zur Anwendung.
3. PHP muss `storage/` lesen/schreiben können. Möglichst nur dem PHP-Benutzer Rechte geben (Ordner 0700 bzw. passende Gruppenrechte; niemals pauschal 0777). Lokales Dateisystem mit funktionierendem `flock`/atomarem `rename` nutzen, kein ungetestetes NFS.
4. **Schema 4:** Echte JSON- und Bilddateien liegen in einem privaten Ordner **außerhalb des Webroots**. Standardmäßig wählt MyWish einen Ordner neben dem DocumentRoot oder `storage/data-v4`, wenn `storage` bereits privat liegt. Falls dort Schreibrechte fehlen, in `config.php` `data` auf einen privaten absoluten Pfad setzen. Der bisherige `storage`-Ordner behält geschützte Verweise, Migrationsbackups, Sitzungen und Suchcaches. [Konzept, Migration und Hosting](docs/STORAGE.md).
5. Im Browser über **HTTPS** `index.php` öffnen (alternativ `setup.php`). Site-Namen, erstes normales Konto und Registrierungsmodus festlegen. Setup wird atomar abgeschlossen und danach gesperrt. Bei offener Registrierung können andere sich selbst Konten anlegen. Bei geschlossener Registrierung bleibt es bei vorhandenen Konten.
6. Eine Liste und einen Wunsch erstellen. Mit einem zweiten Konto die Reservierung prüfen. Der Eigentümer darf davon nichts sehen.

Keine Rewrite-Regeln nötig. Der Basis-Pfad wird aus dem ausgeführten PHP-Skript abgeleitet; alle Links/Assets/Formulare funktionieren auch in Unterordnern. Beim Serverwechsel keine URLs in den Daten ändern. HTTPS-Erkennung nutzt `HTTPS` vom Webserver; hinter einem Reverse Proxy dort korrekt setzen, nicht beliebigen Forwarded-Headern vertrauen.

**Vor öffentlicher Freigabe:** Installation nur selbst erreichbar machen (z. B. Webspace-Verzeichnisschutz), Setup abschließen, dann Schutz entfernen. Die erste Person mit Zugriff auf eine frische Installation kann sie initialisieren. PHP-Ausführung muss für `.php` überall sichergestellt sein. Ein Server, der PHP-Quelltext ausliefert, ist grundsätzlich unsicher.

### PHP-Einstellungen

- `file_uploads=On`, **`upload_max_filesize=8M`**, **`post_max_size=10M`** oder größer. Die mitgelieferte `.user.ini` setzt diese Werte bei CGI/FPM-Hostern, sofern der Hoster sie zulässt; andernfalls im Hosting-Panel ändern. PHP-Konfigurationscaches können Änderungen verzögert übernehmen. Die App begrenzt einzelne Uploads auf 8 MB und ganze Anfragen auf 10 MB. Größere Bilder werden auf 1200 Pixel verkleinert; 6000 px pro Quellseite und 16 Megapixel begrenzen den Dekodieraufwand.
- Sitzungen bleiben **14 Tage seit der letzten Nutzung** gültig, auch nach Schließen des Browsers. Jede Anfrage erneuert Cookie und Frist. Geschützte Sitzungsdateien liegen unter `storage/sessions/` beziehungsweise im konfigurierten Datenordner; PHP muss diesen Unterordner anlegen und beschreiben dürfen. Die eigene Bereinigung ist unabhängig vom kürzeren PHP-Standardlimit des Hosters. Cookie: HttpOnly, SameSite=Lax, bei HTTPS Secure. Abmelden, Passwortwechsel und Kontolöschung behalten ihre sofortige Wirkung.
- `display_errors=Off`; technische Fehlerantworten enthalten keine Stacktraces. Serverlogs schützen. `memory_limit` passend zum Datenvolumen wählen (z. B. 128M oder mehr).
- Ausgehendes Netz ist optional. cURL-Import nutzt direkte, DNS-gepinnte öffentliche IPv4-Verbindungen; wenn der Hoster das blockiert, manuell erfassen. Es gibt keine externen Browser-Tracker, CDNs oder Hotlink-Bilder.

### Apache und Nginx

Apache: Standard-PHP-Hosting genügt. `.htaccess` schützt Storage zusätzlich, ist aber keine Voraussetzung des Dateischutzes. Optional auch `/src/`, `/tools/` und `/tests/` sperren bzw. nicht deployen (außer `src`).

Nginx: PHP-FPM für vorhandene `.php`-Dateien konfigurieren (`try_files $uri =404`, korrekter `SCRIPT_FILENAME`). Für Uploads `client_max_body_size 10m;` im passenden Server-/Location-Block erlauben. Den angepassten Speicherpfad zusätzlich sperren, z. B. bei Unterordnerbetrieb:

```nginx
location ^~ /mywish/storage/ { deny all; }
location ^~ /mywish/src/ { deny all; }
location ^~ /mywish/tests/ { deny all; }
location ^~ /mywish/tools/ { deny all; }
```

Die bestehenden PHP-FPM-Regeln des Hosters beibehalten. Kein pauschales `location ^~ /mywish/` anlegen, das PHP-Ausführung verhindert. `storage/state.php` muss bei direktem Aufruf 404/403 liefern und darf **keine** Daten enthalten.

## Backup, Wiederherstellung, Update

Das neue **Schema 4** trennt kleine JSON-Sammlungen und echte Bilddateien. Ein atomarer Manifestwechsel aktiviert Änderungen gemeinsam. Listen-/Kontoabfragen lesen keine Bildbytes; nur geänderte JSON-Sammlungen werden neu geschrieben. Identische Bilder werden dedupliziert. [Dateiaufbau und Details](docs/STORAGE.md).

Beim ersten Zugriff werden gültige alte Schemata 1–3 unter Lock und nach bytegenauem Backup migriert. Der neue Datenbestand wird vollständig geprüft, bevor die alte Datei abgelöst wird. Große Bestände können per CLI mit Prüflauf und höherem Speicherlimit migriert werden:

```sh
php -d memory_limit=512M tools/migrate-storage.php --storage=/alter/storage --data=/privater/datenordner --web-root=/public_html --dry-run
php -d memory_limit=512M tools/migrate-storage.php --storage=/alter/storage --data=/privater/datenordner --web-root=/public_html
```

Neue Backups umfassen **JSON-Dateien und Bilder**, nicht nur `state.php`:

```sh
php tools/backup-storage.php --storage=/storage --target=/privater/neuer-sicherungsordner --web-root=/public_html
php tools/check-backup.php /privater/neuer-sicherungsordner
```

Ohne CLI während der vollständigen Verzeichniskopie Schreibzugriffe stoppen. `config.php` separat sichern; Sitzungen und Suchcaches nicht übernehmen. Wiederherstellung zuerst in einer frischen geschützten Installation mit `data` auf die private Sicherung prüfen. Alte `state.php`-Sicherungen werden vom Prüfer weiterhin unterstützt. Ein Rollback benötigt alten Code und die passende alte Sicherung zusammen; neue Änderungen sind darin nicht enthalten. [Vorgehen und Wiederanlauf nach Abbruch](docs/STORAGE.md).

Bei Updates `storage/`, `config.php` und den privaten Datenordner erhalten. Die Migration bewahrt IDs, Konten, Google-Verknüpfungen und bestehende Sitzungsversionen. Die einmalige Neuanmeldung beim früheren Wechsel auf 14-Tage-Sitzungen gilt nicht erneut für diese Speichermigration.

Registrierungsmodus ändern: In `config.php` `'registration' => 'open'` oder `'closed'` setzen; `null` verwendet die ursprüngliche Setup-Einstellung. Die Änderung greift serverseitig sofort und benötigt keine Änderung der JSON-Daten. Kein Einladungsmodus in V1. Es gibt keinen E-Mail-Passwortreset; bei Passwortverlust zunächst eine noch aktive Sitzung verwenden oder der Betreiber ersetzt offline über eine `Store::transaction` den Hash durch einen lokal mit `password_hash` erzeugten Hash und erhöht `sessionVersion` (niemals Klartext speichern; versionierte JSON-Dateien nicht direkt bearbeiten). Niemand erhält dadurch eine Adminrolle.

## Verhaltensregeln und Grenzen

- Reservieren bedeutet **keinen Kauf**. Eine Person reserviert höchstens eine Einheit desselben Wunsches. Bei Menge 3 können drei verschiedene Nutzer reservieren.
- Listen-/Wunschlöschung entfernt zugehörige Reservierungen immer atomar. Mengenänderungen setzen **alle** Reservierungen dieses Wunsches zurück; die Regel gilt unabhängig davon, ob welche existieren. Eigentümer erhalten dadurch keine Statushinweise. Titel-/Preisänderungen behalten Reservierungen bei.
- Der Überraschungsschutz gilt für das Eigentümerkonto. Absichtliche Zweitkonten können bei offener Registrierung fremde Ansichten verwenden. Gegenüber dem Serverbetreiber ist Speicherung nicht anonym.
- Alle Listen sind für jedes angemeldete Konto und über ihren jeweiligen Teilen-Link sichtbar. Wer einen Link erhält, kann ihn weitergeben. Es gibt keinen gesonderten Freigabeschalter; Löschen der Liste macht den Link ungültig. Deshalb nur in einer vertrauenswürdigen Gruppe betreiben; keine vertraulichen Gesundheits-/Adressdaten eintragen.
- Amazon.de: Produkt-Titel, sichtbare Produktmerkmale, aktueller Preis und Hauptbild werden gezielt ausgelesen; Streich-/Empfehlungspreise und Platzhalterbilder bleiben außen vor. Tracking-/Mobil-Links mit ASIN werden vereinheitlicht. Bei 503, Captcha oder fehlenden Daten erscheint eine klare manuelle Vorschau statt einer vermeintlich erfolgreichen Übernahme. Bereits ausgefüllte Felder bleiben erhalten, Bilder eines vorherigen Imports werden nicht übernommen.
- Import ist Best-Effort, keine Preis- oder Verfügbarkeitsgarantie. Manche Shops blockieren automatisches Abrufen. V1 importiert nur öffentliche IPv4-Ziele auf Port 80/443; keine privaten Netze, SVGs oder aktiven Inhalte. Bilder werden lokal geprüft und gespeichert, nicht extern im Browser geladen.
- Kleine private Installation: JSON-Metadaten werden als Sammlungen gelesen; Bilder werden einzeln geladen. Keine Zusage für große Communities oder sehr große Metadatenbestände.
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

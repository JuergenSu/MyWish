# V1 – Umfang und Abnahme

Stand: 8. November 2026. Maßgeblich ist [V1-Epic #3](https://github.com/JuergenSu/MyWish/issues/3). Anforderungen älterer Detailtickets zu Admin, Kinderprofilen, ACLs, Gästen, Freigaben oder privaten Listen gehören ausdrücklich nicht zu dieser V1.

## Implementierung

| Tickets | Ergebnis |
|---|---|
| #4, #11 | Copy-Deployment, PHP-Einstiegspunkte ohne Rewrite, Subdirectory-URLs, guarded JSON, separates flock, temporäre Datei + fsync + atomarer Rename, striktes Schema 1 |
| #12, #5 | Atomar gesperrtes Browser-Setup mit erstem normalen Konto, offene/geschlossene Registrierung, sichere Sessions, getrennte Rate Limits, eigene Kontoverwaltung und Löschung |
| #8, #9 | Listen-/Wunsch-CRUD, EUR-Cents statt Floats, optionale Shoplinks, Cover, Stückzahl und serverseitige Eigentümerprüfung |
| #10, #13 | Korrigierbare Importvorschau, OG/JSON-LD/HTML, SSRF-Abwehr, optionale sichere Bilder und authentifizierte Medienauslieferung ohne GD-Pflicht |
| #20, #14 | Atomare Einzelreservierungen bis zur Wunschmenge, 409-Konflikte, Freigeben, Meine Reservierungen, keine Reservierungsfelder für Eigentümer, progressive Aktualisierung ohne Reload |
| #16, #15, #21 | Zentrale Übersicht aller Listen, Suche, Empty States, lokales Designsystem, mobile Navigation, Tastatur/Skip-Link, semantische native Formulare ohne Dialog-Fokusprobleme |
| #22, #19 | Einheitliche JSON-Antworten, Rechte/CSRF/Validierung, Escape/CSP, kein Rohdatendownload, keine externen Browserressourcen, dokumentiertes Sicherheitsmodell |
| #23, #17 | Kopierinstallation, Updates, portables Betreiber-Backup/Restore, Offline-Prüfer, reproduzierbare PHP-/HTTP-/Browser-Tests und CI-Matrix |

Backup/Restore wird gemäß Epic durch den Betreiber über Webspace-Dateizugriff erledigt. Ein Admin-Export-/Restore-Endpoint würde das bewusst rollenlose Produktmodell verletzen und ist nicht enthalten.

## Lokal ausgeführte automatisierte Nachweise

Linux, PHP 8.4.24, Chromium, Playwright und axe-core:

- `php tests/unit.php`: 76 Prüfungen (Passwortregel mit 8 Zeichen und Sonderzeichen einschließlich Unicode-/Grenzfällen, Centpreise, URLs, Bildsignaturen, OG/JSON-LD, reservierte IP-Bereiche, defekte/neue Schema-Versionen, Schreibabbruch, 20 parallele Storage-Schreiber).
- `php tests/import.php`: 52 deterministische Import-/Transportprüfungen (DNS-Pinning, Proxy/Redirect-Policy, öffentliche Ziele, Redirect zu privat/Loopback, Übergröße, Redirect-Limit, Transportfehler und Shop-403). Transport ist hier simuliert; kein Nachweis gegen einen bestimmten echten Händler.
- `python3 tests/integration.py`: 398 HTTP-Prüfungen. Frische Kopie unter `/` und `/mywish/`, Anmeldung, zwei Listen, Menge 1/3, zehn konkurrierende unabhängige Sessions, Rechteverletzungen, Owner-Snapshot ohne Spoiler, XSS-Escaping, ungültige Inputs/CSRF, echter Multipart-Upload, MIME-Spoof-Ablehnung, Passwortvalidierung bei Setup/Registrierung/Änderung, Kompatibilität bestehender Passwörter, Sitzungsversion, Rate Limit, Kontolöschung, Datendirektzugriff, Backup-Roundtrip, beschädigter/readonly Storage, Registrierungs-Override und zwei gleichzeitige Setup-Anfragen.
- `php tests/unit.php`: 76 Prüfungen (Passwortregel mit mindestens 8 Zeichen ohne Sonderzeichenpflicht einschließlich Unicode-/Grenzfällen, Centpreise, URLs, Bildsignaturen, OG/JSON-LD, reservierte IP-Bereiche, defekte/neue Schema-Versionen, Schreibabbruch, 20 parallele Storage-Schreiber).
- `php tests/import.php`: 19 deterministische Transportprüfungen (DNS-Pinning, Proxy/Redirect-Policy, öffentliche Ziele, Redirect zu privat/Loopback, Übergröße, Redirect-Limit, Transportfehler und Shop-403). Transport ist hier simuliert; kein Nachweis gegen einen bestimmten echten Händler.
- `python3 tests/integration.py`: 372 HTTP-Prüfungen. Frische Kopie unter `/` und `/mywish/`, Anmeldung, zwei Listen, Menge 1/3, zehn konkurrierende unabhängige Sessions, Rechteverletzungen, Owner-Snapshot ohne Spoiler, XSS-Escaping, ungültige Inputs/CSRF, echter Multipart-Upload, MIME-Spoof-Ablehnung, Passwortvalidierung bei Setup/Registrierung/Änderung, Kompatibilität bestehender Passwörter, Sitzungsversion, Rate Limit, Kontolöschung, Datendirektzugriff, Backup-Roundtrip, beschädigter/readonly Storage, Registrierungs-Override und zwei gleichzeitige Setup-Anfragen.
- `node tests/browser.cjs`: Einrichtung/CRUD/Bildupload ohne JavaScript; Reservieren/Freigeben mit JavaScript; Skip-Link und lokale Ressourcen. Screenshots, Overflow und axe für Setup, Login, Wunschformular, Dashboard, Liste, Reservierungen und Konto bei 320/360/768/1440 px. 32 Seiten-/Viewport-Audits, keine WCAG-A/AA/2.1-AA-Verstöße im automatisierten Check.
- Amazon-Regressionen: synthetische Fixtures für aktuellen/älteren Produktaufbau, deutsche Preise, Hauptbild-Auswahl, Captcha, 503 und große HTML-Antworten; tatsächliche Formularübernahme und manuelles Speichern bei Ausfall in Root/Subdir getestet. Der Nutzerlink `https://www.amazon.de/Orzly-Lenkrader-Kompatibel-Joy-Geschenkbox/dp/B0FLM3M1JJ/` lieferte beim Live-Abruf am 8. November 2026 HTTP 503, auch als kanonische `/dp/`-URL. Daher kein Nachweis eines erfolgreichen Live-Imports dieses Produkts und keine Zusage zur Umgehung von Amazon-Sperren.
- PHP-Syntaxprüfung für sämtliche PHP-Dateien.
- `node tests/install-banner.cjs`: Banner und manuelle Android-/iPhone-Anleitungen, simuliertes `beforeinstallprompt` mit Annahme/Ablehnung erst nach Nutzerklick, `appinstalled`, Standalone-/Desktop-/No-JS-Unterdrückung, Schließen/Ablauf nach sieben Tagen und gesperrter lokaler Speicher. Root-/Unterordner-Manifest, PNG-Icons, Service-Worker-Scope, leeres Cache Storage, axe und Layout bei 320/360/768 px geprüft. Der native OS-Dialog wird im Test simuliert; ein physischer Android-/iPhone-Installationsnachweis steht noch aus.

Keine Testkonten, Demo-State oder Bilder werden mit dem Anwendungscode ausgeliefert. Screenshots/Logs werden lokal bzw. in CI als Artefakte erzeugt, nicht im Produktions-Storage.

## Vor Livebetrieb auf dem konkreten Webspace

Diese externen Prüfungen wurden in der Entwicklungsumgebung nicht ausgeführt; sie bleiben Teil der Abnahme des Zielhostings:

- [ ] PHP-Version/Erweiterungen, PHP-FPM/Apache-PHP, sichere Sessions und HTTPS prüfen.
- [ ] Root und Unterordner auf tatsächlichem Apache-/Nginx-Webspace installieren. Direktzugriff auf `storage/state.php` und temporäre PHP-Dateien blockiert; PHP wird niemals als Quelltext ausgeliefert.
- [ ] Auf physischem Android/iPhone sowie mit Tastatur und Screenreader prüfen. Chromium-Viewporttests ersetzen keine echten Mobilgeräte.
- [ ] Händlerimport an den konkret benötigten Shops prüfen; manuelle Eingabe bei Netzsperre/Blockierung bleibt verfügbar.
- [ ] Betreiber-Backup herunterladen, auf zweiter geschützter Instanz testen, vor produktivem Restore aktuellen Stand sichern und Sessions invalidieren.
- [ ] Dateirechte, `display_errors=Off`, Serverlogs, Backup-Aufbewahrung und Registrierungsmodus prüfen. Setup vor öffentlicher Freigabe abschließen.

Die GitHub-Actions-Matrix für PHP 8.2/8.3/8.4 und Chromium ist eingerichtet; ihr Status muss nach Push geprüft werden. Lokal belegt ist PHP 8.4.24.

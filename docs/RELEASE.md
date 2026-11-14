# V1 – Umfang und Abnahme

Stand: 14. November 2026. Maßgeblich ist [V1-Epic #3](https://github.com/JuergenSu/MyWish/issues/3). Admin, Kinderprofile, ACLs und private Listen gehören weiterhin nicht zur V1. Öffentliche Ansichten über den Teilen-Link wurden auf ausdrücklichen Wunsch ergänzt.

## Implementierung

| Tickets | Ergebnis |
|---|---|
| #4, #11 | Copy-Deployment, PHP-Einstiegspunkte ohne Rewrite, Subdirectory-URLs, private JSON-Sammlungen und separate Bilder, gemeinsame Datensperre, temporäre Dateien + fsync + atomarer Manifestwechsel, Schema 4 |
| #12, #5 | Atomar gesperrtes Browser-Setup mit erstem normalen Konto, offene/geschlossene Registrierung, sichere Sessions, getrennte Rate Limits, eigene Kontoverwaltung und Löschung |
| #8, #9 | Listen-/Wunsch-CRUD, EUR-Cents statt Floats, optionale Shoplinks, Cover, Stückzahl und serverseitige Eigentümerprüfung |
| #10, #13 | Korrigierbare Importvorschau, OG/JSON-LD/HTML, SSRF-Abwehr, sichere, mit GD verkleinerte Bilder und listenbezogen geschützte Medienauslieferung |
| #20, #14 | Atomare Einzelreservierungen bis zur Wunschmenge, 409-Konflikte, Freigeben, Meine Reservierungen, keine Reservierungsfelder für Eigentümer, progressive Aktualisierung ohne Reload |
| #16, #15, #21 | Zentrale Übersicht aller Listen, Suche, Empty States, lokales Designsystem, mobile Navigation, Tastatur/Skip-Link, semantische native Formulare ohne Dialog-Fokusprobleme |
| #22, #19 | Einheitliche JSON-Antworten, Rechte/CSRF/Validierung, Escape/CSP, kein Rohdatendownload, keine externen Browserressourcen, dokumentiertes Sicherheitsmodell |
| #23, #17 | Kopierinstallation, Updates, portables Betreiber-Backup/Restore, Offline-Prüfer, reproduzierbare PHP-/HTTP-/Browser-Tests und CI-Matrix |

Backup/Restore wird gemäß Epic durch den Betreiber über Webspace-Dateizugriff erledigt. Ein Admin-Export-/Restore-Endpoint würde das bewusst rollenlose Produktmodell verletzen und ist nicht enthalten.

## Lokal ausgeführte automatisierte Nachweise

Linux, PHP 8.4.24, Chromium, Playwright und axe-core:

- `php tests/import.php`: 107 deterministische Import-/Transportprüfungen (DNS-Pinning, Proxy/Redirect-Policy, öffentliche Ziele, Redirect zu privat/Loopback, Übergröße, Redirect-Limit, Transportfehler und Shop-403). Transport ist hier simuliert; kein Nachweis gegen einen bestimmten echten Händler.
- `python3 tests/integration.py`: 557 HTTP-Prüfungen. Frische Kopie unter `/` und `/mywish/`, Anmeldung, zwei Listen, Menge 1/3, zehn konkurrierende unabhängige Sessions, Rechteverletzungen, Owner-Snapshot ohne Spoiler, XSS-Escaping, ungültige Inputs/CSRF, echter Multipart-Upload, MIME-Spoof-Ablehnung, Passwortvalidierung bei Setup/Registrierung/Änderung, Kompatibilität bestehender Passwörter, Sitzungsversion, Rate Limit, Kontolöschung, Datendirektzugriff, Backup-Roundtrip, beschädigter/readonly Storage, Registrierungs-Override und zwei gleichzeitige Setup-Anfragen.
- `php tests/unit.php`: 93 Prüfungen (Passwortregel mit mindestens 8 Zeichen ohne Sonderzeichenpflicht einschließlich Unicode-/Grenzfällen, Centpreise, URLs, Bildsignaturen, OG/JSON-LD, reservierte IP-Bereiche, defekte/neue Schema-Versionen, Schreibabbruch, 20 parallele Storage-Schreiber).
- `node tests/browser.cjs`: Einrichtung/CRUD/Bildupload ohne JavaScript; Reservieren/Freigeben mit JavaScript; Skip-Link und lokale Ressourcen. Screenshots, Overflow und axe für Setup, Login, Wunschformular, Dashboard, Liste, Reservierungen und Konto bei 320/360/768/1440 px. 48 Seiten-/Viewport-Audits, keine WCAG-A/AA/2.1-AA-Verstöße im automatisierten Check.
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


## Optionale Google-Erweiterung

Google-Verknüpfungen ab Schema 2; aktuell Schema 4 mit privaten JSON-/Bilddateien und vorher gesicherter Schema-1-/Schema-2-/Schema-3-Migration. Passwort-Login bleibt verfügbar; Google ist ohne Konfiguration deaktiviert. Details: [GOOGLE_LOGIN.md](GOOGLE_LOGIN.md), [OPENAI_IMAGES.md](OPENAI_IMAGES.md).

- `php tests/google.php`: 43 Token-/State-/Account-/Migrationsprüfungen, einschließlich Algorithmusverwechslung, falschem Issuer/Audience/Nonce, Ablaufzeit, unbestätigter E-Mail, Replay, Google-only-Dummy-Hash-Abwehr und bytegleichem Vorab-Backup.
- `python3 tests/google-http.py`: 376 HTTP-Prüfungen für komplette Callback-/Verknüpfungs-/Reauthentifizierungsabläufe in Root und Unterordner; keine implizite E-Mail-Verknüpfung, geschlossene Registrierung, Profil/Passwort/Trennen/Löschen und geschützter JWK-Cache.
- `node tests/google-browser.cjs`: Login, Registrierung, Kontoverknüpfung, frische Bestätigung und lokale Passwortanlage mit echten lokalen Redirect-/Callback-Seiten; Google-Transport simuliert. Der appseitige Start funktioniert als natives Formular auch ohne JavaScript; Googles eigene Oberfläche kann JavaScript benötigen. 15 axe-/Overflow-Audits für Google-Seiten bei 360/768/1440 px.

Diese Provider-Tests benötigen keine echten Credentials. Der Live-Abnahmeschritt mit der tatsächlichen Google-Cloud-Konfiguration und Domain steht noch aus.

- Direkte Bildadressen (Google-Suchlink entfernt): Root-/Unterordner-Formularübernahme einer Bildadresse, lokales Speichern/Ausliefern, SVG-/Privatnetz-/Übergrößen-Ablehnung und Erhalt manueller Wunschfelder geprüft. Kein Google-Bildsuch-API-Zugang und kein Scraping erforderlich.

- Teilen-Link: begrenzte öffentliche Listen-/Medienansicht, Schutz fremder Bilder und Sitzungs-Vorschauen, identische anonyme Ansicht vor/nach Reservierung, Login-/Registrierungs-/Google-Rücksprung sowie 404 nach Löschen in Root und Unterordner geprüft. Browser: native Teilen-Funktion und Zwischenablage mit Test-Doubles, ohne JavaScript lesbarer Link und Anmeldung über Reservieren, offene Teilen-Felder bei 320/360/768/1440 px.
- Bilder: echter 8-MB-Multipart-Upload ohne JavaScript, serverseitige 1200-Pixel-Vorschau, Seitenverhältnis und beide Bildränder, PNG-Transparenz, JPEG/WebP, kleine Bilder ohne Vergrößerung, beschädigte Dateien und Upload über 8 MB geprüft. Hosting benötigt jetzt GD; `.user.ini` setzt 8M/10M bei unterstützten Hostern.

- `php tests/sessions.php`: 19 Prüfungen für geschützte Sitzungsdateien, Dateirechte, unbekannte IDs, 13/14 Tage Inaktivität, Bereinigung unabhängig von kürzeren PHP-Fristen, Locks und eine wartende Anfrage nach Logout. HTTP-Prüfungen sichern persistente Cookies mit HttpOnly/SameSite und korrektem Unterordnerpfad, Browser-Neustart, Verlängerung, Ablauf, direkten Dateischutz und Logout-Replay ab. Für das Update ist einmaliges erneutes Anmelden erforderlich.


## Getrennte Datenspeicherung

- `php tests/storage.php`: 111 Prüfungen für Migration der Schemata 1–3 mit bytegenauem Backup und unveränderten Bildbytes, Konten und Reservierungen; Deduplizierung, idempotenter Wiederanlauf, Schreibfehler vor Commit, beschädigte JSON-/Bilddateien, sichere Ablage außerhalb des Webroots, CLI-Prüflauf/Migration/Backup/Prüfer und Bereinigung geprüft.
- `php -d memory_limit=512M tests/storage-performance.php`: zwölf synthetische PNGs, ungefähr 29 MB alter JSON-Bestand; aktuelle Metadaten ungefähr 9 KB. Speicher und reine Lesezeit werden getrennt gemessen; keine Behauptung über vollständige Seitenladezeiten. [Messwerte und Grenzen](STORAGE.md#messung-und-grenzen).
- HTTP-Verträge einschließlich zehn konkurrierender Reservierungen, Eigentümer-/Gastschutz, Bilder, Root-/Unterordnerbetrieb und Backup-Wiederherstellung werden mit dem neuen Speicher ausgeführt. Direkte URLs dürfen keine rohen JSON-/Bilddateien ausliefern.

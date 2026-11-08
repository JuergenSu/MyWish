# Sicherheits- und Datenschutzmodell V1

## Vertrauensgrenzen

Ein normales Konto ist entweder Eigentümer oder Schenkender im Kontext einer Liste. Alle eingeloggten Konten sehen jede Liste. Keine separaten Rollen/ACLs/Share-Tokens. Der Betreiber und sein Hosting haben Dateizugriff und sind vertrauenswürdig. Fremde Shops, Formulareingaben und Uploads sind nicht vertrauenswürdig. Eine frische Installation wird vor öffentlich möglicher Fremdeinrichtung per Hosting-Zugriffsschutz gesichert.

| Bedrohung | Umsetzung / Prüfung |
|---|---|
| IDOR / Rechteausweitung | Owner-ID kommt ausschließlich aus Session. CRUD und Reservierungen prüfen Eigentümer serverseitig unter Lock. Fremde Mutationen 403. HTTP-Tests. |
| Überraschung verraten | Snapshot baut Wunschfelder je Eigentümer auf. Keine Reservierungsfelder oder fremden Nutzer-IDs/Namen für Eigentümer, keine statusabhängigen Löschmeldungen. Vergleich identischer Owner-Snapshots nach Reserve/Unreserve. |
| Gleichzeitige Reservierung | Gemeinsamer Lock für Lesen, Mengenprüfung und Schreiben; atomarer Rename. Test: zehn unabhängige Sessions, genau ein bzw. drei Gewinner. |
| CSRF / Session Fixation | Zufallstoken, POST, HttpOnly/SameSite-Cookie, Secure bei HTTPS, strict_mode und ID-Wechsel bei Login/Logout/Passwortwechsel. Inaktivitätslimit und Sitzungsversion. |
| Brute Force | Zehn erfolglose Logins je gehashter IP in 15 Minuten, 30 Registrierungen separat; Ablaufbereinigung bei Login/Registrierung. Keine Passwort-/Tokenlogs. NAT kann mehrere Nutzer betreffen. |
| XSS | Zentrales HTML-Escaping, URL-Scheme-Liste, kein innerHTML, lokale Skripte/CSS, CSP ohne unsafe-inline, kein SVG-Upload. Test mit aktiven HTML-Strings. |
| SSRF / DNS Rebinding | Nur HTTP(S), keine Credentials/Sonderports. DNS-A-Ziele auf öffentliche IPv4 prüfen, konkret per CURLOPT_RESOLVE pinnen, Proxy deaktiviert, jeden Redirect erneut prüfen (max. 3). Kein Wechsel des aufgelösten Ziels beim Verbinden. Keine IPv6-/Übergangstunnel. Zeitlimit, Größenlimit und TLS-Prüfung. |
| Fremde Bilder / Upload | Signatur und Dimensionen prüfen, kein Vertrauen in Browser-MIME/Dateiname. Bytes in geschütztem State, feste MIME-Auslieferung, nosniff. Keine frei wählbaren Pfade, nur echte PHP-Uploads. Bilder nie als Code eingebunden. |
| Rohdaten-Download | `.php`-Dateien mit ausführbarem 404/exit vor JSON; separate .htaccess als Zusatz. Tempdateien ebenfalls `.php` mit Guard. Storage außerhalb Webroot empfohlen. Direktzugriff getestet ohne Rewrite/.htaccess. |
| Fehler und Datenverlust | JSON-/Schema-/Referenzprüfung, unbekannte Version sperrt, temporär schreiben + flush/fsync + rename unter separatem Lock. Abbruch vor Commit erhält alte Bytes. Einheitliche Fehler ohne absolute Pfade/Stacktraces. |
| Backup / Restore | Nur vertrauenswürdiger Betreiber per SFTP/Dateimanager. Vor Wiederherstellung neue Instanz prüfen und aktuellen Stand sichern; kein öffentlicher Restore-Endpunkt. |

## Grenzen

Das Startbildschirm-Banner verwendet nur einen lokalen Ausblend-Zeitstempel. Manifest und App-Icons sind öffentlich und enthalten keine Kontodaten. Der Service Worker liegt im jeweiligen Installationspfad, reicht Anfragen unverändert an das Netzwerk weiter und speichert keine Seiten, APIs oder Medien im Offline-Cache. Installation gewährt keine zusätzlichen Rechte; Anmeldung und serverseitige Zugriffskontrollen bleiben nötig.

Keine Garantie gegen absichtliche Zweitkonten, bösartigen Betreiber, kompromittiertes PHP-Hosting oder Analyse von Betriebssystem-/HTTP-Timing. Anmeldung, CSRF, Mengenprüfung und atomare Transaktionen verhindern die normalen V1-Zugriffsfehler. TLS-Terminierung, Session-Speicher, Dateirechte, Backupfristen und Serverlogs liegen beim Betreiber. Keine Telemetrie, externen Webfonts oder Tracking-Skripte. Import kontaktiert einen Shop erst auf ausdrücklichen Button-Klick serverseitig.

Die Tests ersetzen kein unabhängiges Penetration Testing. Echte Apache-/Nginx-Hostingkombinationen und mobile Safari müssen vor dem jeweiligen Livebetrieb anhand der Release-Checkliste geprüft werden.


## Optionale Google-Anmeldung

OAuth/OIDC-Codefluss mit PKCE, CSRF-geschütztem POST-Start, einmaligem Session-State und Nonce. Signierte ID-Tokens werden mit der mitgelieferten JWT-Bibliothek auf feste Google-Issuer, RS256, Audience, Zeitgrenzen und bestätigte E-Mail geprüft. Die dauerhafte Zuordnung nutzt die Google-Subject-ID; keine automatische Verknüpfung anhand der E-Mail. Verknüpfen verlangt die bisherige Authentifizierung und ist an User-ID/Sitzungsversion gebunden. Google-only-Konten müssen für Änderungen/Löschung frisch dasselbe Google-Konto bestätigen. Trennen ohne lokale Anmeldemöglichkeit ist gesperrt. Der Dummy-Hash zum zeitlich einheitlichen Passwortvergleich darf niemals eine Anmeldung bei einem Google-only-Konto ermöglichen.

Keine Access-/Refresh-/ID-Tokens in Storage oder Logs; nur ein geschützter öffentlicher JWK-Cache. Ohne Nutzerklick keine Google-Anfragen im Browser. Die CSP erlaubt bei konfiguriertem Provider lediglich die notwendige Formularweiterleitung nach `accounts.google.com`; Skripte und Bilder bleiben lokal. [Einrichtung und Nachweise](GOOGLE_LOGIN.md).

Die manuelle Google-Bildersuche übermittelt erst beim bewussten externen Link-Klick den sichtbaren Titel und gegebenenfalls die ASIN, keine vollständigen Shop-/Session-URLs. Suchtreffer werden nicht automatisch ausgewählt. Die anschließend eingegebene direkte Bildadresse durchläuft dieselben öffentlichen DNS-/Redirect-/MIME-/Größenprüfungen wie Shop-Bilder und wird nur lokal ausgeliefert.

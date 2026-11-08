# JSON-API

`api/index.php?action=…`, keine Rewrite-Regeln. `GET session` liefert Einrichtungs-/Registrierungsstatus und ein Session-CSRF-Token; `GET state` liefert den angemeldeten Benutzer (ohne Hash), alle Listen und Wünsche. Unangemeldete erhalten 401, vor Setup nur `installed:false`.

Änderungen ausschließlich `POST`, JSON (`Content-Type: application/json`) oder Formular. CSRF über `X-CSRF-Token` oder Formularfeld `csrf`, Session-Cookie mitsenden. Token nach Login/Logout erneuern (jede Antwort liefert `csrf`). Keine CORS-Freigabe.

Erfolg: `{"ok":true,"data":{…},"csrf":"…"}`. Fehler: `{"ok":false,"error":{"message":"…"},"csrf":"…"}`. 200 Änderung, 201 Anlage, 400 defektes JSON, 401 Anmeldung nötig, 403 Rechte/CSRF, 404 Objekt, 405 Methode, 409 Konflikt, 413 Requestgröße, 422 Eingaben, 429 Rate Limit, 500 interner Fehler, 503 Speicher/Schema/Hostingfehler. Startupfehler können noch kein CSRF enthalten.

| Aktion | Felder |
|---|---|
| setup | siteName, name, username, password, registration=open/closed |
| register | name, username, password |
| login | username, password |
| logout | – |
| account | name, currentPassword, newPassword optional |
| deleteAccount | currentPassword, confirm=yes |
| saveList | id optional, name, occasion, eventDate optional ISO, description optional, cover=lavender/peach/mint/sky |
| deleteList | id, confirm=yes |
| saveWish | id optional, listId, title, description optional, price optional (39,99), productUrl optional, quantity=1…99 |
| deleteWish | id, confirm=yes |
| reserve / unreserve | id (Wunsch) |
| importImage | imageUrl (direkte HTTP(S)-Bilddatei), productUrl optional; geprüfte Vorschau mit hasImage |
| import | productUrl; liefert unverbindliche Vorschau title/description/price/productUrl/hasImage |

Bei Listen/Wünschen optional Multipart-Upload `image` (JPEG/PNG/WebP, maximal 8 MB); `removeImage=yes` entfernt ein Bild. `useImportedImage=yes` übernimmt das zur selben Sitzung gehörende Vorschau-Bild (30 Minuten). Kein Base64-Upload und keine frei wählbaren Dateipfade in der API. Bilddaten werden über `media.php?type=lists|wishes&id=…` ausgeliefert, mit Login und festem MIME; Snapshot enthält lediglich `image:true/false`.

`import` liefert zusätzlich `warning` (leer bei vollständiger Vorschau). Bei Shop-Ausfall oder Amazon-Prüfseite bleibt eine manuell ergänzbare Vorschau mit Link verfügbar; dies wird ausdrücklich als Warnung angezeigt. Sicherheitsfehler wie private Ziele oder ungültige Protokolle bleiben 4xx. Amazon.de-Produktlinks werden auf `https://www.amazon.de/dp/ASIN` normalisiert; Titel aus dem URL-Pfad sind bei blockiertem Abruf nur ein gekennzeichneter Vorschlag.

Neue Passwörter bei `setup`, `register` und `account.newPassword` benötigen mindestens **8 Zeichen**. Maximal 72 UTF-8-Bytes; keine Pflicht zu Sonderzeichen, Großbuchstaben oder Ziffern. Bestehende Passwörter bleiben beim Login und als `currentPassword` gültig. Ein leeres `newPassword` behält das bisherige Passwort.

`state.wishes`: Bei fremden Listen zusätzlich `available` und `reservedByMe`; bei eigenen Listen fehlen diese Felder vollständig. Es gibt keine API für die Reservierungstabelle, fremde Kontodaten, Nutzerlisten oder Eigentümerübertragung. IDs ersetzen keine Rechteprüfung. Alle Eigentümerprüfungen und Reservierungen laufen in derselben Storage-Transaktion.


Google-Anmeldung ist kein frei aufrufbarer Login-API-Token: `POST auth/google/start.php` mit `csrf`, `purpose=login|link|reauthenticate` und bei Verknüpfung dem aktuellen Passwort startet die Weiterleitung. `GET auth/google/callback.php` verarbeitet nur den gebundenen einmaligen OAuth-State und Code. Client-Secret/ID-Token gehören niemals in Browser-API-Payloads. `account` und `deleteAccount` verlangen bei Google-only-Konten eine höchstens fünf Minuten alte Google-Reauthentifizierung statt eines lokalen Passworts. `POST unlinkGoogle` benötigt ein bestehendes lokales Passwort als `currentPassword`.

Die eigene `state.user`-Antwort enthält zusätzlich `hasLocalPassword`, `googleLinked` und die eigene `googleEmail` (oder null). Google-Subject, Passworthash und Provider-Tokens werden nicht ausgeliefert. Andere Nutzer sehen nur den Anzeigenamen des Eigentümers. Einrichtung: [GOOGLE_LOGIN.md](GOOGLE_LOGIN.md).

`importImage` benötigt Anmeldung und CSRF wie der Produktimport. Die Bildadresse wird mit denselben DNS-/Redirect-/Größenprüfungen geladen und anhand der Bytes als JPEG/PNG/WebP validiert. Erst `saveWish` mit `useImportedImage=yes` übernimmt sie dauerhaft. Der frühere Link zur manuellen Google-Bildersuche wurde entfernt.


## Öffentliche Listenansicht und Vorschauen

`GET index.php?page=share&id=LISTEN_ID` zeigt ohne Anmeldung genau eine Liste. Die bestehende zufällige 128-Bit-ID ist der Zugang zum geteilten Inhalt, es gibt keine öffentliche Listenübersicht oder zusätzliche JSON-API. Angezeigt werden Listen-/Wunschdaten und der Anzeigename, keine Kontodaten oder Reservierungsstände. `noindex, nofollow` und `no-store` verhindern reguläre Suchindexierung bzw. Cache-Kopien. Der Link ist weitergebbar und bleibt bis zum Löschen der Liste gültig.

Öffentliche Medien benötigen zusätzlich `&share=LISTEN_ID`; der Server prüft, dass Wunsch oder Cover zu genau dieser Liste gehört. `type=preview` und der vollständige `state` bleiben an die Anmeldung gebunden. Änderungen benötigen weiterhin Anmeldung und CSRF. Reservieren führt für Gäste zu `page=login&returnList=LISTEN_ID`; nach Passwort-Login, Registrierung oder Google-Login geht es zur Liste zurück. Eine Reservierung wird erst durch erneuten bewussten Klick ausgeführt. Rücksprünge erlauben ausschließlich gültige Listen-IDs, keine externen URLs.

Neue Bilder werden per GD dekodiert, ohne Zuschnitt auf maximal 1200 Pixel an der längsten Seite skaliert und neu kodiert. PNG-Transparenz bleibt erhalten; kleine Bilder werden nicht hochskaliert. Maximal 2 MB Vorschau werden im bisherigen Datenformat gespeichert, detailreiche PNGs bei Bedarf als WebP. Uploadlimit 8 MB, Requestlimit 10 MB, Quellbildlimit 6000 Pixel/Seite und 16 Megapixel; Import-Downloads weiterhin 2 MB. PHP benötigt GD und passende `upload_max_filesize`-/`post_max_size`-Werte. Fehlende GD-Unterstützung wird mit 503, beschädigte bzw. zu große Bilder mit 422 gemeldet.

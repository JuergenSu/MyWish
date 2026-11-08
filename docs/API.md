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
| import | productUrl; liefert unverbindliche Vorschau title/description/price/productUrl/hasImage |

Bei Listen/Wünschen optional Multipart-Upload `image` (JPEG/PNG/WebP); `removeImage=yes` entfernt ein Bild. `useImportedImage=yes` übernimmt das zur selben Sitzung gehörende Vorschau-Bild (30 Minuten). Kein Base64-Upload und keine frei wählbaren Dateipfade in der API. Bilddaten werden über `media.php?type=lists|wishes&id=…` ausgeliefert, mit Login und festem MIME; Snapshot enthält lediglich `image:true/false`.

`import` liefert zusätzlich `warning` (leer bei vollständiger Vorschau). Bei Shop-Ausfall oder Amazon-Prüfseite bleibt eine manuell ergänzbare Vorschau mit Link verfügbar; dies wird ausdrücklich als Warnung angezeigt. Sicherheitsfehler wie private Ziele oder ungültige Protokolle bleiben 4xx. Amazon.de-Produktlinks werden auf `https://www.amazon.de/dp/ASIN` normalisiert; Titel aus dem URL-Pfad sind bei blockiertem Abruf nur ein gekennzeichneter Vorschlag.

Neue Passwörter bei `setup`, `register` und `account.newPassword` benötigen mindestens **8 Zeichen und ein Sonderzeichen** (Unicode-Satzzeichen oder -Symbol, z. B. `!`, `?`, `#`). Leerzeichen allein gelten nicht als Sonderzeichen. Maximal 72 UTF-8-Bytes; keine Pflicht zu Großbuchstaben oder Ziffern. Bestehende Passwörter bleiben beim Login und als `currentPassword` gültig. Ein leeres `newPassword` behält das bisherige Passwort.

`state.wishes`: Bei fremden Listen zusätzlich `available` und `reservedByMe`; bei eigenen Listen fehlen diese Felder vollständig. Es gibt keine API für die Reservierungstabelle, fremde Kontodaten, Nutzerlisten oder Eigentümerübertragung. IDs ersetzen keine Rechteprüfung. Alle Eigentümerprüfungen und Reservierungen laufen in derselben Storage-Transaktion.

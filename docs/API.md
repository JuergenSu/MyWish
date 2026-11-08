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

`state.wishes`: Bei fremden Listen zusätzlich `available` und `reservedByMe`; bei eigenen Listen fehlen diese Felder vollständig. Es gibt keine API für die Reservierungstabelle, fremde Kontodaten, Nutzerlisten oder Eigentümerübertragung. IDs ersetzen keine Rechteprüfung. Alle Eigentümerprüfungen und Reservierungen laufen in derselben Storage-Transaktion.

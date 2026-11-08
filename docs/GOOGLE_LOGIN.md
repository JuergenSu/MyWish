# Mit Google anmelden

Google-Anmeldung ist optional. Die normale Passwortanmeldung funktioniert weiterhin. Bestehende Konten werden nur nach einer ausdrücklichen Verknüpfung verbunden; eine übereinstimmende E-Mail-Adresse reicht dafür nicht.

## 1. Google Cloud Console

1. Ein Projekt auswählen oder erstellen und **Google Auth Platform** öffnen.
2. **Branding**: App-Name „MyWish“, Support-E-Mail und Entwickler-Kontaktadresse hinterlegen. Für die spätere öffentliche Veröffentlichung die von Google angeforderten App-/Domain-/Datenschutzhinweise ergänzen.
3. **Audience / Zielgruppe**: normalerweise **Extern** wählen. Im Testmodus alle zugelassenen Google-Konten als **Testnutzer** eintragen. Bei einer internen Workspace-Anwendung kann „Intern“ passend sein.
4. **Data Access / Datenzugriff**: nur `openid`, `email`, `profile` verwenden. Keine Drive-, Kalender- oder Gmail-Berechtigungen nötig.
5. **Clients → Create client → Webanwendung** wählen.
6. Die **autorisierte Weiterleitungs-URI** exakt eintragen:
   - Webroot: `https://DEINE-DOMAIN/auth/google/callback.php`
   - Unterverzeichnis: `https://DEINE-DOMAIN/mywish/auth/google/callback.php`
7. Client-ID und Client-Secret sicher aufbewahren. JavaScript-Ursprünge sind für diesen serverseitigen Redirect-Ablauf nicht erforderlich. Kein Servicekonto und kein API-Key nötig.

Die echte URL inklusive HTTPS, Unterverzeichnis und Dateiname muss genau stimmen. Für unterschiedliche Installationen jeweils die entsprechende URI eintragen. `localhost`/`127.0.0.1` mit HTTP ist nur für lokale Entwicklung vorgesehen. Im Testmodus können ausschließlich die freigegebenen Testkonten anmelden. Vor öffentlichem Einsatz Veröffentlichungsstatus und etwaige Google-Anforderungen im eigenen Projekt prüfen.

## 2. Auf dem Webspace konfigurieren

`config.example.php` nach `config.php` kopieren bzw. die bestehende Konfiguration ergänzen. Bestehende Storage-/Registrierungseinstellungen erhalten:

```php
'google' => [
    'client_id' => 'DEINE-CLIENT-ID.apps.googleusercontent.com',
    'client_secret' => 'DEIN-CLIENT-SECRET',
    'redirect_uri' => 'https://DEINE-DOMAIN/mywish/auth/google/callback.php',
],
```

Alternativ das Secret über `MYWISH_GOOGLE_CLIENT_SECRET` bereitstellen, wie in der Beispielkonfiguration gezeigt. Das Secret gehört ausschließlich in die geschützte Serverkonfiguration bzw. Serverumgebung, niemals in JavaScript, Git, Issues oder Chat. `config.php` ist von Git ausgeschlossen. Dateizugriff auf den PHP-Benutzer begrenzen; PHP-Quelltext darf der Webserver nicht ausliefern.

Alle drei Werte müssen gesetzt sein. Wenn der Hoster PHP-Dateien dauerhaft per OPcache zwischenspeichert, nach Konfigurationsänderungen den PHP-Prozess bzw. OPcache über die Hosting-Einstellungen neu laden. Zusätzlich braucht PHP **cURL und OpenSSL**, funktionierende CA-Zertifikate und ausgehendes HTTPS zu `oauth2.googleapis.com` und `www.googleapis.com`. Der Browser wird nach einem Klick zu `accounts.google.com` weitergeleitet. Ohne vollständige Konfiguration bleibt der Google-Knopf ausgeblendet. Composer/Node/CLI auf dem Webspace sind nicht erforderlich; die JWT-Bibliothek ist mitgeliefert.

## 3. Anmelden und bestehende Konten verknüpfen

- **Neues Konto:** Auf der Login-/Registrierungsseite „Mit Google anmelden“ wählen. Bei offener Registrierung wird ein normales MyWish-Konto angelegt; es benötigt zunächst kein lokales Passwort.
- **Bereits vorhandenes Konto:** Zuerst mit dem bisherigen Passwort anmelden. Unter **Mein Konto → Google-Konto** das aktuelle Passwort bestätigen und „Google-Konto verknüpfen“ wählen. Listen, Wünsche und Kontonummer bleiben erhalten. Verwendet ein neues Google-Konto bereits eine bestehende Benutzerkennung, wird die automatische Neuanlage abgewiesen und auf diesen Verknüpfungsweg hingewiesen.
- **Geschlossene Registrierung:** Bereits verknüpfte Konten können sich weiterhin anmelden; neue Google-Konten werden nicht angelegt.
- **Google-only-Konto bearbeiten/löschen:** Unter Mein Konto „Mit Google erneut bestätigen“ wählen. Nach frischer Google-Bestätigung sind Änderungen für fünf Minuten möglich. Dabei kann auch ein lokales Passwort festgelegt werden.
- **Trennen:** Erst ein lokales Passwort festlegen und anschließend beim Trennen bestätigen. So bleibt eine Anmeldemethode erhalten. Andere Sitzungen werden bei Änderungen der Anmeldemethode ungültig.

Alle angemeldeten Nutzer sehen weiterhin alle Listen; Eigentümer-/Schenkendenrechte und Überraschungsschutz ändern sich nicht. Google erhält keine Listen oder Reservierungen. Gespeichert werden nur Googles stabile Konto-ID, bestätigte E-Mail-Adresse und der Anzeigename; keine Access-/Refresh-/ID-Tokens und keine Profilbilder. Andere Nutzer erhalten die Google-ID oder E-Mail-Adresse nicht über die Listenansicht.

## 4. Update und Datenmigration

Vor dem Update wie üblich ein Betreiber-Backup anlegen und Code **einschließlich `vendor/` und `auth/`** hochladen. `storage/` und `config.php` erhalten.

Google-Verknüpfungen wurden mit Schema 2 eingeführt. Die aktuelle Version nutzt **Schema 3** für Bildquellen und migriert gültige Schema-1-/Schema-2-Daten unter Lock nach vorherigem vollständigem Backup. Nutzer-IDs, Passworthashes, Google-Verknüpfungen, Listen, Wünsche und Reservierungen bleiben erhalten; bei Schema-1-Konten werden leere Google-Felder ergänzt. Schlägt die Sicherung fehl, wird die Migration nicht geschrieben. Details zum aktuellen Datenstand: [OpenAI-Bildsuche](OPENAI_IMAGES.md#update-und-backup).

Neuere Schemaversionen sind für ältere App-Versionen nicht lesbar. Für ein Rollback vorherige App-Version und zugehöriges Backup gemeinsam wiederherstellen. Automatische Migrationsbackups wie andere Sicherungen vertraulich behandeln und nach der Aufbewahrungsfrist löschen. Google-only-Nutzer sollten ein lokales Passwort festlegen, bevor der Betreiber Google dauerhaft deaktiviert.

## Technische Absicherung und Tests

Authorization Code Flow mit PKCE S256, einmaliger und zehn Minuten gültiger Session-State, Nonce und fester Callback-Konfiguration. Start nur per POST mit CSRF. Der Callback prüft Signatur/RS256, Googles Issuer, Client-ID/Audience/Authorized Party, Ablaufzeit, Nonce, stabile Subject-ID und bestätigte E-Mail. Kontoverknüpfung ist zusätzlich an die angemeldete User-ID und Sitzungsversion gebunden. Reauthentifizierung verlangt ein frisches `auth_time` für dasselbe Google-Konto. Token-/Schlüssel-Endpunkte sind fest im Servercode definiert, TLS-Prüfung aktiv, keine vom Token vorgegebenen Schlüssel-URLs.

Mitgeliefert: `firebase/php-jwt` **7.2.1**, BSD-3-Clause, upstream `googleapis/php-jwt`. Quell-Commit und SHA-256-Prüfsummen stehen in `vendor/firebase/php-jwt/SOURCE.json`; die Lizenz liegt daneben. Google-JWKs werden maximal eine Stunde in einer geschützten PHP-Datei gecacht, Tokens nicht gespeichert. Serverlogs sollten OAuth-Callback-Querys nicht unnötig aufbewahren; der Callback leitet anschließend sofort auf die App zurück.

```sh
php tests/google.php
python3 tests/google-http.py
node tests/google-browser.cjs
```

Der appseitige Start nutzt native Formulare und funktioniert ohne JavaScript; Googles eigene Oberfläche kann JavaScript benötigen.

Die Tests verwenden dynamisch erzeugte Testschlüssel und einen lokalen Google-Transport-Doppelgänger. Sie prüfen Token-Manipulationen, CSRF/State/Nonce/PKCE, Login/Verknüpfung, Reauthentifizierung, Kontoverwaltung und Schema-Migration. Ein erfolgreicher Test mit dem echten Google-Projekt ist erst nach Konfiguration der tatsächlichen Client-ID, des Secrets und der Domain möglich; er ist vor Produktivfreigabe erforderlich.

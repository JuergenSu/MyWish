# JSON-Dateien und separate Bilder – Schema 4

Die Anwendung liest im Normalbetrieb nur Metadaten. Bilder stehen als echte JPEG-, PNG- oder WebP-Dateien daneben und werden erst bei einer berechtigten Bildanfrage geöffnet. PHP, JSON und ein lokales Dateisystem reichen weiterhin aus; keine Datenbank und kein Cron sind erforderlich.

## Verzeichnisaufteilung

Alle Dateien liegen standardmäßig im kopierbaren Anwendungsordner. Versteckte Dateien mitkopieren:

```text
mywish/
  index.php
  config.php                   bestehende Konfiguration erhalten
  migrate-storage.php          einmaliger Browserlauf, löscht sich nach Erfolg
  storage/
    .htaccess                  Require all denied – Apache-Zugriffsschutz
    layout.php                 geschützter relativer Verweis: data-v4
    state.php                  kleiner Schema-4-Verweis; keine Konten/Bilder
    backup-schema-3-<hash>.php  bytegenaues ursprüngliches Backup
    protection.php             geschützter Nachweis der HTTP-Schutzprüfung
    migration-schema4.php      Abschlussmarkierung gegen erneuten Lauf
    sessions/                  bestehende geschützte 14-Tage-Sitzungen
    google-jwks.php             optionaler geschützter Schlüsselcache
    openai-search.php          geschützter Suchcache und Kostenlimits
    lock.php                   Sperre für Migration und Pfadwechsel
    migration-lock.php         serialisiert Browser-Migrationsläufe
    data-v4/
      .htaccess                zusätzliche Sperre für kopierte Datenordner
      manifest.json            aktive Generation und JSON-Verweise
      previous.json            vorherige Generation
      transaction.lock         gemeinsame Datensperre
      records/
        settings-<hash>.json    Einrichtung und Registrierung
        users-<hash>.json       Konten, Passworthashes, Google-Zuordnung
        lists-<hash>.json       Listen und Bildverweise
        wishes-<hash>.json      Wünsche und Bildverweise
        reservations-<hash>.json
        attempts-<hash>.json    Anmelde-/Registrierungslimits
      images/
        <sha256>.jpg
        <sha256>.png
        <sha256>.webp
```

Die JSON-Dateien enthalten keine Base64-Bilder. Ein Bildverweis enthält nur Dateiname, MIME-Typ, Bytezahl, Breite, Höhe und gegebenenfalls `sourceUrl`. Die SHA-256-Adresse macht Bilddateien unveränderlich und dedupliziert identische Bilder. Die Migration erhält vorhandene Bildbytes unverändert; neue Uploads werden wie bisher auf maximal 1200 Pixel verkleinert.

## Atomare Änderungen und Lesen

Ein Schreibvorgang hält eine exklusive Sperre im bisherigen Storage und im privaten Datenordner. Die Datensperre funktioniert auch, wenn zwei Einstiegspunkte denselben privaten Datenordner nutzen. Leser verwenden eine geteilte Sperre.

1. Die Anwendung validiert die Änderung einschließlich Eigentümerrechten und Reservierungsmenge.
2. Neue Bilder werden einzeln geschrieben und überprüft. Bereits vorhandene Bilder werden weiter referenziert.
3. Nur geänderte Sammlungen bekommen neue JSON-Dateien. Eine Reservierung schreibt beispielsweise keine Wunschbilder und keine Benutzerdatei neu.
4. Nach Schreiben, Flush und `fsync` wird **ein** neues `manifest.json` per atomarem Rename aktiviert. Das ist der gemeinsame Commit aller betroffenen Sammlungen.
5. Die vorherige Generation bleibt erhalten. Ältere, nicht mehr referenzierte JSON-Dateien und Bilder werden anschließend bestmöglich entfernt. Eine fehlgeschlagene Bereinigung macht einen bereits abgeschlossenen Vorgang nicht rückgängig.

Ein Abbruch vor dem Manifestwechsel lässt den bisherigen Datenstand aktiv. Ein Abbruch danach hinterlässt den neuen vollständigen Stand. Beschädigte JSON-Dateien, Prüfsummen und unbekannte Versionen führen zu einer Fehlermeldung; es gibt keinen stillen Rückfall auf eine ältere Generation, der Reservierungen verlieren könnte.

Einrichtungsabfragen lesen nur die kleine Einstellungssammlung. Listenansichten lesen die Metadaten ohne Bildbytes. `media.php` prüft wie bisher Anmeldung beziehungsweise den Listen-Teilen-Link, öffnet nur das angeforderte Bild und überprüft dessen Hash, bevor es gestreamt wird. Private Dateinamen und Pfade werden nicht im API-Snapshot veröffentlicht. Eine Importvorschau bleibt vorübergehend in der Sitzung; nach erfolgreichem Speichern oder nach 30 Minuten wird sie entfernt.

## Copy-Deployment und Webserver-Schutz

Ohne zusätzliche Konfiguration bleibt der Datenbestand unter `storage/data-v4/` **innerhalb des Webroots**. `storage/.htaccess` enthält `Require all denied`. Apache 2.4 muss diese Regel auswerten: zum Beispiel `AllowOverride AuthConfig` oder `AllowOverride All` für den App-Ordner, mit `mod_authz_core`. PHP benötigt cURL, Schreibrechte in `storage/` und für die einmalige Schutzprüfung auch im App-Ordner.

Vor dem ersten Schreiben echter JSON-/Bilddateien prüft MyWish den tatsächlichen HTTP-Schutz: Eine zufällige öffentliche Kontroll-Datei muss exakt erreichbar sein; zufällige Testdateien mit JSON-/Bild-Endungen im Storage müssen HTTP 403 liefern. Alle Testdateien enthalten nur Zufallswerte und werden wieder entfernt. Die Verbindung wird an die lokale Webserver-IP und den Serverport gebunden, ohne Proxy oder Redirects und mit TLS-Prüfung. Es werden keine fremden Ziele abgefragt. Der Server muss sich selbst erreichen können; bei vorgeschaltetem Proxy müssen `DOCUMENT_ROOT`, `SERVER_ADDR`, `SERVER_PORT` und `HTTPS` den lokalen Webserver korrekt beschreiben. Weiterleitungen auf eine andere URL verhindern die Prüfung.

Wird `.htaccess` ignoriert oder ist die Selbstverbindung unmöglich, bricht MyWish vor der Veröffentlichung roher Daten mit einer verständlichen Meldung ab. Der erfolgreiche Prüfnachweis ist an Verzeichnisse, Host und Regeln gebunden. Nach einem Umzug wird neu geprüft; geänderte oder fehlende Deny-Dateien werden zurückgewiesen. Der Migrationslauf erzwingt die HTTP-Prüfung nochmals. Spätere Änderungen an der Apache-Konfiguration müssen vom Betreiber erneut geprüft werden; der Nachweis überwacht diese Konfiguration nicht fortlaufend.

`storage/layout.php` und der Migrationsverweis speichern `data-v4` **relativ**. Für einen Umzug Schreibzugriffe stoppen und den vollständigen App-Ordner einschließlich `storage/`, Bildern und versteckten Dateien kopieren. Kein zusätzlicher Ordner außerhalb des Webroots und keine Pfadanpassung sind im Standardbetrieb nötig. Ordner erhalten 0700 und Dateien 0600; keine pauschalen 0777-Rechte vergeben. PHP muss das Migrationsskript im App-Ordner löschen können. Die App-Schreibrechte können nach abgeschlossener Prüfung reduziert werden; ein neuer Pfad oder Host erfordert sie erneut kurzzeitig.

Optional bleibt ein privater Ablageort für andere Webserver möglich:

```php
'data' => '/home/dein-konto/private/mywish-data',
```

Dessen Elternordner muss existieren und für PHP beschreibbar sein. Liegt der Datenpfad außerhalb aller veröffentlichten Verzeichnisse und Aliase, entfällt die `.htaccess`-HTTP-Prüfung. Dieser zusätzliche absolute Pfad muss bei einem Umzug separat mitkopiert und angepasst werden. Nginx und der PHP-Entwicklungsserver ignorieren `.htaccess`; für sie diesen privaten Modus nutzen. Der Standardmodus wird dort sicher abgelehnt.

## Migration bestehender Installationen

Unterstützt werden die bisherigen Schemata 1, 2 und 3. Schema 1 erhält die bisher fehlenden Google-Felder. Nutzer-IDs, Passworthashes, Google-only-Konten, Sitzungsversionen, Listen, Wünsche, Preise, Reservierungen, Anmeldezähler und Bildquellen bleiben erhalten. Die 14-Tage-Sitzungen müssen nicht wegen dieser Speichermigration erneuert werden.

Die Migration wird **ausdrücklich einmal im Browser** gestartet, ohne CLI oder Cron:

1. Schreibzugriffe stoppen und den vorhandenen Bestand zusätzlich sichern. Anwendungscode einschließlich `migrate-storage.php` und `storage/.htaccess` hochladen; `config.php` und vorhandenen `storage/` erhalten.
2. `https://deine-domain/mywish/migrate-storage.php` öffnen (im Domain-Hauptordner `/migrate-storage.php`). Ein GET zeigt nur das Formular; normale App-Aufrufe migrieren Altbestände nicht automatisch.
3. **Migration starten** wählen. Der POST benötigt das sitzungsgebundene CSRF-Token. Quell-/Zielpfade lassen sich nicht per URL oder Formular verändern. Parallele Aufrufe werden serialisiert.
4. Nach vollständiger Prüfung erscheint die Erfolgsmeldung, eine geschützte Abschlussmarkierung wird geschrieben und **das Skript löscht sich selbst**. Der Link liefert anschließend 404. Wird es bei einem späteren Code-Upload erneut mitkopiert, erkennt es den Abschluss, führt nichts erneut aus und entfernt sich wieder.

Der Browserlauf führt ausschließlich die festgelegte Schema-Umstellung aus und benötigt keine bestehende Anmeldung. Er stellt keine Daten, beliebigen Pfade oder Wiederherstellungsfunktionen öffentlich bereit. Bei Fehlern bleibt das Skript zum Wiederholen erhalten. Sollte die Selbstlöschung trotz Vorprüfung an Dateirechten scheitern, sperrt die Abschlussmarkierung einen erneuten Lauf und die Seite fordert ausdrücklich zum manuellen Löschen von `migrate-storage.php` auf.

**Bereits auf Schema 4 umgestellte Installationen:** Der Browserlauf kann auch den bisher automatisch außerhalb des Webroots angelegten Datenordner nach `storage/data-v4/` umziehen. Er kopiert unter exklusiver Datensperre den aktuellen geprüften Stand, erhält Dataset-ID und Revision und aktiviert danach den relativen Verweis. Der vorherige externe Ordner bleibt als zusätzliche Sicherung unverändert. Ein ausdrücklich gesetztes `data` in `config.php` wird respektiert; für den Umzug zum Standard dieses Feld vorher auf `null` setzen. Den bisherigen Ordner erst nach geprüfter Sicherung und erfolgreicher Nutzung entfernen. Andere Installationen dürfen während des Umzugs nicht weiter auf denselben alten Bestand schreiben.

Der technische Ablauf nach der Webserver-Prüfung:

1. Ursprüngliche Datei vollständig prüfen.
2. Ein **bytegenaues**, PHP-geschütztes Backup im bisherigen Storage anlegen und seine Prüfsumme prüfen. Wiederholte Versuche mit derselben Quelldatei verwenden dieselbe Sicherung.
3. In einem geschützten Zwischenordner alle Bilder extrahieren und die JSON-Sammlungen schreiben.
4. Referenzen, Mengen, Bildinhalt, MIME-Typ, Abmessungen und Prüfsummen vollständig prüfen.
5. Den geprüften neuen Datenordner aktivieren. Die alte `state.php` durch einen kleinen Schema-4-Verweis ersetzen und `layout.php` veröffentlichen.

Älterer Anwendungscode verweigert den Schema-4-Verweis und kann somit nicht unbemerkt in einer veralteten Kopie weiterschreiben. Ein Abbruch zwischen diesen letzten Schritten lässt sich beim nächsten Lauf aus dem Migrationsverweis fortsetzen. Ein Abbruch vor dem Umschalten erhält die bisherige Datei. Bestehende fremde Zielbestände werden nicht überschrieben.

Der Browserlauf versucht vorübergehend 512 MB Arbeitsspeicher und keine PHP-Ausführungsfrist einzustellen; Hosting- und Proxy-Limits können trotzdem gelten. Vor dem Einlesen erkennt MyWish zu wenig Speicher. Bei sehr großen Beständen das Hosting-Limit erhöhen oder optional per CLI mit einem privaten Ziel migrieren:

```sh
php -d memory_limit=512M tools/migrate-storage.php \
  --storage=/home/dein-konto/public_html/mywish/storage \
  --data=/home/dein-konto/private/mywish-data \
  --web-root=/home/dein-konto/public_html \
  --dry-run

php -d memory_limit=512M tools/migrate-storage.php \
  --storage=/home/dein-konto/public_html/mywish/storage \
  --data=/home/dein-konto/private/mywish-data \
  --web-root=/home/dein-konto/public_html
```

CLI-Werkzeuge erhalten den tatsächlichen veröffentlichten Webroot ausdrücklich über `--web-root`, damit auch außerhalb einer Webanfrage keine rohen Dateien im öffentlichen Verzeichnis angelegt werden. Der Prüflauf verändert keine Dateien. Er prüft den Quelldatenbestand, ersetzt aber keine Schreibrechteprüfung auf dem tatsächlichen Hosting. Für noch größere Bestände die Speichergrenze passend erhöhen. Temporär Platz für alte Datei, vollständiges Backup und extrahierte Bilder einplanen, grob bis zum Dreifachen der bisherigen Dateigröße. Die Werkzeuge unter `tools/` bleiben ausschließlich per CLI aufrufbar. Der gesonderte Browserlauf `migrate-storage.php` entfernt sich nach Erfolg. CLI-Zugriffe auf einen Datenordner im Webroot benötigen einen bereits vorhandenen Browser-Prüfnachweis.

Nach der Migration mindestens mit einem Eigentümer- und einem Schenkendenkonto Listen, Bilder und Reservieren prüfen. Automatische Altsicherungen zunächst behalten; nach geprüfter externer Sicherung entsprechend der eigenen Aufbewahrung vom Server entfernen.

## Backup und Wiederherstellung

Eine einzelne `state.php` reicht ab Schema 4 **nicht** mehr als Sicherung. Benötigt werden ein konsistentes `manifest.json`, dessen referenzierte JSON-Dateien und sämtliche referenzierten Bilder. `config.php` separat sichern; Sitzungen und Suchcaches nicht übernehmen.

Mit CLI wird die Anwendung während einer konsistenten Kopie über eine geteilte Sperre gegen gleichzeitige Schreibvorgänge geschützt:

```sh
php tools/backup-storage.php \
  --storage=/home/dein-konto/public_html/mywish/storage \
  --target=/home/dein-konto/private/backup-2026-11-14 \
  --web-root=/home/dein-konto/public_html

php tools/check-backup.php /home/dein-konto/private/backup-2026-11-14
```

Der Zielordner darf noch nicht existieren und muss privat sein. Das Manifest wird zuletzt geschrieben; eine unvollständige Kopie besteht die Prüfung nicht. Ohne CLI Schreibzugriffe auf die Installation während der SFTP-/Dateimanager-Kopie sperren und den vollständigen `storage/`-Ordner einschließlich `.htaccess` und `data-v4/` sichern. Eine laufende Verzeichniskopie ohne Sperre ist kein konsistentes Backup.

Zum Testen der Wiederherstellung eine frische, zugriffsgeschützte Installation derselben Version verwenden, den Sicherungsordner außerhalb ihres Webroots ablegen und in `config.php` als `data` angeben. Der frische `storage`-Ordner erhält dann den Verweis; Setup nicht erneut ausführen. Erst nach erfolgreicher Prüfung den geplanten produktiven Restore durchführen. Vorher den aktuellen Datenstand separat sichern und Sitzungen invalidieren.

Ein Rollback auf Schema-3-Code benötigt den vorherigen Code und die bytegenaue alte `backup-schema-3-<sha256>.php` als `state.php` zusammen. Änderungen seit der Migration stehen in dieser alten Sicherung nicht zur Verfügung. Deshalb vor einem Rollback auch den neuen Datenbestand sichern und Schreibzugriffe sperren. `layout.php` nicht als Altbestand weiterverwenden.

## Bereinigung

Normalerweise hält MyWish nur die aktuelle und die vorherige Metadatengeneration sowie ihre Bilder vor. Nach abgebrochenen Schreibvorgängen können unveröffentlichte Dateien übrig bleiben. Sie werden bei Bedarf offline unter exklusiver Datensperre entfernt:

```sh
php tools/cleanup-storage.php --storage=/home/dein-konto/public_html/mywish/storage --web-root=/home/dein-konto/public_html
```

Das Werkzeug prüft zuerst den aktuellen Stand. Es entfernt ausschließlich nicht referenzierte, intern benannte JSON-/Bilddateien; aktuelle und vorherige Generation sowie Migrationsbackups bleiben erhalten. Beschädigte aktive Daten werden nicht durch eine Bereinigung übergangen. Verwaiste Migrations-Zwischenordner nach einem harten Prozessabbruch liegen ebenfalls im geschützten Storage und können nach Prüfung separat entfernt werden.

## Messung und Grenzen

`php -d memory_limit=512M tests/storage-performance.php` erzeugt einen isolierten synthetischen Bestand mit zwölf gültigen 1000×600-PNGs von jeweils ungefähr 1,8 MB. Für beide Formate werden sieben reine Lese-/Validierungsläufe in separaten PHP-Prozessen gemessen; angegeben wird der Median. Es werden weder Produktionsdaten verwendet noch Bildqualität oder Netzwerkgeschwindigkeit verändert.

Lokale Messung vom 14. November 2026 mit PHP 8.4.24:

| Größe | Bisheriger Bestand | Getrennter Bestand |
|---|---:|---:|
| Für eine Metadatenabfrage gelesene Daten | 29.269.186 Byte | 9.370 Byte |
| PHP-Spitzenspeicher im Messprozess | 89.489.408 Byte | 2.097.152 Byte |
| Median Lesen und Validieren | 144,61 ms | 0,15 ms |
| Separat gespeicherte Bildbytes | in der JSON-Datei | 21.611.256 Byte |

Das sind Messwerte für diesen synthetischen Bestand mit warmem Dateicache, keine Garantie für eine vollständige Seitenladezeit auf beliebigem Hosting. Konto-/Listen-/Wunschmetadaten werden weiterhin als Sammlungen geladen, Schreibvorgänge serialisiert. Für eine kleine private Installation wird vor allem der teure globale Bildzugriff entfernt. Bei sehr vielen Metadatensätzen wäre eine weitere Aufteilung nach Liste oder eine Datenbank ein separater Ausbau.

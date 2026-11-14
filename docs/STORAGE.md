# JSON-Dateien und separate Bilder – Schema 4

Die Anwendung liest im Normalbetrieb nur Metadaten. Bilder stehen als echte JPEG-, PNG- oder WebP-Dateien daneben und werden erst bei einer berechtigten Bildanfrage geöffnet. PHP, JSON und ein lokales Dateisystem reichen weiterhin aus; keine Datenbank und kein Cron sind erforderlich.

## Verzeichnisaufteilung

Zwei Orte haben unterschiedliche Aufgaben:

```text
Bisheriges storage/ (oder config.php: storage)
  layout.php                   geschützter Verweis auf den privaten Datenordner
  state.php                    kleiner Schema-4-Migrationsverweis; keine Konten/Bilder
  backup-schema-3-<sha256>.php  bytegenaue ursprüngliche Sicherung
  sessions/                    bestehende geschützte 14-Tage-Sitzungen
  google-jwks.php               optionaler öffentlicher Schlüsselcache, PHP-geschützt
  openai-search.php            geschützter Suchcache und Kostenlimits
  lock.php                     Sperre für Migration und Pfadwechsel

Privater Datenordner außerhalb des DocumentRoot (config.php: data)
  manifest.json                aktive Generation und Verweise auf die JSON-Dateien
  previous.json                vorherige Generation
  transaction.lock             gemeinsame Datensperre
  records/
    settings-<sha256>.json      Einrichtung und Registrierung
    users-<sha256>.json         Konten, Passworthashes, Google-Zuordnung
    lists-<sha256>.json         Listen und Bildverweise
    wishes-<sha256>.json        Wünsche und Bildverweise
    reservations-<sha256>.json  Reservierungen
    attempts-<sha256>.json      Anmelde-/Registrierungslimits
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

## Privater Ablageort

Echte JSON- und Bilddateien dürfen **nicht** im öffentlich erreichbaren Webroot liegen. Diese Anforderung wird serverseitig geprüft, einschließlich aufgelöster Symlink-Pfade. Eine alleinige `.htaccess`-Datei würde bei Nginx oder dem PHP-Entwicklungsserver nicht genügen.

`storage` bleibt unverändert. Optional in der bestehenden `config.php` ergänzen:

```php
'data' => '/home/dein-konto/private/mywish-data',
```

Der übergeordnete Ordner muss existieren und für PHP beschreibbar sein. Der Zielordner darf fehlen oder leer sein. MyWish legt Verzeichnisse mit 0700 und Dateien mit 0600 an. Auf einem neuen Host muss der private Datenordner ebenfalls außerhalb aller veröffentlichten Verzeichnisse und Aliase liegen.

Ohne `data` wählt die Anwendung einen privaten Ablageort:

- Liegt `storage` bereits außerhalb des Webroots, wird `storage/data-v4` verwendet.
- Liegt `storage` im Webroot, wird neben dem DocumentRoot ein installationsspezifischer Ordner `.mywish-data-<Kennung>` angelegt.
- Erlaubt der Hoster dort keine Schreibrechte, meldet MyWish die fehlende private Ablage. Dann `data` auf einen geeigneten privaten Ordner setzen. Es werden keine JSON-Dateien im Webroot als Ausweichlösung angelegt.

Der geschützte `storage/layout.php`-Verweis hält den gewählten Ort fest. Kommandozeilenwerkzeuge finden dadurch denselben Datenbestand wie die Webanwendung. Für einen Umzug zuerst Schreibzugriffe stoppen und den vollständigen privaten Ordner kopieren; bei gleichem Datenbestand kann `data` danach auf den neuen privaten Pfad zeigen. Ein fremder Datenbestand wird nicht versehentlich übernommen.

## Migration bestehender Installationen

Unterstützt werden die bisherigen Schemata 1, 2 und 3. Schema 1 erhält die bisher fehlenden Google-Felder. Nutzer-IDs, Passworthashes, Google-only-Konten, Sitzungsversionen, Listen, Wünsche, Preise, Reservierungen, Anmeldezähler und Bildquellen bleiben erhalten. Die 14-Tage-Sitzungen müssen nicht wegen dieser Speichermigration erneuert werden.

Bei kleinen Datenbeständen läuft die Migration beim ersten Zugriff nach dem Code-Update automatisch. Es gelten dieselben Schritte wie beim CLI-Lauf:

1. Ursprüngliche Datei vollständig prüfen.
2. Ein **bytegenaues**, PHP-geschütztes Backup im bisherigen Storage anlegen und seine Prüfsumme prüfen. Wiederholte Versuche mit derselben Quelldatei verwenden dieselbe Sicherung.
3. In einem privaten Zwischenordner alle Bilder extrahieren und die JSON-Sammlungen schreiben.
4. Referenzen, Mengen, Bildinhalt, MIME-Typ, Abmessungen und Prüfsummen vollständig prüfen.
5. Den geprüften neuen Datenordner aktivieren. Die alte `state.php` durch einen kleinen Schema-4-Verweis ersetzen und `layout.php` veröffentlichen.

Älterer Anwendungscode verweigert den Schema-4-Verweis und kann somit nicht unbemerkt in einer veralteten Kopie weiterschreiben. Ein Abbruch zwischen diesen letzten Schritten lässt sich beim nächsten Lauf aus dem Migrationsverweis fortsetzen. Ein Abbruch vor dem Umschalten erhält die bisherige Datei. Bestehende fremde Zielbestände werden nicht überschrieben.

Bei großen Dateien erkennt MyWish vor dem Einlesen zu wenig Arbeitsspeicher und fordert einen CLI-Lauf an. Für den einmaligen Lauf mehr Speicher freigeben, zum Beispiel:

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

CLI-Werkzeuge erhalten den tatsächlichen veröffentlichten Webroot ausdrücklich über `--web-root`, damit auch außerhalb einer Webanfrage keine rohen Dateien im öffentlichen Verzeichnis angelegt werden. Der Prüflauf verändert keine Dateien. Er prüft den Quelldatenbestand, ersetzt aber keine Schreibrechteprüfung auf dem tatsächlichen Hosting. Für noch größere Bestände die Speichergrenze passend erhöhen. Temporär Platz für alte Datei, vollständiges Backup und extrahierte Bilder einplanen, grob bis zum Dreifachen der bisherigen Dateigröße. Die Werkzeuge sind ausschließlich per CLI aufrufbar; es gibt keinen öffentlichen Migrations- oder Administrationsendpunkt.

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

Der Zielordner darf noch nicht existieren und muss privat sein. Das Manifest wird zuletzt geschrieben; eine unvollständige Kopie besteht die Prüfung nicht. Ohne CLI Schreibzugriffe auf die Installation während der SFTP-/Dateimanager-Kopie sperren und den vollständigen privaten Datenordner sichern. Eine laufende Verzeichniskopie ohne Sperre ist kein konsistentes Backup.

Zum Testen der Wiederherstellung eine frische, zugriffsgeschützte Installation derselben Version verwenden, den Sicherungsordner außerhalb ihres Webroots ablegen und in `config.php` als `data` angeben. Der frische `storage`-Ordner erhält dann den Verweis; Setup nicht erneut ausführen. Erst nach erfolgreicher Prüfung den geplanten produktiven Restore durchführen. Vorher den aktuellen Datenstand separat sichern und Sitzungen invalidieren.

Ein Rollback auf Schema-3-Code benötigt den vorherigen Code und die bytegenaue alte `backup-schema-3-<sha256>.php` als `state.php` zusammen. Änderungen seit der Migration stehen in dieser alten Sicherung nicht zur Verfügung. Deshalb vor einem Rollback auch den neuen Datenbestand sichern und Schreibzugriffe sperren. `layout.php` nicht als Altbestand weiterverwenden.

## Bereinigung

Normalerweise hält MyWish nur die aktuelle und die vorherige Metadatengeneration sowie ihre Bilder vor. Nach abgebrochenen Schreibvorgängen können unveröffentlichte Dateien übrig bleiben. Sie werden bei Bedarf offline unter exklusiver Datensperre entfernt:

```sh
php tools/cleanup-storage.php --storage=/home/dein-konto/public_html/mywish/storage --web-root=/home/dein-konto/public_html
```

Das Werkzeug prüft zuerst den aktuellen Stand. Es entfernt ausschließlich nicht referenzierte, intern benannte JSON-/Bilddateien; aktuelle und vorherige Generation sowie Migrationsbackups bleiben erhalten. Beschädigte aktive Daten werden nicht durch eine Bereinigung übergangen. Verwaiste Migrations-Zwischenordner nach einem harten Prozessabbruch sind privat und können nach Prüfung separat entfernt werden.

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

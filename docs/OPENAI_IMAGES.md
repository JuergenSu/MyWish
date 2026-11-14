# Produktbilder mit OpenAI suchen

Wenn der normale Linkimport kein nutzbares Bild findet, kann MyWish über die offizielle OpenAI Responses API nach einem vorhandenen Produkt- oder Beispielbild suchen. Verwendet wird `web_search` mit `search_content_types: ["image", "text"]`; angefordert werden `web_search_call.results` und `web_search_call.action.sources`. MyWish verarbeitet strukturierte Bildtreffer und tatsächliche Quellseiten beziehungsweise Quellenangaben der API. Aus diesen Seiten werden gegebenenfalls Produktbilder ausgelesen. Freie Modelltexte werden nicht als Bildadresse verwendet. Es werden keine Bilder generiert.

## Einrichtung

1. Im [OpenAI-API-Projekt](https://platform.openai.com/api-keys) einen API-Key erstellen und nutzbares API-Guthaben beziehungsweise API-Abrechnung einrichten.
2. Falls noch keine `config.php` vorhanden ist, `config.example.php` nach `config.php` kopieren. Eine vorhandene Konfiguration erhalten und folgenden Block ergänzen:

```php
'openai' => [
    'api_key' => '', // Hier den eigenen API-Key auf dem Server eintragen.
    'model' => 'gpt-6-astra',
    'daily_limit' => 100,
],
```

3. PHP benötigt cURL und ausgehendes HTTPS zu `api.openai.com`. Das eingetragene Modell und der API-Key müssen die Bildsuche unterstützen. Der Modellname ist konfigurierbar; der voreingestellte Name entspricht dem Beispiel in der [offiziellen Bildsuch-Dokumentation](https://developers.openai.com/api/docs/guides/tools-web-search#image-search-results).
4. Mit einem angemeldeten Eigentümerkonto einen Wunsch öffnen. Ein fehlgeschlagener Linkimport startet im Browser automatisch die Bildsuche, wenn ein Titel vorliegt und noch kein gespeichertes Bild vorhanden ist. Alternativ **Bild mit OpenAI suchen** wählen. Ohne JavaScript funktioniert dieser Button als normales Formular.
5. Vorschlag und Modell prüfen, dann **Wunsch speichern** wählen. Einen unpassenden Vorschlag über den Haken **Dieses Bild übernehmen** abwählen. Ein Quellenlink bleibt beim gespeicherten Bild erhalten. Datei-Upload und manuelle Bildadresse sind weiterhin verfügbar.

Ein leerer API-Key deaktiviert die Funktion. Schlüssel und Modell werden ausschließlich aus `config.php` gelesen; Eingaben im Browser können sie nicht überschreiben. `config.php` bleibt von Git ausgeschlossen. Den Schlüssel weder im Chat noch in Issues, Screenshots oder Browsercode veröffentlichen.

## Ablauf und Grenzen

Der normale Shopimport bleibt die erste Quelle. Die optionale Suche startet als zweite Browseranfrage, damit Shopdaten und Formular bereits sichtbar sind. Änderungen an Titel oder Link sowie die Auswahl einer eigenen Datei brechen die laufende Browseranfrage ab. Ein bereits gestarteter API-Aufruf kann dabei weiterlaufen und Kosten verursachen; sein Ergebnis wird nicht ungefragt für das geänderte Produkt ausgewählt.

Gesendet werden der eingegebene Produkttitel und ein bereinigter Produktlink. Amazon-Links werden auf ihre ASIN gekürzt; Queryparameter und Fragmente anderer URLs werden entfernt. Beschreibungen, Preise, Nutzerkonten und Reservierungen werden nicht an OpenAI gesendet. Die Suche beginnt mit einem kurzen Begriff aus Marke und Produkttyp. Bei fehlenden Fotos soll ein zweiter Suchlauf nur die allgemeine Kategorie verwenden, beispielsweise „Toaster“ oder „Temperatursensor mit Sonde“, ohne Modellnummer, ASIN und lange Merkmalslisten. Ähnliche Produkte sind ausdrücklich erlaubt. Bildvorschläge können bei Marke, Farbe oder Modell abweichen und werden entsprechend gekennzeichnet.

Pro API-Anfrage sind höchstens zwei Websuch-Toolaufrufe erlaubt; fehlgeschlagene API-Anfragen werden nicht automatisch wiederholt. Die API hat 20 Sekunden Zeit, Suche einschließlich Bildabruf insgesamt 28 Sekunden. Bis zu vier direkte Bildtreffer und zwei strukturierte Quellseiten werden berücksichtigt. Wenn nötig werden aus den Quellseiten Produktbilder extrahiert. Vorhandene Vorschaubilder haben Vorrang vor großen Originalen. Höchstens sechs Bilddownloads und zwei Seitenabrufe werden versucht, jeweils mit höchstens drei Sekunden innerhalb des Gesamtbudgets. Für alle Abrufe gelten die bisherigen DNS-, Redirect-, TLS- und Größenprüfungen: öffentliche IPv4-Ziele, Standardports, Seiten bis 12 MB nach Dekomprimierung sowie JPEG/PNG/WebP bis 2 MB und 16 Megapixel. Suchbilder unter 64 Pixeln pro Seite werden abgewiesen. API-Header und Schlüssel werden niemals an Bild- oder Quellserver weitergegeben.

Liefert OpenAI weder eine ausgeführte Websuche noch strukturierte Quellen, erscheint ein ausdrücklicher Fehlerhinweis zum Modellzugriff. Leere Suchergebnisse und fehlgeschlagene Bilddownloads erhalten unterschiedliche Hinweise. Auch eine allgemeinere Suche garantiert kein nutzbares Bild, beispielsweise wenn alle Quellen gesperrt sind.

Die API-Nutzung kann Modell- und Suchkosten verursachen. Die Installation erlaubt standardmäßig höchstens **100 neue API-Anfragen pro UTC-Tag**; `daily_limit` kann zwischen 1 und 10000 liegen. Eine Anfrage kann bis zu zwei kostenpflichtige Such-Toolaufrufe enthalten. Zusätzlich gelten fünf neue API-Anfragen je Nutzer innerhalb von zehn Minuten. Fehlgeschlagene API-Aufrufe zählen mit. Ergebnisse für denselben Titel, bereinigten Link, dasselbe Modell und dieselbe Suchstrategie werden bis zu 24 Stunden geteilt wiederverwendet, höchstens 32 Einträge innerhalb von 512 KB. Bei Platzbedarf werden alte abgeschlossene Cacheeinträge entfernt; Nutzungszähler bleiben erhalten. Leere Suchergebnisse werden zehn Minuten gespeichert. Cachetreffer verursachen keinen weiteren OpenAI-Aufruf; Bilder und gegebenenfalls Quellseiten werden erneut geprüft und geladen. Beim Wechsel zur allgemeineren Suche werden alte leere Suchergebnisse nicht wiederverwendet. Die Aufrufzähler werden dabei nicht zurückgesetzt.

Zähler und Suchadressen liegen geschützt unter `storage/openai-search.php`, der zugehörige Lock in `storage/openai-search-lock.php` beziehungsweise im konfigurierten Storage-Verzeichnis. Keine Schlüssel und keine freien Modellantworten werden dort gespeichert. Bei beschädigtem Zähler-/Cacheinhalt werden kostenpflichtige Suchanfragen gesperrt. Der Betreiber kann die Cachedatei nach Prüfung über den Dateimanager entfernen; damit werden auch die lokalen Tageszähler zurückgesetzt. Es gibt keine öffentliche Reset-Funktion.

## Update und Backup

Bildquellen wurden mit Schema 3 eingeführt. Aktuell verwendet MyWish **Schema 4** mit privaten JSON-Sammlungen und separaten Bilddateien. Gültige Schemata 1–3 werden unter Lock nach bytegenauem Backup migriert; Quellen, Konten, Google-Verknüpfungen, Wünsche und Reservierungen bleiben erhalten. [Konzept und Migrationslauf](STORAGE.md).

Bei Updates `storage/`, `config.php` und den privaten Datenordner erhalten. Der OpenAI-Block bleibt in `config.php`; Bildquellen stehen in den JSON-Bildverweisen. Backups benötigen JSON-Dateien und Bilder zusammen. Der Suchcache muss nicht mitgesichert werden. Ein Rollback auf älteren Code erfordert dessen passenden Datenstand aus dem Backup.

## Prüfungen

```sh
php tests/openai-images.php
python3 tests/openai-http.py
node tests/openai-browser.cjs
```

Die Tests verwenden einen isolierten Transport mit synthetischen Bildern und einem künstlichen Testschlüssel. Sie führen keine echten OpenAI-Aufrufe aus und verursachen keine API-Kosten. Die tatsächliche Suchqualität und Verfügbarkeit im eigenen OpenAI-Projekt müssen nach Eintragen eines echten Schlüssels mit einem Produkt geprüft werden.

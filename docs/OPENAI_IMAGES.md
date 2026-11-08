# Produktbilder mit OpenAI suchen

Wenn der normale Linkimport kein nutzbares Bild findet, kann MyWish über die offizielle OpenAI Responses API nach einem bestehenden Produktfoto suchen. Verwendet wird `web_search` mit `search_content_types: ["image", "text"]` und `include: ["web_search_call.results"]`. MyWish verarbeitet ausschließlich tatsächliche `image_result`-Treffer mit Bild- und Quelladresse. Freie Modellantworten werden weder als Bildadresse verwendet noch angezeigt. Es werden keine Bilder generiert.

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

Gesendet werden der eingegebene Produkttitel und ein bereinigter Produktlink. Amazon-Links werden auf ihre ASIN gekürzt; Queryparameter und Fragmente anderer URLs werden entfernt. Beschreibungen, Preise, Nutzerkonten und Reservierungen werden nicht an OpenAI gesendet. Die Suche soll Hersteller und exakte Modellbezeichnung bevorzugen. Eine korrekte Produktzuordnung ist dennoch nicht garantiert.

Pro Suchanfrage wird höchstens ein Websuch-Toolaufruf angefordert; es gibt keine automatischen API-Wiederholungen. Die API hat 20 Sekunden Zeit, Suche einschließlich Bildabruf insgesamt 28 Sekunden. Höchstens drei Downloads von Originalen oder vorhandenen Vorschaubildern werden versucht. Für Bildabrufe gelten die bisherigen DNS-, Redirect-, TLS- und Größenprüfungen: öffentliche IPv4-Ziele, Standardports, JPEG/PNG/WebP bis 2 MB und 16 Megapixel. Suchbilder unter 64 Pixeln pro Seite werden abgewiesen. API-Header und Schlüssel werden niemals an Bildserver weitergegeben.

Die API-Nutzung kann Modell- und Suchkosten verursachen. Die Installation erlaubt standardmäßig höchstens **100 neue Suchanfragen pro UTC-Tag**; `daily_limit` kann zwischen 1 und 10000 liegen. Zusätzlich gelten fünf neue Suchanfragen je Nutzer innerhalb von zehn Minuten. Fehlgeschlagene API-Aufrufe zählen mit. Ergebnisse für denselben Titel, bereinigten Link und dasselbe Modell werden bis zu 24 Stunden geteilt wiederverwendet, höchstens 32 Einträge. Leere Suchergebnisse werden zehn Minuten gespeichert. Cachetreffer verursachen keinen weiteren OpenAI-Aufruf, die Bilddatei wird erneut geprüft und geladen.

Zähler und Suchadressen liegen geschützt unter `storage/openai-search.php`, der zugehörige Lock in `storage/openai-search-lock.php` beziehungsweise im konfigurierten Storage-Verzeichnis. Keine Schlüssel und keine freien Modellantworten werden dort gespeichert. Bei beschädigtem Zähler-/Cacheinhalt werden kostenpflichtige Suchanfragen gesperrt. Der Betreiber kann die Cachedatei nach Prüfung über den Dateimanager entfernen; damit werden auch die lokalen Tageszähler zurückgesetzt. Es gibt keine öffentliche Reset-Funktion.

## Update und Backup

Diese Version verwendet **Schema 3**, um den Quellenlink im Bilddatensatz zu speichern. Gültige Schema-1- und Schema-2-Daten werden unter Lock zuerst bytegenau als geschützte `backup-schema-1-…php` beziehungsweise `backup-schema-2-…php` gesichert, dann umgestellt. Nutzer, Google-Verknüpfungen, Passworthashes, Listen, Wünsche, Bilder und Reservierungen bleiben erhalten. Scheitert die Sicherung, wird nicht migriert.

Vor dem Update ein Betreiber-Backup anlegen. `storage/` und `config.php` erhalten; den neuen OpenAI-Block in die bestehende Konfiguration übernehmen. Gespeicherte Bildquellen sind in `state.php` enthalten und werden mitgesichert. Ein Rollback auf älteren Code erfordert dessen passenden Datenstand aus dem Backup. Der Suchcache muss nicht mitgesichert werden.

## Prüfungen

```sh
php tests/openai-images.php
python3 tests/openai-http.py
node tests/openai-browser.cjs
```

Die Tests verwenden einen isolierten Transport mit synthetischen Bildern und einem künstlichen Testschlüssel. Sie führen keine echten OpenAI-Aufrufe aus und verursachen keine API-Kosten. Die tatsächliche Suchqualität und Verfügbarkeit im eigenen OpenAI-Projekt müssen nach Eintragen eines echten Schlüssels mit einem Produkt geprüft werden.

<?php
declare(strict_types=1);
// Public help contains no account data and needs neither storage nor a session.
header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; style-src 'self'; img-src 'self'; base-uri 'none'; frame-ancestors 'none'; form-action 'none'");
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="description" content="MyWish einfach erklärt: Wünsche sammeln, Listen teilen und Geschenke reservieren. Diese Anleitung ist ohne Anmeldung lesbar.">
  <title>MyWish · Anleitung</title>
  <link rel="stylesheet" href="assets/css/app.css?v=google-first-login">
  <link rel="stylesheet" href="assets/css/guide.css?v=1">
  <link rel="icon" href="assets/icons/icon-192.png">
</head>
<body>
<a class="skip-link" href="#inhalt">Zum Inhalt</a>
<header class="header"><div class="shell header-inner"><a class="brand" href="index.php">MyWish.</a><a class="button secondary" href="index.php">Zu MyWish</a></div></header>
<main id="inhalt" class="shell main guide">
  <div class="form-heading">
    <span class="eyebrow">SO GEHT’S</span>
    <h1>Deine Anleitung für MyWish</h1>
    <p>Mit MyWish sammelst du deine Wünsche in einer Liste. Andere können einen Wunsch als Geschenk reservieren. So kaufen nicht mehrere Personen dasselbe Geschenk.</p>
    <p>Du kannst diese Anleitung ohne Anmeldung lesen und den Link dazu weitergeben.</p>
  </div>

  <nav class="panel guide-section" aria-label="In dieser Anleitung">
    <h2>Was möchtest du machen?</h2>
    <ul class="guide-contents">
      <li><a href="#anmelden">Anmelden oder ein Konto erstellen</a></li>
      <li><a href="#liste">Eine Wunschliste erstellen</a></li>
      <li><a href="#wunsch">Wünsche und Bilder hinzufügen</a></li>
      <li><a href="#teilen">Eine Liste teilen</a></li>
      <li><a href="#schenken">Ein Geschenk reservieren</a></li>
      <li><a href="#handy">MyWish auf dem Handy nutzen</a></li>
      <li><a href="#konto">Dein Konto verwalten</a></li>
      <li><a href="#hilfe">Hilfe bei Problemen</a></li>
    </ul>
  </nav>

  <section class="panel guide-section" aria-labelledby="anmelden">
    <h2 id="anmelden">1. Anmelden oder ein Konto erstellen</h2>
    <p>Öffne MyWish im Browser. Du brauchst ein Konto, um eigene Listen anzulegen oder Geschenke zu reservieren.</p>
    <p><strong>Mit Google:</strong> Wenn du „Mit Google anmelden“ siehst, tippe darauf. Wähle dein Google-Konto und folge den Schritten. Bei deiner ersten Anmeldung kann so ein MyWish-Konto entstehen, wenn neue Konten erlaubt sind.</p>
    <p><strong>Mit Passwort:</strong> Wähle den Tab „Mit Passwort“, falls er angezeigt wird. Gib deinen Benutzernamen oder deine E-Mail und dein Passwort ein. Tippe auf „Anmelden“.</p>
    <p><strong>Noch kein Konto?</strong> Wähle beim Passwort-Login „Jetzt registrieren“. Gib deinen Anzeigenamen, einen Benutzernamen oder deine E-Mail und ein Passwort ein. Das Passwort braucht mindestens 8 Zeichen. Ein Sonderzeichen ist nicht nötig.</p>
    <p>Wenn die Registrierung geschlossen ist, frage die Person, die diese MyWish-Seite betreibt.</p>
    <p>Hast du schon ein Passwort-Konto und möchtest Google nutzen? Melde dich zuerst mit deinem Passwort an. Verknüpfe Google danach unter „Mein Konto“. So bleiben deine Listen bei deinem bisherigen Konto.</p>
  </section>

  <section class="panel guide-section" aria-labelledby="liste">
    <h2 id="liste">2. Eine Wunschliste erstellen</h2>
    <ol>
      <li>Öffne „Alle Listen“ und tippe auf „Liste erstellen“.</li>
      <li>Gib der Liste einen Namen, zum Beispiel „Mein Geburtstag“.</li>
      <li>Wähle einen Anlass. Du kannst auch ein Datum, eine Beschreibung und ein Bild ergänzen.</li>
      <li>Tippe auf „Liste speichern“.</li>
    </ol>
    <p>Du kannst mehrere Listen anlegen. Zum Ändern öffnest du deine Liste und wählst „Liste bearbeiten“.</p>
    <p><strong>Wer sieht deine Liste?</strong> Alle angemeldeten Personen dieser MyWish-Seite sehen alle Listen. Auch Personen mit dem Teilen-Link können die geteilte Liste lesen. Trage deshalb keine vertraulichen Informationen ein. Nur du kannst deine Liste bearbeiten.</p>
  </section>

  <section class="panel guide-section" aria-labelledby="wunsch">
    <h2 id="wunsch">3. Wünsche und Bilder hinzufügen</h2>
    <ol>
      <li>Öffne deine Liste und wähle „Wunsch hinzufügen“.</li>
      <li>Trage unter „Dein Wunsch“ ein, was du dir wünschst. Auch ein Erlebnis oder etwas Selbstgemachtes ist möglich.</li>
      <li>Ergänze bei Bedarf eine Beschreibung, einen Preis, die gewünschte Menge und ein Bild.</li>
      <li>Tippe auf „Wunsch speichern“.</li>
    </ol>
    <h3>Einen Link aus einem Shop verwenden</h3>
    <p>Kopiere den Link zur Produktseite in das Feld „Produktlink“. Tippe auf „Daten übernehmen“. MyWish versucht, den Namen, den Preis und ein Bild einzutragen.</p>
    <p>Prüfe die Angaben. Manche Shops lassen das Auslesen nicht zu. Du kannst fehlende Angaben selbst ergänzen. <strong>Erst „Wunsch speichern“ übernimmt den Wunsch in deine Liste.</strong></p>
    <h3>Ein Bild auswählen</h3>
    <p>Unter „Eigenes Bild“ kannst du ein Foto oder einen Handy-Screenshot auswählen. Erlaubt sind JPG, PNG und WebP bis 8 MB. Das Bild wird automatisch verkleinert.</p>
    <p>Wenn „Bild mit OpenAI suchen“ angezeigt wird, kannst du damit einen Bildvorschlag suchen lassen. Nach einem Import ohne Bild kann die Suche auch von selbst starten. Der Vorschlag kann ein ähnliches Produkt zeigen. Prüfe ihn und übernimm ihn nur, wenn er für deinen Wunsch passt.</p>
    <p>Zum Ändern wählst du „Wunsch bearbeiten“. Wenn du die gewünschte Menge änderst, werden vorhandene Reservierungen für diesen Wunsch zurückgesetzt.</p>
  </section>

  <section class="panel guide-section" aria-labelledby="teilen">
    <h2 id="teilen">4. Eine Liste teilen</h2>
    <ol>
      <li>Öffne die Liste und wähle „Teilen“.</li>
      <li>Wähle eine App zum Verschicken oder kopiere den angezeigten Link.</li>
      <li>Sende den Link an die Personen, die die Liste sehen sollen.</li>
    </ol>
    <p>Mit diesem Link lässt sich genau diese Liste <strong>ohne Anmeldung</strong> ansehen. Der Link kann weitergegeben werden.</p>
    <p>Zum Reservieren ist eine Anmeldung nötig. Ein Klick auf „Für mich reservieren“ führt ohne Anmeldung zuerst zur Anmeldeseite. Danach geht es zurück zur Liste.</p>
    <p>In der Ansicht ohne Anmeldung sieht niemand, welche Wünsche schon reserviert sind.</p>
  </section>

  <section class="panel guide-section" aria-labelledby="schenken">
    <h2 id="schenken">5. Ein Geschenk reservieren</h2>
    <ol>
      <li>Melde dich an und öffne die Liste der Person, die du beschenken möchtest.</li>
      <li>Suche dir einen verfügbaren Wunsch aus.</li>
      <li>Tippe auf „Für mich reservieren“. Bei mehreren gewünschten Stücken heißt der Knopf „Eine Einheit reservieren“.</li>
      <li>Unter „Meine Reservierungen“ findest du deine Auswahl wieder.</li>
    </ol>
    <p>Du reservierst pro Wunsch ein Stück. Andere angemeldete Schenkende sehen, ob noch etwas verfügbar ist. <strong>Die Person, der die Liste gehört, sieht nicht, ob oder wer etwas reserviert hat.</strong> Eigene Wünsche kannst du nicht selbst reservieren.</p>
    <p><strong>Eine Reservierung ist kein Kauf.</strong> Bestelle oder kaufe das Geschenk selbst. „Zum Produkt“ öffnet den Shop, wenn ein Link hinterlegt ist.</p>
    <p>Du möchtest das Geschenk doch nicht kaufen? Wähle bei deinem reservierten Wunsch „Reservierung freigeben“. Dann können andere ihn wieder reservieren.</p>
  </section>

  <section class="panel guide-section" aria-labelledby="handy">
    <h2 id="handy">6. MyWish auf dem Handy nutzen</h2>
    <p>MyWish funktioniert direkt im Browser. Du brauchst keinen App-Store.</p>
    <p><strong>Android:</strong> Wenn oben „Hinzufügen“ angeboten wird, tippe darauf und bestätige. Sonst öffne das Browsermenü und suche nach „App installieren“ oder „Zum Startbildschirm hinzufügen“.</p>
    <p><strong>iPhone:</strong> Öffne MyWish in Safari. Tippe auf „Teilen“, dann auf „Zum Home-Bildschirm“ und „Hinzufügen“.</p>
    <p>Danach öffnest du MyWish über das lila Geschenk-Symbol. Die Namen der Menüpunkte können je nach Handy abweichen. Zum Nutzen brauchst du eine Internetverbindung.</p>
  </section>

  <section class="panel guide-section" aria-labelledby="konto">
    <h2 id="konto">7. Dein Konto verwalten</h2>
    <p>Tippe oben auf dein Profil mit deinem Anfangsbuchstaben. Unter „Mein Konto“ kannst du deinen Anzeigenamen und dein Passwort ändern. Falls Google angeboten wird, kannst du dort auch dein Google-Konto verknüpfen.</p>
    <p>Nach 14 Tagen ohne Nutzung musst du dich neu anmelden. Auf einem gemeinsam genutzten Gerät solltest du unter „Mein Konto“ auf „Abmelden“ tippen.</p>
    <p>Dort kannst du auch dein Konto löschen. Dabei werden deine Listen, Wünsche und Reservierungen gelöscht. Das lässt sich in der App nicht rückgängig machen. Ältere Sicherungen können noch bei der Person liegen, die MyWish betreibt.</p>
  </section>

  <section class="panel guide-section" aria-labelledby="hilfe">
    <h2 id="hilfe">8. Hilfe bei Problemen</h2>
    <h3>Der Produktlink liefert kein Bild oder keinen Preis.</h3>
    <p>Trage die Angaben selbst ein und wähle bei Bedarf ein eigenes Bild. Ein Wunsch funktioniert auch ohne Shop-Link, Preis oder Bild.</p>
    <h3>Ein Wunsch ist schon reserviert.</h3>
    <p>Wähle einen anderen Wunsch. Wenn mehrere Stück gewünscht sind, kannst du reservieren, solange noch ein Stück frei ist.</p>
    <h3>Ich habe mein Passwort vergessen.</h3>
    <p>Wenn du Google bereits mit deinem Konto verknüpft hast, versuche die Google-Anmeldung. Sonst wende dich an die Person, die diese MyWish-Seite betreibt. MyWish verschickt keine E-Mail zum Zurücksetzen des Passworts.</p>
    <h3>Ich sehe eine Fehlermeldung.</h3>
    <p>Lade die Seite neu und melde dich bei Bedarf erneut an. Bleibt das Problem bestehen, gib den Text der Meldung an die Person weiter, die MyWish betreibt. Sende dabei kein Passwort mit.</p>
  </section>
  <a class="button primary" href="index.php">Jetzt MyWish öffnen</a>
</main>
<footer class="footer shell"><a href="index.php">Zu MyWish</a><a href="#inhalt">Zum Anfang der Anleitung</a></footer>
</body>
</html>

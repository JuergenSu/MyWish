<?php
declare(strict_types=1);
require __DIR__.'/src/bootstrap.php';
require __DIR__.'/src/Http.php';
require __DIR__.'/src/View.php';
use MyWish\App;
use MyWish\Problem;

$page=is_string($_GET['page'] ?? null) ? $_GET['page'] : 'home';
$error=''; $form=[]; $preview=null; $state=null;
try { $settings=$app->settings(); } catch (Throwable $ex) { bootFailure($ex instanceof Problem ? $ex->getMessage() : 'Die Daten können nicht gelesen werden.'); }
if ($_SERVER['REQUEST_METHOD']==='POST') {
    try {
        $form=input(); $action=App::text($form,'action',30,true);
        $result=mutate($app,$action,$form);
        if ($action==='import') {
            $preview=$result; $page='wish';
            $form=MyWish\Metadata::mergePreview($result,$form);
            if ($result['hasImage']) { $form['useImportedImage']='yes'; }
        } else {
            $_SESSION['flash']=$result['message'];
            $query=match ($action) {
                'saveList'=>'page=list&id='.$result['id'],
                'saveWish','deleteWish','reserve','unreserve'=>'page=list&id='.$result['listId'],
                'account'=>'page=account',
                default=>'',
            };
            if (in_array($action,['reserve','unreserve'],true) && ($form['returnPage'] ?? '')==='reservations') { $query='page=reservations'; }
            header('Location: '.url($query),true,303); exit;
        }
    } catch (Throwable $ex) { [$status,$error]=safeError($ex); http_response_code($status); }
    $settings=$app->settings();
}
if (!$settings) { $page='setup'; }
else {
    try { $state=$app->snapshot(); }
    catch (Problem $ex) { if ($ex->status===401) { if (!in_array($page,['login','register'],true)) { $page='login'; } } else { $error=$ex->getMessage(); http_response_code($ex->status); $page='error'; } }
}
if ($state && in_array($page,['setup','login','register'],true)) { $page='home'; }
if ($page==='register' && ($settings['registration'] ?? '')!=='open') { $page='login'; $error='Die Registrierung ist geschlossen.'; }
$user=$state['user'] ?? null;
$siteName=$settings['siteName'] ?? 'MyWish';
$flash=$_SESSION['flash'] ?? ''; unset($_SESSION['flash']);
?>
<!doctype html>
<html lang="de">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="theme-color" content="#635bdb"><meta name="apple-mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-title" content="<?= e($siteName) ?>"><title><?= e($siteName) ?> · Wünsche verbinden</title><link rel="icon" href="<?= e($base) ?>/assets/favicon.svg" type="image/svg+xml"><link rel="manifest" href="<?= e($base) ?>/manifest.webmanifest"><link rel="apple-touch-icon" sizes="180x180" href="<?= e($base) ?>/assets/icons/apple-touch-icon.png"><link rel="stylesheet" href="<?= e($base) ?>/assets/css/app.css"><script src="<?= e($base) ?>/assets/js/app.js" defer></script><script src="<?= e($base) ?>/assets/js/install.js" defer></script></head>
<body>
<a class="skip" href="#main">Zum Inhalt</a>
<?php if ($settings): ?>
<aside id="install-banner" class="install-banner" aria-labelledby="install-title" data-base="<?= e($base) ?>/" hidden>
<div class="shell install-inner"><img class="install-icon" src="<?= e($base) ?>/assets/icons/icon-192.png" alt="" width="44" height="44"><div class="install-copy"><h2 id="install-title"><?= e($siteName) ?> auf deinem Startbildschirm</h2><p>Deine Wünsche, nur einen Tipp entfernt.</p><p id="install-help" class="install-help" role="status" hidden></p></div><button id="install-button" type="button" class="button primary" aria-controls="install-help" aria-expanded="false">So geht’s</button><button id="install-dismiss" type="button" class="install-dismiss" aria-label="Installationshinweis schließen">×</button></div>
</aside>
<?php endif ?>
<header class="header"><div class="shell header-inner"><a class="brand" href="<?= e(url()) ?>"><span class="brand-icon"><?= icon('gift') ?></span><?= e($siteName) ?><span class="brand-dot">.</span></a>
<?php if ($user): ?><nav aria-label="Hauptnavigation"><a <?= in_array($page,['home','list','editList','wish'],true) ? 'aria-current="page"' : '' ?> href="<?= e(url()) ?>"><?= icon('grid') ?><span>Alle Listen</span></a><a <?= $page==='reservations' ? 'aria-current="page"' : '' ?> href="<?= e(url('page=reservations')) ?>"><?= icon('heart') ?><span>Meine Reservierungen</span></a></nav><a class="profile" aria-label="Mein Konto: <?= e($user['name']) ?>" href="<?= e(url('page=account')) ?>"><?= e(initials($user['name'])) ?></a>
<?php else: ?><span class="header-note">Kleine Wünsche. Große Freude.</span><?php endif ?></div></header>
<main id="main" class="shell main" tabindex="-1">
<?php if ($flash): ?><div class="notice success" role="status"><?= icon('check') ?><span><?= e($flash) ?></span></div><?php endif ?>
<?php if ($error): ?><div class="notice error" role="alert"><?= icon('info') ?><span><?= e($error) ?></span></div><?php endif ?>
<?php try {
if (in_array($page,['setup','login','register'],true)):
    $setup=$page==='setup'; $register=$page==='register'; ?>
<section class="auth-layout"><div class="auth-story"><span class="eyebrow">SCHENKEN BEGINNT MIT EINEM WUNSCH</span><h1>Mehr Vorfreude.<br>Weniger Rätselraten.</h1><p>Sammle deine Herzenswünsche und mach den Menschen, die dich beschenken möchten, eine kleine Freude.</p><div class="gift-scene" aria-hidden="true"><span class="spark s1">✦</span><span class="spark s2">✧</span><div class="present p-back"></div><div class="present p-front"><span>♡</span></div><span class="spark s3">✦</span></div><div class="story-note"><?= icon('heart') ?> Die schönsten Geschenke bleiben eine Überraschung.</div></div>
<div class="panel auth-panel"><span class="mini-icon"><?= icon($setup ? 'gift' : 'heart') ?></span><h2><?= $setup ? 'Dein MyWish beginnt hier' : ($register ? 'Platz für deine Wünsche' : 'Schön, dass du da bist') ?></h2><p class="muted"><?= $setup ? 'Einmal einrichten, gemeinsam Vorfreude teilen.' : ($register ? 'Erstelle dein Konto – ganz ohne E-Mail-Versand.' : 'Melde dich an und entdecke, was Freude macht.') ?></p>
<form method="post" class="stack">
<?= hidden('action',$setup ? 'setup' : ($register ? 'register' : 'login')) ?><?= csrf() ?>
<?php if ($setup): ?><?= field('siteName','Name deiner Wunschwelt',$form['siteName'] ?? 'MyWish','text',true,80) ?><?php endif ?>
<?php if ($setup || $register): ?><?= field('name','Dein Anzeigename',$form['name'] ?? '','text',true,100) ?><?php endif ?>
<?= field('username','Benutzername oder E-Mail',$form['username'] ?? '','text',true,120,'username') ?>
<?= field('password','Passwort','','password',true,72,$setup || $register ? 'new-password' : 'current-password') ?>
<?php if ($setup || $register): ?><p class="field-help"><?= e(App::PASSWORD_HINT) ?></p><?php endif ?>
<?php if ($setup): ?><label>Registrierung<select name="registration"><option value="open">Offen – neue Nutzer können sich registrieren</option><option value="closed" <?= ($form['registration'] ?? '')==='closed' ? 'selected' : '' ?>>Geschlossen – nur dieses Konto</option></select></label><p class="field-help">Alle angemeldeten Nutzer sehen alle Listen. Es gibt keine privaten Listen und keine Adminrolle. Registrierung später nur durch den Betreiber per Konfigurationsdatei ändern.</p><?php endif ?>
<button class="button primary full" type="submit"><?= $setup ? 'MyWish einrichten' : ($register ? 'Konto erstellen' : 'Anmelden') ?><?= icon('arrow') ?></button></form>
<?php if (!$setup): ?><p class="auth-switch"><?= $register ? 'Schon dabei?' : 'Noch kein Konto?' ?> <?php if ($register || ($settings['registration'] ?? '')==='open'): ?><a href="<?= e(url('page='.($register ? 'login' : 'register'))) ?>"><?= $register ? 'Jetzt anmelden' : 'Jetzt registrieren' ?></a><?php else: ?>Registrierung geschlossen.<?php endif ?></p><?php else: ?><p class="field-help">PHP und Storage geprüft · Keine Datenbank erforderlich</p><?php endif ?></div></section>
<?php elseif ($page==='home' && $state):
    $q=is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';
    $lists=array_filter($state['lists'],fn($l)=>$q==='' || stripos($l['name'].' '.$l['ownerName'],$q)!==false);
    $mine=array_filter($lists,fn($l)=>$l['ownerUserId']===$user['id']); $others=array_filter($lists,fn($l)=>$l['ownerUserId']!==$user['id']); ?>
<div class="page-heading"><div><span class="eyebrow">DEINE KLEINE WUNSCHWELT</span><h1>Hallo <?= e($user['name']) ?><span class="wave"> ☀</span></h1><p class="muted">Wünsche sammeln. Freude schenken. Gemeinsam vorfreuen.</p></div><a class="button primary" href="<?= e(url('page=editList')) ?>"><?= icon('plus') ?>Liste erstellen</a></div>
<section class="welcome-banner"><div><span class="eyebrow">FÜR DIE MENSCHEN, DIE DIR WICHTIG SIND</span><h2>Ein guter Wunsch ist der<br>Anfang einer schönen Überraschung.</h2><p>Alle Listen an einem Ort. Reservierungen bleiben für den Eigentümer unsichtbar.</p></div><div class="banner-gift" aria-hidden="true"><?= icon('gift') ?><span>✦</span></div></section>
<form method="get" class="search" role="search"><label class="sr-only" for="search">Listen oder Personen suchen</label><?= icon('search') ?><input id="search" name="q" type="search" maxlength="160" placeholder="Nach Listen oder Personen suchen …" value="<?= e($q) ?>"><button type="submit" class="button quiet">Suchen</button><?php if ($q): ?><a href="<?= e(url()) ?>">Zurücksetzen</a><?php endif ?></form>
<section class="list-section"><div class="section-heading"><h2>Meine Listen <span class="count"><?= count($mine) ?></span></h2><span class="muted">Deine Wünsche, deine Vorfreude</span></div><div class="card-grid"><?php foreach ($mine as $l) { listCard($l,true); } ?><?php if (!$mine): ?><a class="new-card" href="<?= e(url('page=editList')) ?>"><span class="circle-plus"><?= icon('plus') ?></span><strong><?= $q ? 'Keine passende eigene Liste' : 'Deine erste Wunschliste' ?></strong><span>Ein Anlass, viele kleine Glücksmomente.</span></a><?php endif ?></div></section>
<section class="list-section"><div class="section-heading"><h2>Freude schenken <span class="count"><?= count($others) ?></span></h2><span class="muted">Entdecke die Wünsche der anderen</span></div><div class="card-grid"><?php foreach ($others as $l) { listCard($l,false); } ?></div><?php if (!$others): ?><?= emptyState('Noch ganz viel Platz für Vorfreude',$q ? 'Keine Liste passt zu deiner Suche.' : 'Sobald andere ihre Listen erstellen, findest du sie hier. Alle angemeldeten Nutzer sehen jede Liste.') ?><?php endif ?></section>
<?php elseif ($page==='editList' && $state):
    $id=is_string($_GET['id'] ?? null) ? $_GET['id'] : '';
    $l=$id ? App::find($state['lists'],$id) : [];
    if ($l && $l['ownerUserId']!==$user['id']) { throw new Problem(403,'Nur der Eigentümer darf diese Liste bearbeiten.'); }
    $f=$form ?: $l; ?>
<?= backLink($id ? 'page=list&id='.$id : '', 'Zurück zu deinen Listen') ?>
<div class="form-heading"><span class="eyebrow">VORFREUDE HAT EINEN PLATZ</span><h1><?= $id ? 'Liste bearbeiten' : 'Eine neue Wunschliste' ?></h1><p class="muted">Für einen besonderen Anlass. Oder einfach so.</p></div>
<form class="panel edit-panel stack" method="post" enctype="multipart/form-data"><?= csrf() ?><?= hidden('action','saveList') ?><?= hidden('id',$id) ?>
<?= field('name','Name der Liste',$f['name'] ?? '','text',true,160) ?>
<div class="form-row"><label>Anlass<select name="occasion"><?php foreach (['Geburtstag','Weihnachten','Hochzeit','Anderes'] as $o): ?><option <?= ($f['occasion'] ?? '')===$o ? 'selected' : '' ?>><?= e($o) ?></option><?php endforeach ?></select></label><?= field('eventDate','Datum (optional)',$f['eventDate'] ?? '','date') ?></div>
<?= textarea('description','Ein paar Worte zur Liste (optional)',$f['description'] ?? '') ?>
<fieldset><legend>Deine Coverfarbe</legend><div class="cover-options"><?php foreach (['lavender'=>'Lavendel','peach'=>'Pfirsich','mint'=>'Minze','sky'=>'Himmel'] as $key=>$label): ?><label class="cover-option <?= e($key) ?>"><input type="radio" name="cover" value="<?= e($key) ?>" <?= ($f['cover'] ?? 'lavender')===$key ? 'checked' : '' ?>><?= e($label) ?></label><?php endforeach ?></div></fieldset>
<?= imageField($l['image'] ?? false) ?>
<div class="notice soft"><?= icon('info') ?><span>Alle angemeldeten Nutzer können diese Liste sehen. Nur du bearbeitest sie. Reservierungen bleiben für dich unsichtbar.</span></div><div class="form-actions"><button class="button primary">Liste speichern</button><a class="button secondary" href="<?= e(url($id ? 'page=list&id='.$id : '')) ?>">Abbrechen</a></div></form>
<?php elseif ($page==='list' && $state):
    $id=is_string($_GET['id'] ?? null) ? $_GET['id'] : '';
    $l=App::find($state['lists'],$id); $own=$l['ownerUserId']===$user['id'];
    $wishes=array_filter($state['wishes'],fn($w)=>$w['listId']===$id); ?>
<?= backLink('', 'Alle Wunschlisten') ?>
<section class="list-hero <?= e($l['cover']) ?>"><?php if ($l['image']): ?><img class="hero-cover" src="<?= e(media('lists',$id)) ?>" alt=""><?php endif ?><div class="list-hero-content"><span class="pill"><?= e($l['occasion']) ?><?= $l['eventDate'] ? ' · '.e(dateDE($l['eventDate'])) : '' ?></span><h1><?= e($l['name']) ?></h1><p>Eine Wunschliste von <strong><?= e($l['ownerName']) ?></strong></p><?php if ($l['description']): ?><p class="description"><?= nl2br(e($l['description'])) ?></p><?php endif ?></div><div class="hero-art" aria-hidden="true"><?= icon('gift') ?></div></section>
<div class="section-heading wishes-heading"><div><h2><?= count($wishes) ?> <?= count($wishes)===1 ? 'Wunsch' : 'Wünsche' ?></h2><p class="muted"><?= $own ? 'Deine Reservierungen bleiben eine Überraschung.' : 'Mit Liebe aussuchen. Eine Reservierung ist keine Bestellung.' ?></p></div><?php if ($own): ?><div class="button-group"><a class="button secondary" href="<?= e(url('page=editList&id='.$id)) ?>"><?= icon('edit') ?>Liste bearbeiten</a><a class="button primary" href="<?= e(url('page=wish&listId='.$id)) ?>"><?= icon('plus') ?>Wunsch hinzufügen</a></div><?php endif ?></div>
<div class="card-grid wish-grid"><?php foreach ($wishes as $w) { wishCard($w,$l,$own); } ?></div>
<?php if (!$wishes): ?><?= emptyState('Hier ist noch Platz für Wünsche',$own ? 'Ein Buch, ein Erlebnis oder etwas ganz Eigenes – dein erster Wunsch wartet schon.' : 'Bald gibt es hier Geschenkideen zu entdecken.') ?><?php if ($own): ?><div class="center"><a class="button primary" href="<?= e(url('page=wish&listId='.$id)) ?>">Ersten Wunsch hinzufügen</a></div><?php endif ?><?php endif ?>
<?php if ($own): ?><details class="danger-zone"><summary>Liste löschen</summary><p>Diese Liste mit allen Wünschen und eventuell vorhandenen Reservierungen dauerhaft löschen.</p><form method="post"><?= csrf() ?><?= hidden('action','deleteList') ?><?= hidden('id',$id) ?><label class="check"><input type="checkbox" name="confirm" value="yes" required>Ich möchte die gesamte Liste unwiderruflich löschen.</label><button class="button danger">Liste endgültig löschen</button></form></details><?php endif ?>
<?php elseif ($page==='wish' && $state):
    $id=is_string($_GET['id'] ?? null) ? $_GET['id'] : ($form['id'] ?? '');
    $w=$id ? App::find($state['wishes'],$id) : [];
    $listId=$w['listId'] ?? ($_GET['listId'] ?? $form['listId'] ?? '');
    if (!is_string($listId)) { throw new Problem(422,'Ungültige Listen-ID.'); }
    $l=App::find($state['lists'],$listId);
    if ($l['ownerUserId']!==$user['id']) { throw new Problem(403,'Nur der Eigentümer darf Wünsche bearbeiten.'); }
    $f=$form ?: $w;
    if (!$form && isset($w['priceCents'])) { $f['price']=number_format($w['priceCents']/100,2,',',''); } ?>
<?= backLink('page=list&id='.$listId,$l['name']) ?>
<div class="form-heading"><span class="eyebrow">WAS MACHT DICH GLÜCKLICH?</span><h1><?= $id ? 'Wunsch bearbeiten' : 'Ein neuer Herzenswunsch' ?></h1><p class="muted">Mit Produktlink oder ganz aus deiner Fantasie.</p></div>
<form class="panel edit-panel stack" method="post" enctype="multipart/form-data"><?= csrf() ?><?= hidden('id',$id) ?><?= hidden('listId',$listId) ?>
<div class="import-box"><label for="productUrl">Produktlink (optional)</label><div class="import-row"><input id="productUrl" name="productUrl" type="url" maxlength="2048" value="<?= e($f['productUrl'] ?? '') ?>" placeholder="https://shop.de/dein-wunsch"><button class="button secondary" name="action" value="import" formnovalidate><?= icon('spark') ?>Daten übernehmen</button></div><p class="field-help">Wir versuchen Titel, Preis und Bild zu finden. Bereits ausgefüllte Texte bleiben erhalten. Bitte alle Angaben prüfen.</p></div>
<?php if ($preview): ?><div class="notice <?= !empty($preview['warning']) ? 'soft' : 'success' ?>" role="status"><?= e($preview['warning'] ?: 'Vorschau geladen. Bitte prüfen und erst danach speichern.') ?></div><?php endif ?>
<?= field('title','Dein Wunsch',$f['title'] ?? '','text',true,200) ?>
<?= textarea('description','Beschreibung (optional)',$f['description'] ?? '') ?>
<div class="form-row"><?= field('price','Preis in EUR (optional)',$f['price'] ?? '','text',false,12) ?><?= field('quantity','Gewünschte Menge',$f['quantity'] ?? 1,'number',true,2) ?></div>
<?php if ($id): ?><p class="field-help">Wenn du die Menge änderst, werden eventuell vorhandene Reservierungen zurückgesetzt. Dies geschieht unabhängig vom Reservierungsstand.</p><?php endif ?>
<?php if (($f['useImportedImage'] ?? '')==='yes'): ?><?= hidden('useImportedImage','yes') ?><img class="image-preview" src="<?= e($base) ?>/media.php?type=preview" alt="Importierte Bildvorschau"><p class="field-help">Importiertes Bild wird beim Speichern übernommen.</p><?php endif ?>
<?= imageField($w['image'] ?? false) ?>
<div class="form-actions"><button class="button primary" name="action" value="saveWish">Wunsch speichern</button><a class="button secondary" href="<?= e(url('page=list&id='.$listId)) ?>">Abbrechen</a></div></form>
<?php if ($id): ?><details class="danger-zone narrow"><summary>Wunsch löschen</summary><p>Der Wunsch und eventuell vorhandene Reservierungen werden endgültig entfernt.</p><form method="post"><?= csrf() ?><?= hidden('action','deleteWish') ?><?= hidden('id',$id) ?><label class="check"><input type="checkbox" name="confirm" value="yes" required>Endgültige Löschung bestätigen</label><button class="button danger">Wunsch löschen</button></form></details><?php endif ?>
<?php elseif ($page==='reservations' && $state):
    $reserved=array_filter($state['wishes'],fn($w)=>$w['reservedByMe'] ?? false); ?>
<div class="page-heading"><div><span class="eyebrow">VORFREUDE VERSCHENKEN</span><h1>Meine Reservierungen</h1><p class="muted">Deine kleinen Geheimnisse. Eine Reservierung löst keine Bestellung aus.</p></div><span class="round-emblem"><?= icon('heart') ?></span></div>
<div class="card-grid wish-grid"><?php foreach ($reserved as $w) { $l=App::find($state['lists'],$w['listId']); wishCard($w,$l,false,true); } ?></div>
<?php if (!$reserved): ?><?= emptyState('Noch kein Geschenk ins Auge gefasst?','Entdecke die Listen deiner Lieblingsmenschen und reserviere eine Einheit eines Wunsches.') ?><div class="center"><a class="button primary" href="<?= e(url()) ?>">Wunschlisten entdecken</a></div><?php endif ?>
<?php elseif ($page==='account' && $state): ?>
<?= backLink('', 'Alle Wunschlisten') ?><div class="form-heading"><span class="eyebrow">DEIN PLATZ BEI MYWISH</span><h1>Mein Konto</h1><p class="muted"><?= e($user['username']) ?> · Keine E-Mail-Benachrichtigungen, keine Tracker.</p></div>
<form class="panel edit-panel stack" method="post"><?= csrf() ?><?= hidden('action','account') ?><?= field('name','Anzeigename',$form['name'] ?? $user['name'],'text',true,100) ?><?= field('currentPassword','Aktuelles Passwort','','password',true,72,'current-password') ?><?= field('newPassword','Neues Passwort (optional)','','password',false,72,'new-password') ?><p class="field-help"><?= e(App::PASSWORD_HINT) ?> Leer lassen, um dein Passwort zu behalten. Nach Änderung werden deine anderen Sitzungen ungültig.</p><button class="button primary">Änderungen speichern</button></form>
<div class="narrow account-footer"><form method="post"><?= csrf() ?><?= hidden('action','logout') ?><button class="button secondary">Abmelden</button></form><details class="danger-zone"><summary>Konto löschen</summary><p>Dein Konto, deine Listen und Wünsche sowie deine Reservierungen werden dauerhaft gelöscht. Sicherungen des Betreibers können ältere Daten enthalten.</p><form method="post" class="stack"><?= csrf() ?><?= hidden('action','deleteAccount') ?><?= field('currentPassword','Aktuelles Passwort','','password',true,72,'current-password') ?><label class="check"><input type="checkbox" name="confirm" value="yes" required>Ich bestätige die endgültige Löschung.</label><button class="button danger">Konto endgültig löschen</button></form></details></div>
<?php elseif ($page==='error'): ?><?= emptyState('MyWish ist gerade nicht verfügbar','Bitte die angezeigte Meldung prüfen und später erneut versuchen.') ?>
<?php else: throw new Problem(404,'Diese Seite wurde nicht gefunden.'); endif;
} catch (Throwable $ex) { [$status,$message]=safeError($ex); http_response_code($status); ?><div class="notice error" role="alert"><?= e($message) ?></div><a class="button secondary" href="<?= e(url()) ?>">Zur Übersicht</a><?php } ?>
</main><footer class="footer shell"><a class="brand small" href="<?= e(url()) ?>"><?= icon('gift') ?><?= e($siteName) ?></a><span>Mit Wünschen fängt Freude an.</span><span>Alle Listen sind für angemeldete Nutzer sichtbar. <a href="<?= e(url('page=account')) ?>">Mein Konto</a></span></footer>
</body></html>

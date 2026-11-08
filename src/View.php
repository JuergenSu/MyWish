<?php
declare(strict_types=1);
function icon(string $name): string
{
    $paths=[
        'gift'=>'<rect x="3" y="8" width="18" height="4" rx="1"/><path d="M5 12v8h14v-8M12 8v12M12 8H8.5A2.5 2.5 0 1 1 11 5.5L12 8Zm0 0h3.5A2.5 2.5 0 1 0 13 5.5L12 8Z"/>',
        'heart'=>'<path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1.1-1.1a5.5 5.5 0 0 0-7.8 7.8L12 21l8.8-8.6a5.5 5.5 0 0 0 0-7.8Z"/>',
        'grid'=>'<rect x="3" y="3" width="7" height="7" rx="2"/><rect x="14" y="3" width="7" height="7" rx="2"/><rect x="3" y="14" width="7" height="7" rx="2"/><rect x="14" y="14" width="7" height="7" rx="2"/>',
        'plus'=>'<path d="M12 5v14M5 12h14"/>',
        'arrow'=>'<path d="M4 12h16m-6-6 6 6-6 6"/>',
        'back'=>'<path d="M20 12H4m6-6-6 6 6 6"/>',
        'search'=>'<circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 5 5"/>',
        'check'=>'<path d="m5 12 4 4L19 6"/>',
        'info'=>'<circle cx="12" cy="12" r="9"/><path d="M12 11v6m0-10v1"/>',
        'edit'=>'<path d="m15 4 5 5M4 20l5-1L21 7a2 2 0 0 0-5-5L4 14v6Z"/>',
        'spark'=>'<path d="m12 3 2.7 6.3L21 12l-6.3 2.7L12 21l-2.7-6.3L3 12l6.3-2.7L12 3Z"/>',
        'external'=>'<path d="M14 3h7v7m0-7L10 14M10 3H4a1 1 0 0 0-1 1v16a1 1 0 0 0 1 1h16a1 1 0 0 0 1-1v-6"/>',
        'calendar'=>'<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M7 3v4m10-4v4M3 11h18"/>',
    ];
    return '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'.($paths[$name] ?? $paths['gift']).'</svg>';
}
function csrf(): string { return hidden('csrf',$_SESSION['csrf']); }
function hidden(string $key,string $value): string { return '<input type="hidden" name="'.e($key).'" value="'.e($value).'">'; }
function field(string $name,string $label,mixed $value='',string $type='text',bool $required=false,int $max=200,string $autocomplete=''): string
{
    $extra=$type==='number' ? ' min="1" max="99" step="1"' : ' maxlength="'.$max.'"';
    if ($type==='password' && $autocomplete==='new-password') {
        $extra.=' minlength="8" pattern=".{8,}" title="'.e(MyWish\App::PASSWORD_HINT).'"';
    }
    return '<label>'.e($label).'<input name="'.e($name).'" type="'.e($type).'" value="'.e(is_scalar($value) ? $value : '').'"'.($required ? ' required' : '').$extra.($autocomplete ? ' autocomplete="'.e($autocomplete).'"' : '').($name==='price' ? ' inputmode="decimal"' : '').'></label>';
}
function textarea(string $name,string $label,mixed $value): string { return '<label>'.e($label).'<textarea name="'.e($name).'" rows="4" maxlength="4000">'.e(is_scalar($value) ? $value : '').'</textarea></label>'; }
function imageField(bool $hasImage): string { return '<label>Eigenes Bild (optional)<input type="file" name="image" accept="image/jpeg,image/png,image/webp"></label><p class="field-help">JPEG, PNG oder WebP · max. 2 MB und 16 Megapixel. Bilder werden geschützt gespeichert.</p>'.($hasImage ? '<label class="check"><input type="checkbox" name="removeImage" value="yes">Vorhandenes Bild entfernen</label>' : ''); }
function media(string $type,string $id): string { global $base; return $base.'/media.php?type='.$type.'&id='.$id; }
function imageSourceLink(string $source): string { return $source==='' ? '' : '<p class="field-help"><a href="'.e($source).'" target="_blank" rel="noopener noreferrer">Bildquelle<span class="sr-only"> (öffnet neuen Tab)</span></a></p>'; }
function initials(string $name): string { return function_exists('mb_substr') ? mb_strtoupper(mb_substr($name,0,1)) : strtoupper(substr($name,0,1)); }
function dateDE(string $date): string { return substr($date,8,2).'.'.substr($date,5,2).'.'.substr($date,0,4); }
function backLink(string $query,string $label): string { return '<a class="back-link" href="'.e(url($query)).'">'.icon('back').e($label).'</a>'; }
function emptyState(string $title,string $text): string { return '<div class="empty"><span class="empty-icon">'.icon('gift').'</span><h3>'.e($title).'</h3><p>'.e($text).'</p></div>'; }
function listCard(array $l,bool $own): void { ?>
<article class="list-card"><a class="card-link" href="<?= e(url('page=list&id='.$l['id'])) ?>"><div class="card-cover <?= e($l['cover']) ?>"><?php if ($l['image']): ?><img src="<?= e(media('lists',$l['id'])) ?>" alt="" loading="lazy"><?php else: ?><div class="cover-decoration" aria-hidden="true"><?= icon($l['occasion']==='Hochzeit' ? 'heart' : 'gift') ?><span class="decor-star">✦</span><span class="decor-dot">·</span></div><?php endif ?><span class="pill"><?= e($l['occasion']) ?></span></div><div class="card-body"><div class="card-person"><span class="avatar tiny"><?= e(initials($l['ownerName'])) ?></span><?= $own ? 'Für mich' : e($l['ownerName']) ?></div><h3><?= e($l['name']) ?></h3><div class="card-meta"><span><?= $l['eventDate'] ? icon('calendar').' '.e(dateDE($l['eventDate'])) : 'Für kleine & große Momente' ?></span><span><?= $l['wishCount'] ?> Wünsche</span></div></div></a><?= imageSourceLink($l['imageSourceUrl'] ?? '') ?></article>
<?php }
function wishCard(array $w,array $l,bool $own,bool $reservationPage=false): void { ?>
<article class="wish-card" data-wish-id="<?= e($w['id']) ?>" data-quantity="<?= $w['quantity'] ?>"><div class="wish-image <?= e($l['cover']) ?>"><?php if ($w['image']): ?><img src="<?= e(media('wishes',$w['id'])) ?>" alt="<?= e($w['title']) ?>" loading="lazy"><?php else: ?><span class="wish-placeholder" aria-hidden="true"><?= icon('gift') ?></span><?php endif ?><?php if ($w['quantity']>1): ?><span class="quantity"><?= $w['quantity'] ?> Stück gewünscht</span><?php endif ?></div><div class="wish-body">
<?php if ($reservationPage): ?><a class="wish-list-link" href="<?= e(url('page=list&id='.$l['id'])) ?>"><?= e($l['ownerName']) ?> · <?= e($l['name']) ?></a><?php endif ?>
<?= imageSourceLink($w['imageSourceUrl'] ?? '') ?><h3><?= e($w['title']) ?></h3><p class="price"><?= $w['priceCents']===null ? 'Ein Herzenswunsch' : e(number_format($w['priceCents']/100,2,',','.')).' €' ?></p>
<?php if ($w['description']): ?><p class="wish-description"><?= nl2br(e($w['description'])) ?></p><?php endif ?>
<?php if ($w['productUrl']): ?><a class="shop-link" href="<?= e($w['productUrl']) ?>" target="_blank" rel="noopener noreferrer">Zum Produkt <?= icon('external') ?><span class="sr-only"> (öffnet neuen Tab)</span></a><?php endif ?>
<div class="wish-actions" aria-live="polite">
<?php if ($own): ?><a class="button secondary full" href="<?= e(url('page=wish&id='.$w['id'])) ?>"><?= icon('edit') ?>Wunsch bearbeiten</a>
<?php else: ?><p class="availability <?= $w['reservedByMe'] ? 'mine' : ($w['available']>0 ? 'free' : 'taken') ?>"><?= icon($w['reservedByMe'] ? 'check' : 'gift') ?><?= $w['reservedByMe'] ? 'Eine Einheit für dich reserviert' : ($w['available']>0 ? ($w['quantity']>1 ? $w['available'].' von '.$w['quantity'].' verfügbar' : 'Noch zu haben') : 'Bereits reserviert') ?></p>
<?php if ($w['reservedByMe'] || $w['available']>0): ?><form method="post" class="reservation-form" data-api="<?= e($GLOBALS['base']) ?>/api/index.php"><?= csrf() ?><?= hidden('action',$w['reservedByMe'] ? 'unreserve' : 'reserve') ?><?= hidden('id',$w['id']) ?><?= hidden('returnPage',$reservationPage ? 'reservations' : 'list') ?><button class="button <?= $w['reservedByMe'] ? 'secondary' : 'primary' ?> full"><?= icon($w['reservedByMe'] ? 'check' : 'heart') ?><?= $w['reservedByMe'] ? 'Reservierung freigeben' : ($w['quantity']>1 ? 'Eine Einheit reservieren' : 'Für mich reservieren') ?></button></form><?php else: ?><button class="button secondary full" disabled>Schon verplant</button><?php endif ?><?php endif ?>
</div></div></article>
<?php }

function googleButton(string $purpose,string $label): string
{
    global $base;
    return '<form method="post" action="'.e($base.'/auth/google/start.php').'" class="google-form">'.csrf().hidden('purpose',$purpose).'<button type="submit" class="button google-button full"><img src="'.e($base).'/assets/google-g.svg" alt="" width="20" height="20">'.e($label).'</button></form>';
}

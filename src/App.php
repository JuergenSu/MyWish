<?php
declare(strict_types=1);
namespace MyWish;

final class App
{
    public const PASSWORD_HINT = 'Mindestens 8 Zeichen.';

    public function __construct(public Store $store, private ?string $registrationOverride = null)
    {
        if (!in_array($registrationOverride, [null,'open','closed'], true)) { throw new Problem(503,'Registrierungsmodus in config.php muss open oder closed sein.'); }
    }
    public static function id(): string { return bin2hex(random_bytes(16)); }
    public static function now(): string { return gmdate('c'); }
    public static function text(array $in, string $key, int $max = 200, bool $required = false): string
    {
        $value = $in[$key] ?? '';
        if (!is_string($value) || !preg_match('//u', $value)) { throw new Problem(422, 'Ungültige Eingabe: '.$key); }
        $value = trim(str_replace(["\r\n", "\r"], "\n", $value));
        if (class_exists('Normalizer')) { $value = \Normalizer::normalize($value) ?: $value; }
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $value) || strlen($value) > $max || ($required && $value === '')) { throw new Problem(422, 'Bitte '.$key.' ausfüllen (maximal '.$max.' Bytes).'); }
        return $value;
    }
    public static function username(array $in): string
    {
        $name = strtolower(self::text($in, 'username', 120, true));
        if (!preg_match('/^[a-z0-9][a-z0-9._@+\-]{2,119}$/D', $name)) { throw new Problem(422, 'Benutzername: 3–120 Zeichen, Buchstaben a–z, Zahlen oder . _ @ + -'); }
        return $name;
    }
    public static function password(array $in, string $key = 'password'): string
    {
        $pass = $in[$key] ?? null;
        if (!is_string($pass) || strlen($pass) > 72 || str_contains($pass, "\0")
            || !preg_match('//u', $pass) || preg_match_all('/./us', $pass) < 8) {
            throw new Problem(422, self::PASSWORD_HINT.' Maximal 72 Bytes.');
        }
        return $pass;
    }
    public static function webUrl(string $value): string
    {
        if ($value === '') { return ''; }
        $p = parse_url($value);
        if (strlen($value)>2048 || !filter_var($value, FILTER_VALIDATE_URL) || !in_array(strtolower($p['scheme'] ?? ''), ['http','https'], true) || isset($p['user']) || isset($p['pass'])) { throw new Problem(422, 'Bitte eine gültige HTTP- oder HTTPS-Adresse ohne Zugangsdaten verwenden.'); }
        return $value;
    }
    public static function money(string $value): ?int
    {
        if ($value === '') { return null; }
        if (!preg_match('/^(0|[1-9][0-9]{0,6})(?:[.,]([0-9]{1,2}))?$/D', $value, $m)) { throw new Problem(422, 'Preis bitte z. B. als 39,99 angeben (keine Tausendertrennzeichen).'); }
        return (int)$m[1]*100 + (int)str_pad($m[2] ?? '', 2, '0');
    }
    public static function find(array $rows, string $id): array
    {
        foreach ($rows as $row) { if ($row['id'] === $id) { return $row; } }
        throw new Problem(404, 'Der Eintrag wurde nicht gefunden.');
    }
    public static function user(array $s): array
    {
        if (!isset($_SESSION['userId'])) { throw new Problem(401, 'Bitte zuerst anmelden.'); }
        foreach ($s['users'] as $u) {
            if ($u['id'] === $_SESSION['userId'] && $u['sessionVersion'] === ($_SESSION['version'] ?? null)) { return $u; }
        }
        throw new Problem(401, 'Die Sitzung ist abgelaufen. Bitte erneut anmelden.');
    }
    public static function publicUser(array $u): array { return array_intersect_key($u, array_flip(['id','name','username','googleEmail'])) + ['hasLocalPassword'=>is_string($u['passwordHash']), 'googleLinked'=>$u['googleSub']!==null]; }
    private static function owner(array $s, string $listId, string $userId): array
    {
        $list = self::find($s['lists'], $listId);
        if ($list['ownerUserId'] !== $userId) { throw new Problem(403, 'Nur der Eigentümer darf diese Liste bearbeiten.'); }
        return $list;
    }
    private static function removeList(array &$s, string $id): void
    {
        $wishIds = array_column(array_filter($s['wishes'], fn($w)=>$w['listId']===$id), 'id');
        $s['reservations'] = array_values(array_filter($s['reservations'], fn($r)=>!in_array($r['wishId'], $wishIds, true)));
        $s['wishes'] = array_values(array_filter($s['wishes'], fn($w)=>$w['listId']!==$id));
        $s['lists'] = array_values(array_filter($s['lists'], fn($l)=>$l['id']!==$id));
    }
    public function snapshot(): array
    {
        return $this->store->transaction(function (&$s) {
            if (!$s['settings']) { return ['installed'=>false]; }
            $u = self::user($s);
            $lists = [];
            foreach ($s['lists'] as $l) {
                $owner = self::find($s['users'], $l['ownerUserId']);
                $l['ownerName'] = $owner['name'];
                $l['wishCount'] = count(array_filter($s['wishes'], fn($w)=>$w['listId']===$l['id']));
                $l['image'] = $l['image'] !== null;
                $lists[] = $l;
            }
            $wishes = [];
            foreach ($s['wishes'] as $w) {
                $l = self::find($s['lists'], $w['listId']);
                if ($l['ownerUserId'] !== $u['id']) {
                    $rs = array_filter($s['reservations'], fn($r)=>$r['wishId']===$w['id']);
                    $w['available'] = $w['quantity'] - count($rs);
                    $w['reservedByMe'] = count(array_filter($rs, fn($r)=>$r['userId']===$u['id'])) > 0;
                }
                $w['image'] = $w['image'] !== null;
                $wishes[] = $w;
            }
            return ['installed'=>true,'siteName'=>$s['settings']['siteName'], 'user'=>self::publicUser($u), 'lists'=>$lists, 'wishes'=>$wishes];
        });
    }
    public function settings(): ?array { return $this->store->transaction(fn(&$s)=>$s['settings']===null ? null : array_replace($s['settings'], $this->registrationOverride!==null ? ['registration'=>$this->registrationOverride] : [])); }
    public function act(string $action, array $in): array
    {
        $result = $this->store->transaction(function (&$s) use ($action, $in) {
            if ($action === 'setup') {
                if ($s['settings'] !== null) { throw new Problem(409, 'MyWish ist bereits eingerichtet.'); }
                $name = self::text($in, 'name', 100, true);
                $username = self::username($in);
                $hash = password_hash(self::password($in), PASSWORD_DEFAULT);
                $registration = $in['registration'] ?? 'open';
                if (!in_array($registration, ['open','closed'], true)) { throw new Problem(422, 'Ungültiger Registrierungsmodus.'); }
                $s['settings'] = ['siteName'=>self::text($in, 'siteName', 80, true), 'registration'=>$registration, 'createdAt'=>self::now()];
                $u = ['id'=>self::id(),'username'=>$username,'name'=>$name,'passwordHash'=>$hash,'sessionVersion'=>1,'createdAt'=>self::now(),'googleSub'=>null,'googleEmail'=>null];
                $s['users'][] = $u;
                return ['login'=>$u, 'message'=>'Willkommen bei MyWish!'];
            }
            if (!$s['settings']) { throw new Problem(409, 'Bitte MyWish zuerst einrichten.'); }
            if (in_array($action, ['login','register'], true)) {
                $ip = hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? 'local'));
                $s['attempts'] = array_values(array_filter($s['attempts'], fn($a)=>$a['at'] > time()-900));
                if (count(array_filter($s['attempts'], fn($a)=>$a['ip']===$ip && ($a['kind'] ?? 'login')===$action))>=($action==='register' ? 30 : 10)) { return new Problem(429, 'Zu viele Versuche. Bitte nach 15 Minuten erneut versuchen.'); }
                $username = self::username($in);
                $existing = null;
                foreach ($s['users'] as $u) { if ($u['username'] === $username) { $existing = $u; } }
                if ($action === 'login') {
                    $pass = $in['password'] ?? '';
                    $valid = is_string($pass) && strlen($pass)<=72 && password_verify($pass, $existing['passwordHash'] ?? '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.');
                    if (!$existing || !is_string($existing['passwordHash']) || !$valid) { $s['attempts'][]=['ip'=>$ip,'at'=>time(),'kind'=>$action]; return new Problem(401, 'Anmeldung nicht möglich. Bitte Zugangsdaten prüfen.'); }
                    $s['attempts'] = array_values(array_filter($s['attempts'], fn($a)=>$a['ip']!==$ip || ($a['kind'] ?? 'login')!=='login'));
                    return ['login'=>$existing,'message'=>'Schön, dass du da bist!'];
                }
                if (($this->registrationOverride ?? $s['settings']['registration']) !== 'open') { throw new Problem(403, 'Die Registrierung ist geschlossen.'); }
                $s['attempts'][]=['ip'=>$ip,'at'=>time(),'kind'=>$action];
                if ($existing) { return new Problem(409, 'Dieses Benutzerkonto kann nicht registriert werden. Bitte einen anderen Namen verwenden.'); }
                $u=['id'=>self::id(),'username'=>$username,'name'=>self::text($in,'name',100,true),'passwordHash'=>password_hash(self::password($in), PASSWORD_DEFAULT),'sessionVersion'=>1,'createdAt'=>self::now(),'googleSub'=>null,'googleEmail'=>null];
                $s['users'][]=$u;
                return ['login'=>$u,'message'=>'Dein Konto ist bereit.'];
            }
            $u=self::user($s); $uid=$u['id'];
            $id=self::text($in,'id',32);
            if ($id!=='' && !preg_match('/^[a-f0-9]{32}$/D',$id)) { throw new Problem(422,'Ungültige ID.'); }
            switch ($action) {
                case 'logout': return ['logout'=>true,'message'=>'Du bist abgemeldet.'];
                case 'account':
                    if (!self::verifyCurrent($in,$u)) { throw new Problem(403,'Bitte dein aktuelles Passwort bestätigen oder dich erneut mit Google anmelden.'); }
                    foreach ($s['users'] as &$row) { if ($row['id']===$uid) {
                        $row['name']=self::text($in,'name',100,true);
                        if (($in['newPassword'] ?? '')!=='') { $row['passwordHash']=password_hash(self::password($in,'newPassword'),PASSWORD_DEFAULT); $row['sessionVersion']++; }
                        $updated=$row;
                    } } unset($row);
                    return ['login'=>$updated,'message'=>'Konto aktualisiert.'];
                case 'unlinkGoogle':
                    if (!is_string($u['passwordHash']) || !self::verifyCurrent($in,$u)) { throw new Problem(403,'Zum Trennen zuerst ein lokales Passwort festlegen und bestätigen.'); }
                    foreach ($s['users'] as &$row) { if ($row['id']===$uid) { $row['googleSub']=null; $row['googleEmail']=null; $row['sessionVersion']++; $updated=$row; } } unset($row);
                    return ['login'=>$updated,'message'=>'Google-Verknüpfung getrennt.'];
                case 'deleteAccount':
                    self::confirm($in);
                    if (!self::verifyCurrent($in,$u)) { throw new Problem(403,'Bitte dein aktuelles Passwort bestätigen oder dich erneut mit Google anmelden.'); }
                    foreach ($s['lists'] as $l) { if ($l['ownerUserId']===$uid) { self::removeList($s,$l['id']); } }
                    $s['reservations']=array_values(array_filter($s['reservations'],fn($r)=>$r['userId']!==$uid));
                    $s['users']=array_values(array_filter($s['users'],fn($r)=>$r['id']!==$uid));
                    return ['logout'=>true,'message'=>'Dein Konto und deine Listen wurden gelöscht.'];
                case 'saveList':
                    if ($id!=='') { $old=self::owner($s,$id,$uid); }
                    $occasion=self::text($in,'occasion',30,true);
                    if (!in_array($occasion,['Geburtstag','Weihnachten','Hochzeit','Anderes'],true)) { throw new Problem(422,'Bitte einen gültigen Anlass wählen.'); }
                    $date=self::text($in,'eventDate',10);
                    if ($date!=='' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$date) || !checkdate((int)substr($date,5,2),(int)substr($date,8,2),(int)substr($date,0,4)))) { throw new Problem(422,'Bitte ein gültiges Datum eingeben.'); }
                    $l=['id'=>$id ?: self::id(),'ownerUserId'=>$uid,'name'=>self::text($in,'name',160,true),'description'=>self::text($in,'description',4000),'occasion'=>$occasion,'eventDate'=>$date,'cover'=>self::cover($in),'image'=>self::image($in,$old['image'] ?? null),'createdAt'=>$old['createdAt'] ?? self::now(),'updatedAt'=>self::now()];
                    self::upsert($s['lists'],$l); return ['id'=>$l['id'],'message'=>'Liste gespeichert.'];
                case 'deleteList': self::confirm($in); self::owner($s,$id,$uid); self::removeList($s,$id); return ['message'=>'Liste gelöscht.'];
                case 'saveWish':
                    $listId=self::text($in,'listId',32,true); self::owner($s,$listId,$uid);
                    if ($id!=='') { $old=self::find($s['wishes'],$id); if ($old['listId']!==$listId) { throw new Problem(403,'Dieser Wunsch gehört zu einer anderen Liste.'); } }
                    $qty=$in['quantity'] ?? '1';
                    if (!is_scalar($qty) || !preg_match('/^[1-9][0-9]?$/D',(string)$qty)) { throw new Problem(422,'Die Menge muss zwischen 1 und 99 liegen.'); }
                    $w=['id'=>$id ?: self::id(),'listId'=>$listId,'title'=>self::text($in,'title',200,true),'description'=>self::text($in,'description',4000),'priceCents'=>self::money(self::text($in,'price',12)),'currency'=>'EUR','productUrl'=>self::webUrl(self::text($in,'productUrl',2048)),'quantity'=>(int)$qty,'image'=>self::image($in,$old['image'] ?? null),'createdAt'=>$old['createdAt'] ?? self::now(),'updatedAt'=>self::now()];
                    // Jede Mengenänderung setzt Reservierungen zurück, unabhängig von ihrem Bestand.
                    if (isset($old) && $old['quantity']!==$w['quantity']) { $s['reservations']=array_values(array_filter($s['reservations'],fn($r)=>$r['wishId']!==$id)); }
                    self::upsert($s['wishes'],$w); return ['id'=>$w['id'],'listId'=>$listId,'message'=>'Wunsch gespeichert.'];
                case 'deleteWish':
                    self::confirm($in); $w=self::find($s['wishes'],$id); self::owner($s,$w['listId'],$uid);
                    $s['wishes']=array_values(array_filter($s['wishes'],fn($r)=>$r['id']!==$id));
                    $s['reservations']=array_values(array_filter($s['reservations'],fn($r)=>$r['wishId']!==$id));
                    return ['listId'=>$w['listId'],'message'=>'Wunsch gelöscht.'];
                case 'reserve': case 'unreserve':
                    $w=self::find($s['wishes'],$id); $l=self::find($s['lists'],$w['listId']);
                    if ($l['ownerUserId']===$uid) { throw new Problem(403,'Eigene Wünsche können nicht reserviert werden.'); }
                    $rs=array_filter($s['reservations'],fn($r)=>$r['wishId']===$id);
                    $mine=array_filter($rs,fn($r)=>$r['userId']===$uid);
                    if ($action==='reserve') {
                        if ($mine || count($rs)>=$w['quantity']) { throw new Problem(409,'Bereits reserviert. Bitte die Liste aktualisieren.'); }
                        $s['reservations'][]=['id'=>self::id(),'wishId'=>$id,'userId'=>$uid,'createdAt'=>self::now()];
                    } else {
                        if (!$mine) { throw new Problem(403,'Du kannst nur eigene Reservierungen freigeben.'); }
                        $s['reservations']=array_values(array_filter($s['reservations'],fn($r)=>!($r['wishId']===$id && $r['userId']===$uid)));
                    }
                    return ['listId'=>$w['listId'],'available'=>$w['quantity']-count($rs)+($action==='reserve' ? -1 : 1),'reservedByMe'=>$action==='reserve','message'=>$action==='reserve' ? 'Eine Einheit ist für dich reserviert. Dies löst keine Bestellung aus.' : 'Reservierung freigegeben.'];
                default: throw new Problem(404,'Diese Aktion gibt es nicht.');
            }
        });
        if ($result instanceof Problem) { throw $result; }
        if (isset($result['login'])) {
            self::establishSession($result['login']); unset($result['login']);
        }
        if (isset($result['logout'])) { $_SESSION=[]; session_regenerate_id(true); $_SESSION['csrf']=bin2hex(random_bytes(32)); unset($result['logout']); }
        return $result;
    }
    public static function establishSession(array $user): void
    {
        session_regenerate_id(true);
        $_SESSION['userId']=$user['id']; $_SESSION['version']=$user['sessionVersion']; $_SESSION['csrf']=bin2hex(random_bytes(32));
        unset($_SESSION['googleReauthenticatedUser'],$_SESSION['googleReauthenticatedSub'],$_SESSION['googleReauthenticatedAt']);
    }

    // Ausschließlich nach Prüfung des Google-ID-Tokens durch den OIDC-Callback aufrufen.
    public function googleIdentity(array $identity,string $purpose,array $pending): array
    {
        $user=$this->store->transaction(function(&$s) use ($identity,$purpose,$pending) {
            if (!$s['settings']) { throw new Problem(409,'Bitte MyWish zuerst einrichten.'); }
            $linked=null;
            foreach ($s['users'] as $row) { if ($row['googleSub']===$identity['sub']) { $linked=$row; } }
            if ($purpose!=='login') {
                $current=self::user($s);
                if ($current['id']!==($pending['userId'] ?? null) || $current['sessionVersion']!==($pending['version'] ?? null)) { throw new Problem(403,'Deine Sitzung hat sich geändert. Bitte erneut beginnen.'); }
                if ($purpose==='reauthenticate') {
                    if ($current['googleSub']!==$identity['sub']) { throw new Problem(403,'Bitte dasselbe Google-Konto wie bei deiner Anmeldung verwenden.'); }
                    return $current;
                }
                if ($purpose!=='link' || ($linked && $linked['id']!==$current['id']) || ($current['googleSub']!==null && $current['googleSub']!==$identity['sub'])) { throw new Problem(409,'Dieses Google-Konto kann nicht verknüpft werden.'); }
                foreach ($s['users'] as &$row) { if ($row['id']===$current['id']) {
                    $row['googleSub']=$identity['sub']; $row['googleEmail']=$identity['email']; $row['sessionVersion']++; $current=$row;
                } } unset($row);
                return $current;
            }
            if ($linked) {
                foreach ($s['users'] as &$row) { if ($row['id']===$linked['id']) { $row['googleEmail']=$identity['email']; $linked=$row; } } unset($row);
                return $linked;
            }
            if (($this->registrationOverride ?? $s['settings']['registration'])!=='open') { throw new Problem(403,'Die Registrierung ist geschlossen. Bestehende Konten können weiterhin angemeldet werden.'); }
            $ip=hash('sha256',$_SERVER['REMOTE_ADDR'] ?? 'local');
            $s['attempts']=array_values(array_filter($s['attempts'],fn($a)=>$a['at']>time()-900));
            if (count(array_filter($s['attempts'],fn($a)=>$a['ip']===$ip && $a['kind']==='register'))>=30) { throw new Problem(429,'Zu viele Registrierungen. Bitte später erneut versuchen.'); }
            $username=strtolower($identity['email']);
            if (!preg_match('/^[a-z0-9][a-z0-9._@+\-]{2,119}$/D',$username)) { $username='google-'.substr(hash('sha256',$identity['sub']),0,24); }
            foreach ($s['users'] as $row) { if ($row['username']===$username) { throw new Problem(409,'Es gibt bereits ein Konto mit dieser Kennung. Bitte mit deinem bisherigen Passwort anmelden und Google unter Mein Konto verknüpfen.'); } }
            $user=['id'=>self::id(),'username'=>$username,'name'=>$identity['name'],'passwordHash'=>null,'sessionVersion'=>1,'createdAt'=>self::now(),'googleSub'=>$identity['sub'],'googleEmail'=>$identity['email']];
            $s['users'][]=$user; $s['attempts'][]=['ip'=>$ip,'at'=>time(),'kind'=>'register']; return $user;
        });
        self::establishSession($user);
        if ($purpose==='reauthenticate') {
            $_SESSION['googleReauthenticatedUser']=$user['id']; $_SESSION['googleReauthenticatedSub']=$user['googleSub']; $_SESSION['googleReauthenticatedAt']=time();
        }
        return self::publicUser($user);
    }

    public static function verifyCurrent(array $in,array $user): bool
    {
        $pass=$in['currentPassword'] ?? null;
        if (is_string($user['passwordHash'])) { return is_string($pass) && strlen($pass)<=72 && password_verify($pass,$user['passwordHash']); }
        return ($_SESSION['googleReauthenticatedUser'] ?? null)===$user['id'] && ($_SESSION['googleReauthenticatedSub'] ?? null)===$user['googleSub'] && ($_SESSION['googleReauthenticatedAt'] ?? 0)>time()-300;
    }
    private static function confirm(array $in): void { if (($in['confirm'] ?? '')!=='yes') { throw new Problem(422,'Bitte die Löschung ausdrücklich bestätigen.'); } }
    private static function upsert(array &$rows,array $new): void { foreach ($rows as &$row) { if ($row['id']===$new['id']) { $row=$new; return; } } unset($row); $rows[]=$new; }
    private static function cover(array $in): string { $v=self::text($in,'cover',20) ?: 'lavender'; if (!in_array($v,['lavender','peach','mint','sky'],true)) { throw new Problem(422,'Ungültiges Cover.'); } return $v; }
    public static function image(array $in,?array $old): ?array
    {
        if (($in['removeImage'] ?? '')==='yes') { return null; }
        if (($in['useImportedImage'] ?? '')==='yes' && isset($_SESSION['importImage']) && time()-$_SESSION['importImage']['at']<1800) { $old=$_SESSION['importImage']['image']; }
        $file=$in['_image'] ?? null;
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_NO_FILE) { return $old; }
        if (!is_array($file) || !is_string($file['tmp_name'] ?? null) || !is_uploaded_file($file['tmp_name']) || ($file['error'] ?? 1)!==UPLOAD_ERR_OK || ($file['size'] ?? 0)>2*1024*1024) { throw new Problem(422,'Bild bitte als JPEG, PNG oder WebP mit maximal 2 MB hochladen.'); }
        $bytes=@file_get_contents($file['tmp_name']);
        return self::imageBytes($bytes ?: '');
    }
    public static function imageBytes(string $bytes): array
    {
        if (strlen($bytes)>2*1024*1024) { throw new Problem(422,'Das Bild ist zu groß (maximal 2 MB).'); }
        $info=@getimagesizefromstring($bytes);
        if (!$info || !in_array($info['mime'],['image/jpeg','image/png','image/webp'],true) || $info[0]>6000 || $info[1]>6000 || $info[0]*$info[1]>16000000) { throw new Problem(422,'Ungültiges Bild. Erlaubt sind JPEG, PNG und WebP bis 16 Megapixel.'); }
        return ['mime'=>$info['mime'],'data'=>base64_encode($bytes)];
    }
}

<?php
declare(strict_types=1);
namespace MyWish;

final class Problem extends \RuntimeException
{
    public function __construct(public int $status, string $message, public ?string $reason = null) { parent::__construct($message); }
}

require_once __DIR__.'/DiskStorage.php';

final class Store
{
    public const GUARD = "<?php http_response_code(404); exit; __halt_compiler();\n";
    public const VERSION = 4;
    private DiskStorage $disk;
    public function __construct(private string $directory,?string $dataDirectory=null)
    {
        if (!is_dir($directory) || !is_writable($directory)) {
            throw new Problem(503, 'Der Datenordner fehlt oder ist nicht beschreibbar. Bitte Dateirechte beim Webspace-Anbieter prüfen.');
        }
        $this->directory=realpath($directory);
        $web=is_string($_SERVER['DOCUMENT_ROOT'] ?? null) && $_SERVER['DOCUMENT_ROOT']!=='' ? $_SERVER['DOCUMENT_ROOT'] : null;
        $this->disk=new DiskStorage($this->directory,$dataDirectory,$web);
    }
    public static function empty(): array
    {
        return ['schemaVersion'=>self::VERSION, 'settings'=>null, 'users'=>[], 'lists'=>[], 'wishes'=>[], 'reservations'=>[], 'attempts'=>[]];
    }
    private function locked(int $mode,callable $callback): mixed
    {
        $lock=@fopen($this->directory.'/lock.php','c+');
        if (!$lock || !flock($lock,$mode)) { throw new Problem(503,'Die Datenspeicherung ist gerade nicht verfügbar. Bitte erneut versuchen.'); }
        try { return $callback(); }
        finally { flock($lock,LOCK_UN);fclose($lock); }
    }
    private function dataLocked(int $mode,callable $callback): mixed
    {
        $path=$this->disk->directory().'/transaction.lock';$lock=@fopen($path,'c+');
        if (!$lock || !flock($lock,$mode)) { if (is_resource($lock)) { fclose($lock); }throw new Problem(503,'Der JSON-Datenbestand kann gerade nicht gesperrt werden.'); }
        @chmod($path,0600);
        try { return $callback(); }finally { flock($lock,LOCK_UN);fclose($lock); }
    }
    public function transaction(callable $callback): mixed
    {
        return $this->locked(LOCK_EX,function() use ($callback) {
            $this->disk->initialize();
            return $this->dataLocked(LOCK_EX,function() use ($callback) {
                $state=$this->disk->load();$before=$state;$result=$callback($state);
                if ($state!==$before) { $this->disk->commit($state,$before); }
                return $result;
            });
        });
    }
    public function read(callable $callback): mixed
    {
        if (!$this->disk->hasLayout()) { $this->transaction(static fn(&$state)=>null); }
        return $this->locked(LOCK_SH,fn()=>$this->dataLocked(LOCK_SH,function() use ($callback) { $state=$this->disk->load();return $callback($state); }));
    }
    public function settings(): ?array
    {
        if (!$this->disk->hasLayout()) { $this->transaction(static fn(&$state)=>null); }
        return $this->locked(LOCK_SH,fn()=>$this->dataLocked(LOCK_SH,fn()=>$this->disk->settings()));
    }
    public function openImage(array $image): mixed { return $this->locked(LOCK_SH,fn()=>$this->dataLocked(LOCK_SH,fn()=>$this->disk->openImage($image))); }
    public function verify(): array
    {
        if (!$this->disk->hasLayout()) { $this->transaction(static fn(&$state)=>null); }
        return $this->locked(LOCK_SH,fn()=>$this->dataLocked(LOCK_SH,fn()=>$this->disk->verify()));
    }
    public function dataDirectory(): string
    {
        if (!$this->disk->hasLayout()) { $this->transaction(static fn(&$state)=>null); }
        return $this->disk->directory();
    }
    public function backup(string $target): void
    {
        if (!$this->disk->hasLayout()) { throw new Problem(503,'Bitte den Datenbestand zuerst migrieren. Eine Altsicherung besteht aus der ursprünglichen state.php.'); }
        $this->locked(LOCK_SH,fn()=>$this->dataLocked(LOCK_SH,fn()=>$this->disk->backup($target)));
    }
    public function cleanup(): array
    {
        if (!$this->disk->hasLayout()) { throw new Problem(503,'Bitte den Datenbestand zuerst migrieren und prüfen.'); }
        return $this->locked(LOCK_EX,fn()=>$this->dataLocked(LOCK_EX,fn()=>$this->disk->cleanup()));
    }
    public static function checkMigrationMemory(int $bytes): void
    {
        $limit=ini_parse_quantity(ini_get('memory_limit'));
        if ($limit>0 && memory_get_usage(true)+$bytes*4+16*1024*1024>$limit) { throw new Problem(503,'Für die Migration reicht der PHP-Arbeitsspeicher nicht aus. Bitte den Migrationslauf per CLI mit höherem memory_limit starten. Der bisherige Datenbestand bleibt unverändert.'); }
    }
    public static function decode(string $data): array
    {
        if (!str_starts_with($data, self::GUARD)) { throw new Problem(503, 'Die Datendatei ist beschädigt. Bitte eine geprüfte Sicherung wiederherstellen.'); }
        try { $state = json_decode(substr($data, strlen(self::GUARD)), true, 64, JSON_THROW_ON_ERROR); }
        catch (\Throwable) { throw new Problem(503, 'Die Datendatei ist beschädigt. Bitte eine geprüfte Sicherung wiederherstellen.'); }
        if (!is_array($state)) { throw new Problem(503, 'Ungültiges Datenformat.'); }
        self::validate($state);
        return $state;
    }
    public static function validate(array $s): void
    {
        if (!in_array($s['schemaVersion'] ?? null, [1,2,3,self::VERSION], true)) { throw new Problem(503, 'Unbekannte Datenversion. Bitte passende MyWish-Version verwenden; Daten nicht überschreiben.'); }
        foreach (['users','lists','wishes','reservations','attempts'] as $key) {
            if (!isset($s[$key]) || !is_array($s[$key]) || !array_is_list($s[$key])) { throw new Problem(503, 'Ungültige Datenstruktur.'); }
        }
        self::keys($s, ['schemaVersion','settings','users','lists','wishes','reservations','attempts']);
        if ($s['settings'] !== null) {
            if (!is_array($s['settings'])) { throw new Problem(503, 'Ungültige Konfiguration.'); }
            self::keys($s['settings'], ['siteName','registration','createdAt']);
            self::string($s['settings']['siteName'], 80, true);
            if (!in_array($s['settings']['registration'], ['open','closed'], true)) { throw new Problem(503, 'Ungültiger Registrierungsmodus.'); }
        } elseif ($s['users'] || $s['lists'] || $s['wishes'] || $s['reservations']) { throw new Problem(503, 'Unvollständige Einrichtung.'); }
        $shapes = [
            'users'=>array_merge(['id','username','name','passwordHash','sessionVersion','createdAt'], $s['schemaVersion']===1 ? [] : ['googleSub','googleEmail']),
            'lists'=>['id','ownerUserId','name','description','occasion','eventDate','cover','image','createdAt','updatedAt'],
            'wishes'=>['id','listId','title','description','priceCents','currency','productUrl','quantity','image','createdAt','updatedAt'],
            'reservations'=>['id','wishId','userId','createdAt'],
        ];
        foreach ($shapes as $key=>$shape) {
            foreach ($s[$key] as $row) {
                if (!is_array($row)) { throw new Problem(503, 'Ungültiger Datensatz.'); }
                self::keys($row, $shape);
                foreach (['id','ownerUserId','listId','wishId','userId'] as $field) {
                    if (array_key_exists($field,$row) && (!is_string($row[$field]) || !preg_match('/^[a-f0-9]{32}$/D',$row[$field]))) { throw new Problem(503, 'Ungültige Objekt-ID.'); }
                }
                foreach (['createdAt','updatedAt'] as $field) { if (isset($row[$field])) { self::string($row[$field],40,true); } }
                if (array_key_exists('image',$row) && $row['image']!==null) {
                    $image=$row['image'];
                    if (!is_array($image)) { throw new Problem(503, 'Ungültige Bilddaten.'); }
                    if ($s['schemaVersion']===4) { self::validateImageReference($image); }
                    else {
                        if ($s['schemaVersion']<3 && array_key_exists('sourceUrl',$image)) { throw new Problem(503,'Ungültige Bildquelle im alten Schema.'); }
                        self::validateInlineImage($image);
                    }
                }
            }
        }
        $names=[]; $googleSubjects=[];
        foreach ($s['users'] as $u) {
            self::string($u['name'],100,true); self::string($u['username'],120,true);
            if (!preg_match('/^[a-z0-9][a-z0-9._@+\-]{2,119}$/D',$u['username']) || isset($names[$u['username']]) || !is_int($u['sessionVersion']) || $u['sessionVersion']<1) { throw new Problem(503, 'Ungültiges Benutzerkonto.'); }
            $names[$u['username']]=true;
            if ($s['schemaVersion']>=2) {
                $sub=$u['googleSub']; $email=$u['googleEmail'];
                if ($sub!==null) {
                    if (!is_string($sub) || !preg_match('/^[a-zA-Z0-9_-]{1,255}$/D',$sub) || isset($googleSubjects[$sub]) || !is_string($email) || strlen($email)>254 || !filter_var($email,FILTER_VALIDATE_EMAIL)) { throw new Problem(503,'Ungültige Google-Kontoverknüpfung.'); }
                    $googleSubjects[$sub]=true;
                } elseif ($email!==null || $u['passwordHash']===null) { throw new Problem(503,'Das Konto hat keine gültige Anmeldemethode.'); }
            }

        }
        foreach ($s['lists'] as $l) {
            self::string($l['name'],160,true); self::string($l['description'],4000);
            if (!in_array($l['occasion'],['Geburtstag','Weihnachten','Hochzeit','Anderes'],true) || !in_array($l['cover'],['lavender','peach','mint','sky'],true)) { throw new Problem(503, 'Ungültige Listendaten.'); }
            $d=$l['eventDate'];
            if (!is_string($d) || ($d!=='' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$d) || !checkdate((int)substr($d,5,2),(int)substr($d,8,2),(int)substr($d,0,4))))) { throw new Problem(503, 'Ungültiges Listendatum.'); }
        }
        foreach ($s['wishes'] as $w) {
            self::string($w['title'],200,true); self::string($w['description'],4000); self::string($w['productUrl'],2048);
            if ($w['currency']!=='EUR' || ($w['priceCents']!==null && (!is_int($w['priceCents']) || $w['priceCents']<0 || $w['priceCents']>999999999))) { throw new Problem(503, 'Ungültiger Preis.'); }
            $p=parse_url($w['productUrl']);
            if ($w['productUrl']!=='' && (!filter_var($w['productUrl'],FILTER_VALIDATE_URL) || !in_array(strtolower($p['scheme'] ?? ''),['http','https'],true) || isset($p['user']) || isset($p['pass']))) { throw new Problem(503, 'Ungültiger Produktlink.'); }
        }
        foreach ($s['attempts'] as $a) {
            if (!is_array($a) || !is_string($a['ip'] ?? null) || !is_int($a['at'] ?? null) || !in_array($a['kind'] ?? '', ['login','register'],true)) { throw new Problem(503, 'Ungültiger Anmeldezähler.'); }
        }
        $ids = [];
        foreach (['users','lists','wishes','reservations'] as $key) {
            $ids[$key] = [];
            foreach ($s[$key] as $row) {
                if (!is_array($row) || !preg_match('/^[a-f0-9]{32}$/D', $row['id'] ?? '') || isset($ids[$key][$row['id']])) { throw new Problem(503, 'Ungültige oder doppelte Objekt-ID.'); }
                $ids[$key][$row['id']] = $row;
            }
        }
        foreach ($s['users'] as $u) {
            if (!(($s['schemaVersion']>=2 && $u['passwordHash']===null && $u['googleSub']!==null) || (is_string($u['passwordHash'] ?? null) && (password_get_info($u['passwordHash'])['algo'] ?? null))) || !isset($u['username'], $u['name'], $u['sessionVersion'])) { throw new Problem(503, 'Ungültiges Benutzerkonto.'); }
        }
        foreach ($s['lists'] as $l) { if (!isset($ids['users'][$l['ownerUserId'] ?? '']) || !isset($l['name'])) { throw new Problem(503, 'Ungültige Listenreferenz.'); } }
        foreach ($s['wishes'] as $w) { if (!isset($ids['lists'][$w['listId'] ?? '']) || !is_int($w['quantity'] ?? null) || $w['quantity'] < 1 || $w['quantity'] > 99) { throw new Problem(503, 'Ungültige Wunschreferenz.'); } }
        $count = []; $pairs = [];
        foreach ($s['reservations'] as $r) {
            $w = $ids['wishes'][$r['wishId'] ?? ''] ?? null;
            if (!$w || !isset($ids['users'][$r['userId'] ?? '']) || $ids['lists'][$w['listId']]['ownerUserId'] === $r['userId']) { throw new Problem(503, 'Ungültige Reservierungsreferenz.'); }
            $pair = $r['wishId'].':'.$r['userId'];
            $count[$r['wishId']] = ($count[$r['wishId']] ?? 0) + 1;
            if (isset($pairs[$pair]) || $count[$r['wishId']] > $w['quantity']) { throw new Problem(503, 'Ungültige Reservierungsmenge.'); }
            $pairs[$pair] = true;
        }
    }
    public static function validImageFile(mixed $file): bool { return is_string($file) && (bool)preg_match('/^[a-f0-9]{64}\.(?:jpg|png|webp)$/D',$file); }
    private static function imageSource(array $image): void
    {
        if (!array_key_exists('sourceUrl',$image)) { return; }
        self::string($image['sourceUrl'],2048,true);$source=parse_url($image['sourceUrl']);
        if (!filter_var($image['sourceUrl'],FILTER_VALIDATE_URL) || !in_array(strtolower($source['scheme'] ?? ''),['http','https'],true) || isset($source['user']) || isset($source['pass'])) { throw new Problem(503,'Ungültige Bildquelle.'); }
    }
    public static function validateInlineImage(array $image): void
    {
        self::keys($image,array_merge(['mime','data'],array_key_exists('sourceUrl',$image) ? ['sourceUrl'] : []));self::imageSource($image);
        if (!is_string($image['data']) || strlen($image['data'])>2800000 || !in_array($image['mime'],['image/jpeg','image/png','image/webp'],true)) { throw new Problem(503,'Ungültige Bilddaten.'); }
        $bytes=base64_decode($image['data'],true);$info=$bytes!==false ? @getimagesizefromstring($bytes) : false;
        if (!$info || $info['mime']!==$image['mime'] || $info[0]<1 || $info[1]<1 || strlen($bytes)>2097152 || $info[0]>6000 || $info[1]>6000 || $info[0]*$info[1]>16000000) { throw new Problem(503,'Ungültige Bilddaten.'); }
    }
    public static function validateImageReference(array $image): void
    {
        self::keys($image,array_merge(['file','mime','bytes','width','height'],array_key_exists('sourceUrl',$image) ? ['sourceUrl'] : []));self::imageSource($image);
        if (!is_string($image['mime'])) { throw new Problem(503,'Ungültiger Bildtyp.'); }
        $extension=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'][$image['mime']] ?? null;
        if (!self::validImageFile($image['file']) || $extension===null || !str_ends_with($image['file'],'.'.$extension) || !is_int($image['bytes']) || $image['bytes']<1 || $image['bytes']>2097152 || !is_int($image['width']) || !is_int($image['height']) || $image['width']<1 || $image['height']<1 || $image['width']>6000 || $image['height']>6000 || $image['width']*$image['height']>16000000) { throw new Problem(503,'Ungültiger Bilddateiverweis.'); }
    }
    public static function writeFile(string $file,string $data): void
    {
        $temp=dirname($file).'/tmp-'.bin2hex(random_bytes(12)).'.php';
        try {
            $out=@fopen($temp,'xb');if (!$out) { throw new \RuntimeException('write'); }
            @chmod($temp,0600);
            try {
                $offset=0;while ($offset<strlen($data)) { $written=fwrite($out,substr($data,$offset));if (!$written) { throw new \RuntimeException('write'); }$offset+=$written; }
                if (!fflush($out) || (function_exists('fsync') && !fsync($out))) { throw new \RuntimeException('flush'); }
            } finally { fclose($out); }
            if (!@rename($temp,$file)) { throw new \RuntimeException('rename'); }
        } catch (\Throwable) { throw new Problem(503,'Speichern oder Migrationsbackup fehlgeschlagen. Die bisherigen Daten bleiben erhalten. Bitte Speicherplatz und Dateirechte prüfen.'); }
        finally { if (is_file($temp)) { @unlink($temp); } }
    }

    private static function keys(array $row,array $expected): void
    {
        $keys=array_keys($row); sort($keys); sort($expected);
        if ($keys!==$expected) { throw new Problem(503,'Die Datendatei entspricht nicht dem unterstützten Schema.'); }
    }
    private static function string(mixed $value,int $max,bool $required=false): void
    {
        if (!is_string($value) || !preg_match('//u',$value) || strlen($value)>$max || ($required && trim($value)==='')) { throw new Problem(503,'Ungültiger Text in der Datendatei.'); }
    }

}

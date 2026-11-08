<?php
declare(strict_types=1);
namespace MyWish;

final class Problem extends \RuntimeException
{
    public function __construct(public int $status, string $message) { parent::__construct($message); }
}

final class Store
{
    public const GUARD = "<?php http_response_code(404); exit; __halt_compiler();\n";
    public const VERSION = 1;
    public function __construct(private string $directory)
    {
        if (!is_dir($directory) || !is_writable($directory)) {
            throw new Problem(503, 'Der Datenordner fehlt oder ist nicht beschreibbar. Bitte Dateirechte beim Webspace-Anbieter prüfen.');
        }
    }
    public static function empty(): array
    {
        return ['schemaVersion'=>self::VERSION, 'settings'=>null, 'users'=>[], 'lists'=>[], 'wishes'=>[], 'reservations'=>[], 'attempts'=>[]];
    }
    public function transaction(callable $callback): mixed
    {
        $lock = @fopen($this->directory.'/lock.php', 'c+');
        if (!$lock || !flock($lock, LOCK_EX)) { throw new Problem(503, 'Die Datenspeicherung ist gerade nicht verfügbar. Bitte erneut versuchen.'); }
        try {
            $file = $this->directory.'/state.php';
            $state = is_file($file) ? self::decode((string)file_get_contents($file)) : self::empty();
            $before = $state;
            $result = $callback($state);
            if ($before !== $state) {
                self::validate($state);
                $data = self::GUARD.json_encode($state, JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
                $temp = $this->directory.'/tmp-'.bin2hex(random_bytes(12)).'.php';
                try {
                    $out = @fopen($temp, 'xb');
                    if (!$out) { throw new \RuntimeException('write'); }
                    @chmod($temp, 0600);
                    try {
                        $offset = 0;
                        while ($offset < strlen($data)) {
                            $written = fwrite($out, substr($data, $offset));
                            if (!$written) { throw new \RuntimeException('write'); }
                            $offset += $written;
                        }
                        if (!fflush($out) || (function_exists('fsync') && !fsync($out))) { throw new \RuntimeException('flush'); }
                    } finally { fclose($out); }
                    if (!@rename($temp, $file)) { throw new \RuntimeException('rename'); }
                } catch (\Throwable $e) { throw new Problem(503, 'Speichern fehlgeschlagen. Die bisherigen Daten bleiben erhalten. Bitte Speicherplatz und Dateirechte prüfen.'); }
                finally { if (is_file($temp)) { @unlink($temp); } }
            }
            return $result;
        } finally { flock($lock, LOCK_UN); fclose($lock); }
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
        if (($s['schemaVersion'] ?? null) !== self::VERSION) { throw new Problem(503, 'Unbekannte Datenversion. Bitte passende MyWish-Version verwenden; Daten nicht überschreiben.'); }
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
            'users'=>['id','username','name','passwordHash','sessionVersion','createdAt'],
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
                    self::keys($image,['mime','data']);
                    if (!is_string($image['data']) || strlen($image['data'])>2800000 || !in_array($image['mime'],['image/jpeg','image/png','image/webp'],true)) { throw new Problem(503, 'Ungültige Bilddaten.'); }
                    $bytes=base64_decode($image['data'],true); $info=$bytes!==false ? @getimagesizefromstring($bytes) : false;
                    if (!$info || $info['mime']!==$image['mime'] || strlen($bytes)>2097152 || $info[0]>6000 || $info[1]>6000 || $info[0]*$info[1]>16000000) { throw new Problem(503, 'Ungültige Bilddaten.'); }
                }
            }
        }
        $names=[];
        foreach ($s['users'] as $u) {
            self::string($u['name'],100,true); self::string($u['username'],120,true);
            if (!preg_match('/^[a-z0-9][a-z0-9._@+\-]{2,119}$/D',$u['username']) || isset($names[$u['username']]) || !is_int($u['sessionVersion']) || $u['sessionVersion']<1) { throw new Problem(503, 'Ungültiges Benutzerkonto.'); }
            $names[$u['username']]=true;
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
            if (!is_string($u['passwordHash'] ?? null) || !(password_get_info($u['passwordHash'])['algo'] ?? null) || !isset($u['username'], $u['name'], $u['sessionVersion'])) { throw new Problem(503, 'Ungültiges Benutzerkonto.'); }
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
    private static function keys(array $row,array $expected): void
    {
        $keys=array_keys($row); sort($keys); sort($expected);
        if ($keys!==$expected) { throw new Problem(503,'Die Datendatei entspricht nicht dem V1-Schema.'); }
    }
    private static function string(mixed $value,int $max,bool $required=false): void
    {
        if (!is_string($value) || !preg_match('//u',$value) || strlen($value)>$max || ($required && trim($value)==='')) { throw new Problem(503,'Ungültiger Text in der Datendatei.'); }
    }

}

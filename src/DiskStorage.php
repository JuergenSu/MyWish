<?php
declare(strict_types=1);
namespace MyWish;

/** Plain JSON and binary images, exclusively outside the public document root. */
final class DiskStorage
{
    public const TABLES=['settings','users','lists','wishes','reservations','attempts'];
    private string $root='';
    private ?array $manifest=null;
    private ?string $expectedId=null;

    public function __construct(private string $legacy,private ?string $requested=null,private ?string $webRoot=null) {}
    public function hasLayout(): bool
    {
        if (!is_file($this->legacy.'/layout.php')) { return false; }
        if ($this->requested!==null) {
            $raw=self::bytes($this->legacy.'/layout.php');
            if (!str_starts_with($raw,Store::GUARD)) { throw new Problem(503,'Ungültiger Datenverweis.'); }
            $locator=self::locator(self::json(substr($raw,strlen(Store::GUARD))));
            if ($this->privatePath($this->requested)!==$this->privatePath($locator['directory'])) { return false; }
        }
        return true;
    }
    public function directory(): string { $this->locate(); return $this->root; }
    private static function json(string $bytes): mixed
    {
        try { return json_decode($bytes,true,64,JSON_THROW_ON_ERROR); }
        catch (\Throwable) { throw new Problem(503,'Eine JSON-Datendatei ist beschädigt. Bitte eine geprüfte Sicherung wiederherstellen.'); }
    }
    private static function bytes(string $path): string
    {
        $bytes=@file_get_contents($path);
        if ($bytes===false) { throw new Problem(503,'Eine Datendatei fehlt oder ist nicht lesbar. Bitte Dateirechte und Sicherung prüfen.'); }
        return $bytes;
    }
    private static function encode(mixed $value): string { return json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT)."\n"; }
    private static function within(string $path,string $parent): bool { return $path===$parent || str_starts_with($path,rtrim($parent,'/').'/'); }
    private static function canonical(string $path): string
    {
        if (!str_starts_with($path,'/') || str_contains($path,"\0")) { throw new Problem(503,'Der JSON-Datenordner muss ein absoluter Pfad außerhalb des Webroots sein.'); }
        $parts=[];
        foreach (explode('/',$path) as $part) { if ($part==='' || $part==='.') { continue; } if ($part==='..') { array_pop($parts); } else { $parts[]=$part; } }
        $path='/'.implode('/',$parts); $tail=[];
        while (!file_exists($path)) { array_unshift($tail,basename($path)); $path=dirname($path); }
        $resolved=realpath($path);
        if ($resolved===false || !is_dir($path)) { throw new Problem(503,'Der JSON-Datenpfad ist kein Ordner.'); }
        return (rtrim($resolved,'/').($tail ? '/'.implode('/',$tail) : '')) ?: '/';
    }
    private function privatePath(string $path): string
    {
        $path=self::canonical($path);
        foreach (array_filter([realpath(dirname(__DIR__)),$this->webRoot!==null ? realpath($this->webRoot) : false]) as $public) {
            if (self::within($path,$public)) { throw new Problem(503,'JSON und Bilddateien müssen außerhalb des öffentlich erreichbaren Webroots liegen. Bitte data in config.php auf einen privaten Ordner setzen.'); }
        }
        return $path;
    }
    private function target(): string
    {
        if ($this->requested!==null) { return $this->privatePath($this->requested); }
        $web=$this->webRoot!==null ? realpath($this->webRoot) : false;
        if ($web && self::within($this->legacy,$web)) { return $this->privatePath(dirname($web).'/.mywish-data-'.substr(hash('sha256',$this->legacy),0,16)); }
        return $this->privatePath($this->legacy.'/data-v4');
    }
    private static function locator(mixed $value): array
    {
        if (!is_array($value)) { throw new Problem(503,'Der Datenverweis ist beschädigt.'); }
        $keys=array_keys($value);sort($keys);
        if ($keys!==['datasetId','directory','format'] || $value['format']!==4 || !is_string($value['directory']) || !is_string($value['datasetId']) || !preg_match('/^[a-f0-9]{32}$/D',$value['datasetId'])) { throw new Problem(503,'Der Verweis auf den JSON-Datenordner ist beschädigt.'); }
        return $value;
    }
    private function locate(): void
    {
        if ($this->root!=='') { return; }
        $raw=self::bytes($this->legacy.'/layout.php');
        if (!str_starts_with($raw,Store::GUARD)) { throw new Problem(503,'Ungültiger Schutz des Datenverweises.'); }
        $locator=self::locator(self::json(substr($raw,strlen(Store::GUARD))));
        $this->root=$this->privatePath($this->requested ?? $locator['directory']);$this->expectedId=$locator['datasetId'];
        $manifest=$this->readManifest();
        if ($manifest['datasetId']!==$locator['datasetId']) { throw new Problem(503,'Der konfigurierte Datenordner gehört zu einem anderen Datenbestand. Bitte den Datenpfad prüfen.'); }
    }
    private function readManifest(): array
    {
        $manifest=self::json(self::bytes($this->root.'/manifest.json'));
        if (!is_array($manifest) || ($manifest['format'] ?? null)!==1 || ($manifest['schemaVersion'] ?? null)!==4 || !is_string($manifest['datasetId'] ?? null) || !preg_match('/^[a-f0-9]{32}$/D',$manifest['datasetId']) || !is_int($manifest['revision'] ?? null) || $manifest['revision']<1 || !is_array($manifest['collections'] ?? null)) { throw new Problem(503,'Unbekanntes oder beschädigtes JSON-Speicherformat. Es wurden keine Daten überschrieben.'); }
        $tables=array_keys($manifest['collections']);sort($tables);$expected=self::TABLES;sort($expected);
        if ($tables!==$expected) { throw new Problem(503,'Der JSON-Datenbestand ist unvollständig.'); }
        foreach ($manifest['collections'] as $table=>$file) { if (!is_string($file) || !preg_match('/^'. $table.'-[a-f0-9]{64}\.json$/D',$file)) { throw new Problem(503,'Ungültiger JSON-Dateiverweis.'); } }
        if ($this->expectedId!==null && $manifest['datasetId']!==$this->expectedId) { throw new Problem(503,'Die Identität des JSON-Datenbestands wurde verändert.'); }
        $this->expectedId=$manifest['datasetId'];return $this->manifest=$manifest;
    }
    private function collection(array $manifest,string $table): mixed
    {
        $file=$manifest['collections'][$table]; $bytes=self::bytes($this->root.'/records/'.$file);
        if (!hash_equals(substr($file,strlen($table)+1,64),hash('sha256',$bytes))) { throw new Problem(503,'Eine JSON-Datendatei wurde verändert oder ist beschädigt. Bitte eine geprüfte Sicherung verwenden.'); }
        return self::json($bytes);
    }
    public function load(): array
    {
        $this->locate();$manifest=$this->readManifest();$state=['schemaVersion'=>4];
        foreach (self::TABLES as $table) { $state[$table]=$this->collection($manifest,$table); }
        Store::validate($state);return $state;
    }
    public function settings(): ?array
    {
        $this->locate();$settings=$this->collection($this->readManifest(),'settings');
        $check=Store::empty();$check['settings']=$settings;Store::validate($check);return $settings;
    }
    private function put(string $folder,string $file,string $bytes): void
    {
        $path=$this->root.'/'.$folder.'/'.$file;
        if (is_file($path)) {
            $digest=@hash_file('sha256',$path);
            if (!is_string($digest) || !hash_equals(hash('sha256',$bytes),$digest)) { throw new Problem(503,'Eine bestehende Daten- oder Bilddatei ist beschädigt.'); }
            return;
        }
        Store::writeFile($path,$bytes);
    }
    private function images(array &$state): void
    {
        foreach (['lists','wishes'] as $table) { foreach ($state[$table] as &$row) {
            if (!is_array($row['image']) || !array_key_exists('data',$row['image'])) { continue; }
            $image=$row['image'];Store::validateInlineImage($image);
            $bytes=base64_decode($image['data'],true);$info=getimagesizefromstring($bytes);
            $extension=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'][$image['mime']];
            $file=hash('sha256',$bytes).'.'.$extension;$this->put('images',$file,$bytes);
            $row['image']=['file'=>$file,'mime'=>$image['mime'],'bytes'=>strlen($bytes),'width'=>$info[0],'height'=>$info[1]];
            if (isset($image['sourceUrl'])) { $row['image']['sourceUrl']=$image['sourceUrl']; }
        } unset($row); }
    }
    public function commit(array &$state,?array $before=null,?array $origin=null): void
    {
        $this->images($state);$state['schemaVersion']=4;Store::validate($state);
        if ($before!==null && $state===$before) { return; }
        $old=$this->manifest;$collections=[];
        foreach (self::TABLES as $table) {
            if ($old!==null && $before!==null && $state[$table]===$before[$table]) { $collections[$table]=$old['collections'][$table];continue; }
            $bytes=self::encode($state[$table]);$file=$table.'-'.hash('sha256',$bytes).'.json';$this->put('records',$file,$bytes);$collections[$table]=$file;
        }
        $manifest=['format'=>1,'schemaVersion'=>4,'datasetId'=>$old['datasetId'] ?? bin2hex(random_bytes(16)),'revision'=>($old['revision'] ?? 0)+1,'collections'=>$collections,'origin'=>$old['origin'] ?? $origin];
        $older=null;
        if (is_file($this->root.'/previous.json')) { try { $older=self::json(self::bytes($this->root.'/previous.json')); } catch (Problem) {} }
        if ($old!==null) { Store::writeFile($this->root.'/previous.json',self::encode($old)); }
        Store::writeFile($this->root.'/manifest.json',self::encode($manifest));$this->manifest=$manifest;
        // Cleanup must never turn an already committed reservation into an error.
        if ($old!==null && is_array($older)) { try { $this->prune($older,$old,$manifest,$before,$state); } catch (\Throwable) {} }
    }
    private static function imageFiles(array $state): array
    {
        $files=[];foreach (['lists','wishes'] as $table) { foreach ($state[$table] as $row) { if ($row['image']) { $files[$row['image']['file']]=true; } } }return $files;
    }
    private function prune(array $older,array $old,array $current,array $before,array $state): void
    {
        $keep=array_flip(array_merge(array_values($old['collections']),array_values($current['collections'])));
        $images=self::imageFiles($before)+self::imageFiles($state);
        foreach ($older['collections'] as $table=>$file) {
            if (isset($keep[$file]) || !in_array($table,self::TABLES,true) || !preg_match('/^'.$table.'-[a-f0-9]{64}\.json$/D',$file)) { continue; }
            if (in_array($table,['lists','wishes'],true)) { foreach ($this->collection($older,$table) as $row) { $image=$row['image'];if ($image && !isset($images[$image['file']]) && Store::validImageFile($image['file'])) { @unlink($this->root.'/images/'.$image['file']); } } }
            @unlink($this->root.'/records/'.$file);
        }
    }
    public function openImage(array $image): mixed
    {
        Store::validateImageReference($image);$this->locate();$file=@fopen($this->root.'/images/'.$image['file'],'rb');
        if (!$file) { throw new Problem(503,'Die Bilddatei fehlt oder ist nicht lesbar. Bitte Sicherung und Dateirechte prüfen.'); }
        try {
            if (fstat($file)['size']!==$image['bytes']) { throw new Problem(503,'Die Bilddatei ist beschädigt.'); }
            $hash=hash_init('sha256');hash_update_stream($hash,$file);
            if (!hash_equals(substr($image['file'],0,64),hash_final($hash)) || !rewind($file)) { throw new Problem(503,'Die Bilddatei ist beschädigt.'); }
            return $file;
        } catch (\Throwable $e) { fclose($file);throw $e; }
    }
    public function verify(): array
    {
        $state=$this->load();$files=self::imageFiles($state);
        foreach (['lists','wishes'] as $table) { foreach ($state[$table] as $row) { if (!$row['image'] || !isset($files[$row['image']['file']])) { continue; }$file=$this->openImage($row['image']);$info=getimagesizefromstring(stream_get_contents($file));fclose($file);
            if (!$info || $info['mime']!==$row['image']['mime'] || $info[0]!==$row['image']['width'] || $info[1]!==$row['image']['height']) { throw new Problem(503,'Bildinhalt und JSON-Bildverweis stimmen nicht überein.'); }unset($files[$row['image']['file']]);
        } }
        return $state;
    }
    private function publishLocator(): void
    {
        $locator=['format'=>4,'directory'=>$this->root,'datasetId'=>$this->manifest['datasetId']];
        // Old app versions must refuse the retired monolith instead of diverging.
        Store::writeFile($this->legacy.'/state.php',Store::GUARD.self::encode(['schemaVersion'=>4,'migration'=>$locator]));
        Store::writeFile($this->legacy.'/layout.php',Store::GUARD.self::encode($locator));
    }
    public function initialize(): void
    {
        if ($this->hasLayout()) { $this->locate();return; }
        $file=$this->legacy.'/state.php';
        if (is_file($file)) { Store::checkMigrationMemory(filesize($file)); }
        $source=is_file($file) ? self::bytes($file) : null;
        if ($source!==null && !str_starts_with($source,Store::GUARD)) { throw new Problem(503,'Die bisherige Datendatei ist beschädigt. Bitte eine geprüfte Sicherung verwenden.'); }
        $header=$source!==null && str_starts_with($source,Store::GUARD) ? self::json(substr($source,strlen(Store::GUARD))) : null;
        if (is_array($header) && ($header['schemaVersion'] ?? null)===4 && isset($header['migration'])) {
            $locator=self::locator($header['migration']);$this->root=$this->privatePath($this->requested ?? $locator['directory']);$this->verify();
            if ($this->manifest['datasetId']!==$locator['datasetId']) { throw new Problem(503,'Der Migrationsverweis passt nicht zum Datenbestand.'); }
            $this->publishLocator();return;
        }
        $state=$source!==null ? $header : Store::empty();unset($header);Store::validate($state);
        if ($state['schemaVersion']===1) { foreach ($state['users'] as &$user) { $user['googleSub']=null;$user['googleEmail']=null; }unset($user); }
        $this->root=$this->target();
        if (is_file($this->root.'/manifest.json')) {
            $this->verify();$origin=$this->manifest['origin'] ?? null;
            if ($source!==null && (!is_array($origin) || ($origin['sourceHash'] ?? null)!==hash('sha256',$source) || ($origin['storageHash'] ?? null)!==hash('sha256',$this->legacy))) { throw new Problem(503,'Der Zielordner enthält einen anderen Datenbestand. Bitte einen leeren privaten Datenordner verwenden.'); }
            if ($source!==null) {
                $backup=$origin['backup'] ?? '';
                if (!is_string($backup) || !preg_match('/^backup-schema-[123]-[a-f0-9]{64}\\.php$/D',$backup)) { throw new Problem(503,'Der Migrationsbackup-Verweis ist beschädigt.'); }
                if (!is_file($this->legacy.'/'.$backup)) { Store::writeFile($this->legacy.'/'.$backup,$source); }
                if (!is_readable($this->legacy.'/'.$backup) || !hash_equals(hash('sha256',$source),hash_file('sha256',$this->legacy.'/'.$backup))) { throw new Problem(503,'Das Migrationsbackup ist beschädigt.'); }
            }
            $this->publishLocator();return;
        }
        $target=$this->root;$staging=$target.'.migrate-'.bin2hex(random_bytes(8));
        if ((is_dir($target) && count(scandir($target))>2) || !is_dir(dirname($target)) || !is_writable(dirname($target))) { throw new Problem(503,'Der private JSON-Datenordner kann nicht angelegt werden oder ist nicht leer. Bitte data in config.php auf einen leeren beschreibbaren Ordner außerhalb des Webroots setzen.'); }
        $backup=null;
        if ($source!==null) {
            $backup='backup-schema-'.$state['schemaVersion'].'-'.hash('sha256',$source).'.php';
            if (!is_file($this->legacy.'/'.$backup)) { Store::writeFile($this->legacy.'/'.$backup,$source); }
            if (!is_readable($this->legacy.'/'.$backup) || !hash_equals(hash('sha256',$source),hash_file('sha256',$this->legacy.'/'.$backup))) { throw new Problem(503,'Das Migrationsbackup konnte nicht geprüft werden.'); }
        }
        if (!@mkdir($staging,0700)) { throw new Problem(503,'Der private Migrationsordner kann nicht angelegt werden. Bitte Speicherplatz und Dateirechte prüfen.'); }
        $this->root=$staging;$this->manifest=null;$this->expectedId=null;
        try {
            foreach (['records','images'] as $folder) { if (!@mkdir($staging.'/'.$folder,0700)) { throw new Problem(503,'Der private Datenordner ist nicht beschreibbar.'); } }
            $origin=['sourceHash'=>$source!==null ? hash('sha256',$source) : null,'storageHash'=>hash('sha256',$this->legacy),'backup'=>$backup];
            $this->commit($state,null,$origin);$this->verify();
            if (!@rename($staging,$target)) { throw new Problem(503,'Der geprüfte neue Datenbestand kann nicht aktiviert werden. Die bisherigen Daten bleiben erhalten.'); }
            $this->root=$target;$this->publishLocator();
        } finally {
            // Staging is never a live dataset. A failed migration is retryable.
            if (is_dir($staging)) { foreach (['records','images'] as $folder) { foreach (glob($staging.'/'.$folder.'/*') ?: [] as $file) { @unlink($file); }@rmdir($staging.'/'.$folder); }foreach (glob($staging.'/*') ?: [] as $file) { @unlink($file); }@rmdir($staging); }
            $this->root=$target;
        }
    }
    public function backup(string $target): void
    {
        $target=$this->privatePath($target);$this->verify();
        if (file_exists($target)) { throw new Problem(503,'Der Sicherungsordner darf noch nicht existieren.'); }
        if (!@mkdir($target,0700) || !@mkdir($target.'/records',0700) || !@mkdir($target.'/images',0700)) { throw new Problem(503,'Der Sicherungsordner kann nicht angelegt werden.'); }
        $state=$this->load();
        foreach ($this->manifest['collections'] as $file) { Store::writeFile($target.'/records/'.$file,self::bytes($this->root.'/records/'.$file)); }
        foreach (self::imageFiles($state) as $file=>$_) { Store::writeFile($target.'/images/'.$file,self::bytes($this->root.'/images/'.$file)); }
        Store::writeFile($target.'/manifest.json',self::encode($this->manifest));
    }
    public function cleanup(): array
    {
        $current=$this->verify();$manifests=[$this->manifest];$images=self::imageFiles($current);
        if (is_file($this->root.'/previous.json')) {
            $previous=self::json(self::bytes($this->root.'/previous.json'));$state=['schemaVersion'=>4];
            if (!is_array($previous) || ($previous['datasetId'] ?? null)!==$this->manifest['datasetId']) { throw new Problem(503,'Die vorherige Generation ist beschädigt. Keine Dateien bereinigt.'); }
            foreach (self::TABLES as $table) {
                if (!is_string($previous['collections'][$table] ?? null) || !preg_match('/^'.$table.'-[a-f0-9]{64}\\.json$/D',$previous['collections'][$table])) { throw new Problem(503,'Ungültige vorherige Generation.'); }
                $state[$table]=$this->collection($previous,$table);
            }
            Store::validate($state);$images+=self::imageFiles($state);$manifests[]=$previous;
        }
        $records=[];foreach ($manifests as $manifest) { $records+=array_fill_keys(array_values($manifest['collections']),true); }
        $removed=['records'=>0,'images'=>0];
        foreach (glob($this->root.'/records/*.json') ?: [] as $path) { $file=basename($path);if (preg_match('/^(?:settings|users|lists|wishes|reservations|attempts)-[a-f0-9]{64}\\.json$/D',$file) && !isset($records[$file]) && @unlink($path)) { $removed['records']++; } }
        foreach (glob($this->root.'/images/*') ?: [] as $path) { $file=basename($path);if (Store::validImageFile($file) && !isset($images[$file]) && @unlink($path)) { $removed['images']++; } }
        return $removed;
    }
    public static function plannedDirectory(string $legacy,?string $requested,string $webRoot): string
    {
        return (new self($legacy,$requested,$webRoot))->target();
    }
    public static function checkDirectory(string $directory): array
    {
        $disk=new self(dirname($directory));$disk->root=$disk->privatePath($directory);return $disk->verify();
    }
}

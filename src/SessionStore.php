<?php
declare(strict_types=1);
namespace MyWish;

// Eigene geschützte Dateien verhindern, dass die PHP-Standardbereinigung des
// Hosters langlebige Sitzungen bereits nach wenigen Minuten entfernt.
final class SessionStore implements \SessionHandlerInterface, \SessionIdInterface, \SessionUpdateTimestampHandlerInterface
{
    public const LIFETIME = 14 * 24 * 60 * 60;
    private mixed $file = null;
    private string $lockedId = '';

    public function __construct(private string $directory)
    {
        if ((!is_dir($directory) && !@mkdir($directory,0700) && !is_dir($directory)) || !is_writable($directory)) {
            throw new Problem(503,'Der Sitzungsordner kann nicht angelegt oder beschrieben werden. Bitte Dateirechte im Datenordner prüfen.');
        }
    }
    private function path(string $id): ?string
    {
        return preg_match('/^[a-f0-9]{64}$/D',$id) ? $this->directory.'/session-'.$id.'.php' : null;
    }
    public function open(string $path,string $name): bool { return true; }
    public function close(): bool
    {
        if (is_resource($this->file)) { flock($this->file,LOCK_UN); fclose($this->file); }
        $this->file=null; $this->lockedId=''; return true;
    }
    private function lock(string $id): bool
    {
        if ($id===$this->lockedId && is_resource($this->file)) { return true; }
        $this->close(); $path=$this->path($id);
        if ($path===null) { return false; }
        $file=@fopen($path,'c+b');
        if (!$file) { return false; }
        if (!@chmod($path,0600) || !flock($file,LOCK_EX)) { fclose($file); return false; }
        $this->file=$file; $this->lockedId=$id;
        if (fstat($file)['size']===0 && (fwrite($file,Store::GUARD)!==strlen(Store::GUARD) || !fflush($file))) { $this->close(); return false; }
        return true;
    }
    public function read(string $id): string|false
    {
        if (!$this->lock($id)) { return false; }
        rewind($this->file);
        if (fread($this->file,strlen(Store::GUARD))!==Store::GUARD) { return false; }
        if (fstat($this->file)['mtime']<=time()-self::LIFETIME) { return ''; }
        return stream_get_contents($this->file);
    }
    public function write(string $id,string $data): bool
    {
        if (!$this->lock($id) || fseek($this->file,strlen(Store::GUARD))!==0) { return false; }
        for ($offset=0;$offset<strlen($data);$offset+=$written) {
            $written=fwrite($this->file,substr($data,$offset));
            if ($written===false || $written===0) { return false; }
        }
        // Der PHP-Abbruchschutz bleibt auch während des Schreibens bestehen.
        return ftruncate($this->file,strlen(Store::GUARD)+strlen($data)) && fflush($this->file);
    }
    public function destroy(string $id): bool
    {
        // Unter demselben Lock leeren: wartende Requests dürfen alte
        // Anmeldedaten nach Logout oder ID-Wechsel nicht wiederherstellen.
        return $this->write($id,'');
    }
    public function create_sid(): string { return bin2hex(random_bytes(32)); }
    public function validateId(string $id): bool
    {
        $path=$this->path($id); $file=$path!==null ? @fopen($path,'rb') : false;
        if (!$file) { return false; }
        try {
            if (!flock($file,LOCK_SH)) { return false; }
            $stat=fstat($file);
            return $stat['mtime']>time()-self::LIFETIME && $stat['size']>strlen(Store::GUARD) && fread($file,strlen(Store::GUARD))===Store::GUARD;
        } finally { flock($file,LOCK_UN); fclose($file); }
    }
    public function updateTimestamp(string $id,string $data): bool { return $this->write($id,$data); }
    public function gc(int $max_lifetime): int|false
    {
        $removed=0;
        foreach (glob($this->directory.'/session-*.php') ?: [] as $path) {
            if (!preg_match('/^session-[a-f0-9]{64}\.php$/D',basename($path))) { continue; }
            $file=@fopen($path,'r+b');
            if (!$file) { continue; }
            try {
                if (flock($file,LOCK_EX|LOCK_NB) && fstat($file)['mtime']<=time()-self::LIFETIME && @unlink($path)) { $removed++; }
            } finally { flock($file,LOCK_UN); fclose($file); }
        }
        return $removed;
    }
}

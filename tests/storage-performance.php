<?php
declare(strict_types=1);
// Reproducible synthetic measurement, never touches an installation's data.
require dirname(__DIR__).'/src/Store.php';
require __DIR__.'/fixtures/storage-helpers.php';
use MyWish\Store;
if (isset($argv[1]) && $argv[1]==='worker') {
    $mode=$argv[2];$directory=$argv[3];$times=[];
    for ($i=0;$i<7;$i++) {
        $start=hrtime(true);
        if ($mode==='legacy') { $state=Store::decode(file_get_contents($directory.'/legacy.php')); }
        else { $store=new Store($directory.'/storage');$state=$store->read(fn(&$s)=>$s); }
        $times[]=(hrtime(true)-$start)/1e6;unset($state);
    }
    sort($times);echo json_encode(['medianMs'=>$times[3],'peakBytes'=>memory_get_peak_usage(true)]);exit;
}
$root=sys_get_temp_dir().'/mywish-storage-benchmark-'.bin2hex(random_bytes(6));mkdir($root,0700);mkdir($root.'/storage',0700);
try {
    $state=Store::empty();$state['schemaVersion']=3;$now=gmdate('c');
    $state['settings']=['siteName'=>'Benchmark','registration'=>'open','createdAt'=>$now];
    $state['users'][]=['id'=>str_repeat('a',32),'name'=>'Test','username'=>'test','passwordHash'=>password_hash('abcdefgh',PASSWORD_DEFAULT),'sessionVersion'=>1,'googleSub'=>null,'googleEmail'=>null,'createdAt'=>$now];
    $state['lists'][]=['id'=>str_repeat('b',32),'ownerUserId'=>str_repeat('a',32),'name'=>'Synthetic list','description'=>'','occasion'=>'Anderes','eventDate'=>'','cover'=>'lavender','image'=>null,'createdAt'=>$now,'updatedAt'=>$now];
    // Real 1000x600 PNGs with noise: approximately 1.8 MB each, within legacy limits.
    $chunk=static fn(string $type,string $data)=>pack('N',strlen($data)).$type.$data.pack('N',crc32($type.$data));
    for ($i=0;$i<12;$i++) {
        $pixels='';for ($y=0;$y<600;$y++) { $pixels.="\0".random_bytes(3000); }
        $png="\x89PNG\r\n\x1a\n".$chunk('IHDR',pack('NNCCCCC',1000,600,8,2,0,0,0)).$chunk('IDAT',gzcompress($pixels)).$chunk('IEND','');unset($pixels);
        $state['wishes'][]=['id'=>sprintf('%032x',$i+1),'listId'=>str_repeat('b',32),'title'=>'Synthetic wish '.$i,'description'=>'','priceCents'=>1000,'currency'=>'EUR','productUrl'=>'','quantity'=>1,'image'=>['mime'=>'image/png','data'=>base64_encode($png)],'createdAt'=>$now,'updatedAt'=>$now];
    }
    $raw=Store::GUARD.json_encode($state);unset($state,$png);file_put_contents($root.'/legacy.php',$raw);file_put_contents($root.'/storage/state.php',$raw);unset($raw);
    $store=new Store($root.'/storage');$store->verify();$data=$store->dataDirectory();
    $run=static function(string $mode) use ($root): array {
        $process=proc_open([PHP_BINARY,'-n','-d','memory_limit=256M',__FILE__,'worker',$mode,$root],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        fclose($pipes[0]);$output=stream_get_contents($pipes[1]);$errors=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
        if (proc_close($process)!==0) { throw new RuntimeException($errors); }return json_decode($output,true,64,JSON_THROW_ON_ERROR);
    };
    $manifest=json_decode(file_get_contents($data.'/manifest.json'),true);$metadata=filesize($data.'/manifest.json');
    foreach ($manifest['collections'] as $file) { $metadata+=filesize($data.'/records/'.$file); }
    $result=['images'=>12,'legacyBytes'=>filesize($root.'/legacy.php'),'metadataBytes'=>$metadata,'imageBytes'=>array_sum(array_map('filesize',glob($data.'/images/*'))),'legacy'=>$run('legacy'),'split'=>$run('split')];
    if ($result['split']['peakBytes']>16*1024*1024 || $metadata>100000) { throw new RuntimeException('Metadata request unexpectedly loaded image payloads.'); }
    echo json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
} finally { removeTestTree($root); }

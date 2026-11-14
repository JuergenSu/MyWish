<?php
declare(strict_types=1);
require __DIR__.'/fixtures/openai-transport.php';
require __DIR__.'/fixtures/storage-helpers.php';
foreach (['Store','App','Metadata','OpenAiImages'] as $class) { require dirname(__DIR__).'/src/'.$class.'.php'; }
use MyWish\{App,Store,Problem,OpenAiImages};
$checks=0;
function check(bool $ok,string $label): void { global $checks; if (!$ok) { throw new RuntimeException($label); } $checks++; }
function rejected(callable $fn,int $status): string { try { $fn(); } catch (Problem $e) { check($e->status===$status,'Expected status '.$status); return $e->getMessage(); } throw new RuntimeException('Expected rejection'); }
function apiCalls(): array { return array_values(array_filter($GLOBALS['openaiCalls'] ?? [],fn($c)=>$c->url==='https://api.openai.com/v1/responses')); }
function clearCache(string $dir): void { foreach (glob($dir.'/openai-search*.php') as $file) { unlink($file); } }
$dir=sys_get_temp_dir().'/mywish-openai-'.bin2hex(random_bytes(6)); mkdir($dir,0700); session_start(); $_SESSION=[];
try {
    $config=['api_key'=>'sk-test-server-key','model'=>'gpt-6-astra','daily_limit'=>100];
    $search=new OpenAiImages($config,$dir);
    check(!(new OpenAiImages([],$dir))->available(),'Empty key disables optional search');
    rejected(fn()=>(new OpenAiImages([],$dir))->preview('Product','',str_repeat('a',32)),503);
    rejected(fn()=>new OpenAiImages(['api_key'=>"bad\r\nheader"],$dir),503);
    rejected(fn()=>new OpenAiImages(['model'=>'bad model'],$dir),503);
    rejected(fn()=>$search->preview('','',str_repeat('a',32)),422);
    $url='https://www.amazon.de/Cuisinart-Toaster/dp/B0G1586BZG/?tracking=secret#fragment';
    $result=$search->preview('Cuisinart RBT2200E',$url,str_repeat('a',32));
    check($result['hasImage'] && $result['imageSourceUrl']==='https://shop.example/product','Real image_result yields checked preview and source page');
    check($_SESSION['importImage']['image']['sourceUrl']===$result['imageSourceUrl'],'Attribution belongs to checked image, not editable hidden input');
    $call=apiCalls()[0]; $payload=json_decode($call->options[CURLOPT_POSTFIELDS],true); $input=json_decode($payload['input'],true);
    check($input['product_url']==='https://www.amazon.de/dp/B0G1586BZG' && !str_contains($payload['input'],'secret'),'Product tracking and fragment stripped from OpenAI input');
    check($payload['model']===$config['model'] && $payload['store']===false && $payload['max_tool_calls']===2,'Configured model, no response storage and bounded specific/broad search');
    check($payload['tools'][0]['search_content_types']===['image','text'] && $payload['include']===['web_search_call.results','web_search_call.action.sources'],'Documented Responses image search with raw results and source pages');
    check(str_contains($payload['instructions'],'similar product is acceptable') && str_contains($payload['instructions'],'generic product category'),'Prompt allows approximate product photos and a category-only search');
    check(str_contains($result['warning'],'abweichen'),'Every AI image proposal explicitly warns about possible product differences');
    check($call->options[CURLOPT_FOLLOWLOCATION]===false && $call->options[CURLOPT_PROTOCOLS]===CURLPROTO_HTTPS && $call->options[CURLOPT_SSL_VERIFYPEER] && $call->options[CURLOPT_TIMEOUT_MS]<=20000,'Bounded fixed HTTPS API endpoint without redirects');
    check(in_array('Authorization: Bearer sk-test-server-key',$call->options[CURLOPT_HTTPHEADER],true),'Server configuration supplies API key');
    foreach ($GLOBALS['openaiCalls'] as $c) {
        if ($c->url===$call->url) { continue; }
        check(!str_contains(implode('\n',$c->options[CURLOPT_HTTPHEADER]),'sk-test-server-key'),'API key never forwarded to images');
    }
    $before=count(apiCalls()); $search->preview('Cuisinart RBT2200E',$url,str_repeat('b',32));
    check(count(apiCalls())===$before,'Shared product cache prevents repeated paid requests');
    $raw=file_get_contents($dir.'/openai-search.php');
    check(str_starts_with($raw,Store::GUARD) && !str_contains($raw,'sk-test-server-key') && !str_contains($raw,'tracking'),'Protected cache contains no API key or tracking parameters');
    clearCache($dir);
    $oldKey=hash('sha256',$config['model']."\0Product\0");
    file_put_contents($dir.'/openai-search.php',Store::GUARD.json_encode(['day'=>gmdate('Y-m-d'),'used'=>1,'users'=>[],'cache'=>[$oldKey=>['until'=>time()+600,'results'=>[]]]]));
    $before=count(apiCalls());check($search->preview('Product','',str_repeat('a',32))['hasImage'] && count(apiCalls())===$before+1,'Previous empty search cache cannot hide new broader-search results');
    $newState=json_decode(substr(file_get_contents($dir.'/openai-search.php'),strlen(Store::GUARD)),true);
    check($newState['used']===2,'Strategy change preserves existing daily usage');
    clearCache($dir);
    check(!$search->preview('text-only','',str_repeat('a',32))['hasImage'],'Free model text is never treated as an image URL');
    check(!isset($_SESSION['importImage']),'No matches clear previous preview');
    clearCache($dir);
    check($search->preview('unsafe-first','',str_repeat('a',32))['hasImage'],'Private first image is blocked before safe candidate succeeds');
    clearCache($dir);
    check(!$search->preview('fail-download','',str_repeat('a',32))['hasImage'],'Unsupported image content rejected');
    foreach (['source-only','citation-only','source-private-first'] as $title) {
        clearCache($dir);$result=$search->preview($title,'',str_repeat('a',32));
        check($result['hasImage'] && $result['imageSourceUrl']==='https://shop.example/with-image','Find product image through structured source page: '.$title);
    }
    clearCache($dir);$result=$search->preview('source-after-broken','',str_repeat('a',32));
    check($result['hasImage'] && $result['imageSourceUrl']==='https://shop.example/with-image','Source-page fallback retains download budget after broken direct images');
    foreach ($GLOBALS['openaiCalls'] as $c) {
        if ($c->url==='https://api.openai.com/v1/responses') { continue; }
        check(!str_contains(implode('\n',$c->options[CURLOPT_HTTPHEADER]),'sk-test-server-key'),'No key on source-page or image downloads');
    }
    clearCache($dir);$missing=$search->preview('source-no-images','',str_repeat('a',32));
    check(!$missing['hasImage'] && str_contains($missing['warning'],'Quellen gefunden'),'Distinguish discovered pages without downloadable images from empty search');
    clearCache($dir);$message=rejected(fn()=>$search->preview('no-tool','',str_repeat('a',32)),503);
    check(str_contains($message,'keine Websuche ausgeführt'),'Missing tool execution is an explicit provider error');
    clearCache($dir);
    $message=rejected(fn()=>$search->preview('provider-error','',str_repeat('a',32)),503);
    check(str_contains($message,'HTTP 401') && !str_contains($message,'sk-test'),'Provider error bodies and secrets never reflected');
    $data=json_decode(substr(file_get_contents($dir.'/openai-search.php'),strlen(Store::GUARD)),true);
    check($data['used']===1 && $data['cache']===[],'Failed paid attempt counted and in-flight cache entry removed');
    foreach ([['status'=>302,'headers'=>['location'=>'https://attacker.example'],'body'=>''],['status'=>429,'body'=>''],['status'=>200,'body'=>'not JSON'],['status'=>200,'body'=>str_repeat('x',524289)],['status'=>200,'body'=>'{"status":"incomplete","output":[]}'],['status'=>200,'failure'=>true]] as $reply) {
        clearCache($dir); $GLOBALS['openaiReplies']=[$reply];
        rejected(fn()=>$search->preview('Bad response','',str_repeat('a',32)),503);
        check(!isset($_SESSION['importImage']),'Invalid provider response never selects stale image');
    }
    clearCache($dir);
    $GLOBALS['openaiReplies']=[['status'=>200,'body'=>json_encode(['status'=>'incomplete','output'=>[['type'=>'web_search_call','status'=>'completed','results'=>[['type'=>'image_result','image_url'=>'https://images.example/product.png','source_website_url'=>'https://shop.example/product']]]]])]];
    check($search->preview('Completed image search','',str_repeat('a',32))['hasImage'],'Completed raw image result remains usable when discarded assistant text reaches token limit');
    clearCache($dir);
    for ($i=0;$i<5;$i++) { $search->preview('Product '.$i,'',str_repeat('a',32)); }
    rejected(fn()=>$search->preview('Sixth product','',str_repeat('a',32)),429);
    clearCache($dir); $limited=new OpenAiImages(array_replace($config,['daily_limit'=>1]),$dir);
    $limited->preview('First','',str_repeat('a',32));
    rejected(fn()=>$limited->preview('Second','',str_repeat('b',32)),429);
    check($limited->preview('First','',str_repeat('b',32))['hasImage'],'Cache hits work after daily limit without another charge');
    clearCache($dir);file_put_contents($dir.'/openai-search.php','corrupt');
    $before=count(apiCalls()); rejected(fn()=>$search->preview('Product','',str_repeat('a',32)),503);
    check(count(apiCalls())===$before,'Corrupt quota cache fails closed before paid API call');
    clearCache($dir);
    $search->preview('Product','',str_repeat('a',32));
    $cacheFile=$dir.'/openai-search.php';$state=json_decode(substr(file_get_contents($cacheFile),strlen(Store::GUARD)),true);$key=array_key_first($state['cache']);
    $state['cache'][$key]=['until'=>time()+30,'results'=>null];file_put_contents($cacheFile,Store::GUARD.json_encode($state));
    $before=count(apiCalls());rejected(fn()=>$search->preview('Product','',str_repeat('b',32)),429);
    check(count(apiCalls())===$before,'In-flight product query is not charged twice');
    $state['cache'][$key]['until']=time()-1;file_put_contents($cacheFile,Store::GUARD.json_encode($state));
    $search->preview('Product','',str_repeat('b',32));
    check(count(apiCalls())===$before+1,'Expired in-flight entry permits recovery');
    clearCache($dir);
    if (function_exists('pcntl_fork')) {
        $workers=[];
        for ($i=0;$i<4;$i++) {
            $pid=pcntl_fork();
            if ($pid===0) { try { $search->preview('Concurrent product','',str_repeat('a',32)); } catch (Problem $e) { if ($e->status!==429) { exit(1); } } exit(0); }
            $workers[]=$pid;
        }
        foreach ($workers as $pid) { pcntl_waitpid($pid,$status);check(pcntl_wexitstatus($status)===0,'Concurrent worker completed or found in-flight search'); }
        $state=json_decode(substr(file_get_contents($cacheFile),strlen(Store::GUARD)),true);
        check($state['used']===1,'Concurrent identical searches reserve only one paid request');
        clearCache($dir);
    }
    // Version 2 migration, including a Google-only account, then attribution roundtrip.
    $store=new Store($dir); $app=new App($store); $app->act('setup',['name'=>'Owner','username'=>'owner','password'=>'abcdefgh','siteName'=>'Test']);
    $list=$app->act('saveList',['name'=>'Wishes','occasion'=>'Anderes'])['id'];
    $legacy=$store->transaction(fn(&$s)=>$s); $legacy['schemaVersion']=2;
    $google=$legacy['users'][0];$google['id']=str_repeat('b',32);$google['username']='google';$google['passwordHash']=null;$google['googleSub']='test-sub';$google['googleEmail']='test@example.com';$legacy['users'][]=$google;
    $migrationDir=$dir.'/legacy';mkdir($migrationDir,0700);$store=new Store($migrationDir);$app=new App($store);
    $bytes=Store::GUARD.json_encode($legacy);file_put_contents($migrationDir.'/state.php',$bytes);
    $migrated=$store->transaction(fn(&$s)=>$s);$backups=glob($migrationDir.'/backup-schema-2-*.php');
    check($migrated['schemaVersion']===Store::VERSION && $migrated['users']===$legacy['users'] && $migrated['lists']===$legacy['lists'],'Migration preserves local and Google-only accounts and lists');
    check(count($backups)===1 && file_get_contents($backups[0])===$bytes,'Schema 2 backed up byte-for-byte before migration');
    $search->preview('Product','',$_SESSION['userId']);
    $wish=$app->act('saveWish',['listId'=>$list,'title'=>'Product','useImportedImage'=>'yes'])['id'];
    $snapshot=$app->snapshot();check($snapshot['wishes'][0]['imageSourceUrl']==='https://shop.example/product' && $snapshot['wishes'][0]['image']===true,'Saved image source survives API snapshot');
    $app->act('saveWish',['id'=>$wish,'listId'=>$list,'title'=>'Renamed']);
    check($app->snapshot()['wishes'][0]['imageSourceUrl']==='https://shop.example/product','Editing text retains source attribution');
    $state=$store->transaction(fn(&$s)=>$s);$state['wishes'][0]['image']['sourceUrl']='javascript:alert(1)';
    rejected(fn()=>Store::validate($state),503);
    $app->act('saveWish',['id'=>$wish,'listId'=>$list,'title'=>'Removed','removeImage'=>'yes']);
    check(!isset($app->snapshot()['wishes'][0]['imageSourceUrl']),'Removing image removes attribution');
    echo "OK: $checks OpenAI image search/cache/migration checks\n";
} finally { removeTestTree($dir); $_SESSION=[]; session_destroy(); }

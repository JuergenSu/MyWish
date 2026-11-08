<?php
// Nur isolierte Tests laden diesen Transport. Keine echten API-Aufrufe oder Schlüssel.
namespace MyWish;
function gethostbynamel(string $host): array|false { return in_array($host,['www.amazon.de','m.media-amazon.com','images.example','shop.example'],true) ? ['93.184.216.34'] : false; }
function curl_init(string $url): object { return (object)['url'=>$url,'options'=>[],'status'=>0]; }
function curl_setopt_array(object $c,array $options): bool { $c->options=$options; return true; }
function curl_exec(object $c): bool
{
    $GLOBALS['openaiCalls'][]=$c;
    if (!empty($GLOBALS['openaiReplies'])) { $reply=array_shift($GLOBALS['openaiReplies']); }
    elseif ($c->url==='https://api.openai.com/v1/responses') {
        $payload=json_decode($c->options[CURLOPT_POSTFIELDS],true);
        $input=json_decode($payload['input'],true); $title=$input['title'];
        $results=in_array($title,['no-match','text-only'],true) ? [] : [['type'=>'image_result','image_url'=>'https://images.example/'.($title==='fail-download' ? 'invalid.svg' : 'openai-good.png'),'source_website_url'=>'https://shop.example/product','thumbnail_url'=>'','caption'=>'Testprodukt']];
        if ($title==='unsafe-first') { array_unshift($results,['type'=>'image_result','image_url'=>'http://127.0.0.1/private.png','source_website_url'=>'https://shop.example/product']); }
        $reply=['status'=>$title==='provider-error' ? 401 : 200,'body'=>$title==='provider-error' ? '{"error":{"message":"Do not expose sk-test-server-key"}}' : json_encode(['status'=>'completed','output'=>[['type'=>'web_search_call','status'=>'completed','results'=>$results],['type'=>'message','content'=>[['type'=>'output_text','text'=>'https://images.example/invented.jpg']]]]])];
    } elseif (str_starts_with($c->url,'https://images.example/')) {
        $reply=['status'=>200,'body'=>str_contains($c->url,'invalid.svg') ? '<svg></svg>' : require __DIR__.'/product-image.php'];
    } elseif (str_starts_with($c->url,'https://shop.example/')) { $reply=['status'=>200,'body'=>'<title>Shopprodukt</title>']; }
    else { $reply=['status'=>503,'body'=>'']; }
    $c->status=$reply['status'];
    if ($reply['failure'] ?? false) { return false; }
    if (isset($c->options[CURLOPT_HEADERFUNCTION])) {
        foreach ($reply['headers'] ?? [] as $key=>$value) { ($c->options[CURLOPT_HEADERFUNCTION])($c,$key.': '.$value."\r\n"); }
    }
    $body=$reply['body'] ?? '';
    return ($c->options[CURLOPT_WRITEFUNCTION])($c,$body)===strlen($body);
}
function curl_getinfo(object $c,int $option): int { return $c->status; }
function curl_close(object $c): void {}

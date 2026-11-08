<?php
// Ausschließlich vom isolierten HTTP-Test-Router geladen, nie von Anwendungscode.
namespace MyWish;
function gethostbynamel(string $host): array|false { return in_array($host,['www.amazon.de','images.example'],true) ? ['93.184.216.34'] : false; }
function curl_init(string $url): object { return (object)['url'=>$url,'options'=>[],'status'=>0]; }
function curl_setopt_array(object $c,array $options): bool { $c->options=$options; return true; }
function curl_exec(object $c): bool
{
    if (str_contains($c->url,'B000000503')) { $c->status=503; $body=''; }
    elseif (str_starts_with($c->url,'https://images.example/')) {
        $c->status=200; $body=str_contains($c->url,'invalid.svg') ? '<svg></svg>' : base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl6C6sAAAAASUVORK5CYII=');
    } else { $c->status=200; $body=file_get_contents(__DIR__.'/amazon-product.html'); }
    return ($c->options[CURLOPT_WRITEFUNCTION])($c,$body)===strlen($body);
}
function curl_getinfo(object $c,int $option): int { return $c->status; }
function curl_close(object $c): void {}

<?php
// Ausschließlich vom isolierten HTTP-Test-Router geladen, nie von Anwendungscode.
namespace MyWish;
function gethostbynamel(string $host): array|false { return in_array($host,['www.amazon.de','m.media-amazon.com','images.example','shop.example'],true) ? ['93.184.216.34'] : false; }
function curl_init(string $url): object { return (object)['url'=>$url,'options'=>[],'status'=>0]; }
function curl_setopt_array(object $c,array $options): bool { $c->options=$options; return true; }
function curl_exec(object $c): bool
{
    if (str_starts_with($c->url,'https://m.media-amazon.com/')) {
        $placeholder=$c->url==='https://m.media-amazon.com/images/P/B0G1586BZG.01.L.jpg';
        $c->status=$placeholder || $c->url==='https://m.media-amazon.com/images/P/B0FCG8N5NC.01.L.jpg' ? 200 : 404;
        $body=$placeholder ? base64_decode('R0lGODlhAQABAIABAAAAAP///yH5BAEAAAEALAAAAAABAAEAAAICTAEAOw==') : ($c->status===200 ? require __DIR__.'/product-image.php' : '');
    }
    elseif (str_contains($c->url,'B000000503') || str_contains($c->url,'B0FCG8N5NC') || str_contains($c->url,'B0G1586BZG')) { $c->status=503; $body=''; }
    elseif ($c->url==='https://shop.example/product-large') {
        $c->status=200; $body=str_repeat(' ',5*1024*1024).'<title>Große Shopseite</title><meta property="og:image" content="https://images.example/broken.jpg"><div class="product-gallery"><img data-src="https://images.example/recovered.png"></div>';
    }
    elseif (str_starts_with($c->url,'https://images.example/')) {
        $c->status=str_contains($c->url,'broken.jpg') ? 404 : 200; $body=str_contains($c->url,'invalid.svg') ? '<svg></svg>' : base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl6C6sAAAAASUVORK5CYII=');
    } else { $c->status=200; $body=file_get_contents(__DIR__.'/amazon-product.html'); }
    return ($c->options[CURLOPT_WRITEFUNCTION])($c,$body)===strlen($body);
}
function curl_getinfo(object $c,int $option): int { return $c->status; }
function curl_close(object $c): void {}

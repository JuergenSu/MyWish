<?php
// Nur per auto_prepend_file im isolierten Google-HTTP-Test. Keine Produktionskonfiguration.
namespace MyWish;
function curl_init(string $url): object { return (object)['url'=>$url,'options'=>[],'status'=>0]; }
function curl_setopt_array(object $c,array $options): bool { $c->options=$options; return true; }
function curl_exec(object $c): bool
{
    $fixture=json_decode(file_get_contents(getenv('MYWISH_GOOGLE_TEST_KEYS')),true);
    if ($c->url==='https://www.googleapis.com/oauth2/v3/certs') { $c->status=200; $body=json_encode($fixture['jwks']); }
    elseif ($c->url==='https://oauth2.googleapis.com/token') {
        parse_str($c->options[CURLOPT_POSTFIELDS] ?? '',$form);
        $claims=str_starts_with($form['code'] ?? '', 'fixture:') ? json_decode(base64_decode(substr($form['code'],8)),true) : null;
        $challenge=rtrim(strtr(base64_encode(hash('sha256',$form['code_verifier'] ?? '',true)),'+/','-_'),'=');
        if (!is_array($claims) || ($claims['_challenge'] ?? '')!==$challenge || ($form['client_id'] ?? '')!=='fixture.apps.googleusercontent.com' || ($form['client_secret'] ?? '')!=='test-secret-only' || ($claims['_token_error'] ?? false)) {
            $c->status=400; $body='{}';
        } else {
            unset($claims['_challenge']); $c->status=200;
            $body=json_encode(['id_token'=>\Firebase\JWT\JWT::encode($claims,$fixture['private'],'RS256','test-key'),'access_token'=>'test-token-not-to-be-stored']);
        }
    } else { throw new \RuntimeException('Unexpected provider endpoint'); }
    return ($c->options[CURLOPT_WRITEFUNCTION])($c,$body)===strlen($body);
}
function curl_getinfo(object $c,int $option): int { return $c->status; }
function curl_close(object $c): void {}

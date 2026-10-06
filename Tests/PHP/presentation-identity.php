<?php
// Pure unit checks: no Mautic bootstrap, ORM, fixtures or database connection.
require __DIR__.'/../../Application/Presentation.php';
require __DIR__.'/../../Application/PageContext.php';
require __DIR__.'/../../Security/IdentityVerifier.php';
use MauticPlugin\MauticWebChatBundle\Application\Presentation;
use MauticPlugin\MauticWebChatBundle\Application\PageContext;
use MauticPlugin\MauticWebChatBundle\Security\IdentityVerifier;
function check(bool $ok, string $label): void { if (!$ok) throw new RuntimeException($label); }
$p=Presentation::sanitize(['theme'=>'macro','overrides'=>['macro'=>['options'=>['width'=>999,'font'=>'url(unsafe)','showFooter'=>false],'dark'=>['primary'=>'#16a34a','surface'=>'red;url(x)']]],'fields'=>['phone'=>'hidden']]);
check($p['overrides']['macro']['options']['width']===480,'width clamp');
check(!isset($p['overrides']['macro']['options']['font']),'font allowlist');
check($p['overrides']['macro']['dark']['primary']==='#16A34A','valid color');
check(!isset($p['overrides']['macro']['dark']['surface']),'invalid CSS dropped');
check($p['fields']['phone']==='hidden','visibility');
check(Presentation::sanitize(['overrides'=>['macro'=>['options'=>['fontSize'=>99]]]])['overrides']['macro']['options']['fontSize']===18,'font size bounded');
$page=PageContext::sanitize(['page_url'=>'https://SITE.example:443/market/nfl?token=private#details','page_title'=>"<b>Bears vs Packers</b>\nNFL",'locale'=>'en','subject'=>'fake'],'https://site.example');
check($page===['locale'=>'en','page_url'=>'https://site.example/market/nfl','page_title'=>'Bears vs Packers NFL'],'same-origin page normalized and subject excluded');
foreach(['https://evil.example/page','https://user:pass@site.example/page','javascript:alert(1)','/relative','https://site.example:8443/page'] as $url) {
    check(PageContext::sanitize(['page_url'=>$url,'page_title'=>'Wrong page','locale'=>'es'],'https://site.example')===['locale'=>'es'],'invalid page rejected');
}
check(PageContext::sanitize(['page_url'=>null,'locale'=>'en;ignore'],'https://site.example')===[],'invalid locale rejected');
check(Presentation::contactLocale('pt')==='pt_BR' && Presentation::contactLocale('en')==='en_US' && Presentation::contactLocale('es')==='es_ES','Mautic contact locales');
$key=openssl_pkey_new(['private_key_bits'=>2048,'private_key_type'=>OPENSSL_KEYTYPE_RSA]);
$public=openssl_pkey_get_details($key)['key'];
$encode=fn($v)=>rtrim(strtr(base64_encode(json_encode($v)), '+/', '-_'),'=');
$claims=['iss'=>'https://cms.example','aud'=>'pub_test','origin'=>'https://site.example','iat'=>1000,'exp'=>1300,'sub'=>'macro:42','email'=>'person@example.com'];
$token=function(array $c,string $alg='RS256')use($key,$encode){$data=$encode(['alg'=>$alg,'typ'=>'JWT']).'.'.$encode($c);openssl_sign($data,$signature,$key,OPENSSL_ALGO_SHA256);return $data.'.'.rtrim(strtr(base64_encode($signature),'+/','-_'),'=');};
$v=new IdentityVerifier($public,'https://cms.example');
check($v->verify($token($claims),'pub_test','https://site.example',1001)['sub']==='macro:42','valid signed identity');
foreach([[$token($claims),'pub_other','https://site.example',1001],[$token($claims),'pub_test','https://evil.example',1001],[$token($claims),'pub_test','https://site.example',1300],[$token($claims,'none'),'pub_test','https://site.example',1001],[$token($claims).'bad','pub_test','https://site.example',1001],[$token(array_replace($claims,['iss'=>'evil'])),'pub_test','https://site.example',1001]] as $args){try{$v->verify(...$args);throw new RuntimeException('invalid identity accepted');}catch(DomainException $e){check($e->getMessage()==='identity_invalid','generic identity error');}}
echo "presentation and identity unit checks passed (no database)\n";

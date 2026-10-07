<?php
// Pure checks: no Mautic bootstrap or database connection.
require __DIR__.'/../../Security/IdentityVerifier.php';
use MauticPlugin\MauticWebChatBundle\Security\IdentityVerifier;
function check(bool $condition, string $label): void { if (!$condition) throw new RuntimeException($label); }
$primary=openssl_pkey_new(['private_key_bits'=>2048]);
$tenant=openssl_pkey_new(['private_key_bits'=>2048]);
$encode=fn($value)=>rtrim(strtr(base64_encode(json_encode($value)), '+/', '-_'), '=');
$sign=function($claims,$key)use($encode){$data=$encode(['alg'=>'RS256','typ'=>'JWT']).'.'.$encode($claims);openssl_sign($data,$signature,$key,OPENSSL_ALGO_SHA256);return $data.'.'.rtrim(strtr(base64_encode($signature),'+/','-_'),'=');};
$claims=['iss'=>'https://tenant.back.example','aud'=>'pub_test','origin'=>'https://tenant.example','iat'=>1000,'exp'=>1300,'sub'=>'wl-tenant:5'];
$anchors=json_encode([['issuer'=>$claims['iss'],'widget'=>'pub_test','origins'=>['https://tenant.example'],'namespace'=>'wl-tenant','public_key'=>openssl_pkey_get_details($tenant)['key']]]);
$verifier=new IdentityVerifier(openssl_pkey_get_details($primary)['key'],'https://cms.example',$anchors);
check($verifier->verify($sign($claims,$tenant),'pub_test','https://tenant.example',1001)['sub']==='wl-tenant:5','tenant accepted');
$legacy=array_replace($claims,['iss'=>'https://cms.example','sub'=>'macro:5','origin'=>'https://site.example']);
check($verifier->verify($sign($legacy,$primary),'pub_test','https://site.example',1001)['sub']==='macro:5','original installation preserved');
$invalid=[
 [$claims,$primary,'pub_test','https://tenant.example'],
 [array_replace($claims,['iss'=>'https://other.back.example']),$tenant,'pub_test','https://tenant.example'],
 [array_replace($claims,['sub'=>'macro:5']),$tenant,'pub_test','https://tenant.example'],
 [array_replace($claims,['sub'=>'wl-other:5']),$tenant,'pub_test','https://tenant.example'],
 [array_replace($claims,['origin'=>'https://evil.example']),$tenant,'pub_test','https://evil.example'],
 [array_replace($claims,['aud'=>'pub_other']),$tenant,'pub_other','https://tenant.example'],
 [$legacy,$tenant,'pub_test','https://site.example'],
];
foreach($invalid as [$c,$key,$widget,$origin]) {
 try { $verifier->verify($sign($c,$key),$widget,$origin,1001); throw new RuntimeException('cross-installation identity accepted'); }
 catch(DomainException $e) { check($e->getMessage()==='identity_invalid','generic rejection'); }
}
echo "tenant trust checks passed (9 assertions, no database)\n";

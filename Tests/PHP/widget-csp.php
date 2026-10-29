<?php
// Pure unit checks: no Mautic bootstrap, ORM, fixtures or database connection.
require __DIR__.'/../../Security/WidgetContentSecurityPolicy.php';
use MauticPlugin\MauticWebChatBundle\Security\WidgetContentSecurityPolicy;
function check(bool $ok, string $label): void { if (!$ok) throw new RuntimeException($label); }
$header=WidgetContentSecurityPolicy::header('bm9uY2U=',['macro.markets','www.macro.markets']);
$directives=[];
foreach(explode(';',$header) as $directive){$parts=preg_split('/\s+/',trim($directive));$directives[array_shift($parts)]=$parts;}
check($directives['script-src']===["'self'","'nonce-bm9uY2U='"],'scripts from the origin and the nonce only');
check(!in_array("'unsafe-inline'",$directives['script-src'],true),'inline scripts still need the nonce');
check($directives['frame-ancestors']===["'self'",'https://macro.markets','https://www.macro.markets'],'allowed domains frame the widget');
check($directives['default-src']===["'self'"] && $directives['connect-src']===["'self'"],'other sources unchanged');
check(WidgetContentSecurityPolicy::header('bm9uY2U=',[])===str_replace(" https://macro.markets https://www.macro.markets",'',$header),'no domains leaves the widget to its own origin');
$nonce=WidgetContentSecurityPolicy::nonce();
check(1===preg_match('/^[A-Za-z0-9+\/]{22}==$/',$nonce) && strlen(base64_decode($nonce,true))===16,'nonce carries 128 random bits');
check($nonce!==WidgetContentSecurityPolicy::nonce(),'each response gets its own nonce');
echo "widget content security policy checks passed (no database)\n";

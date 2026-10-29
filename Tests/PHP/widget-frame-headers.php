<?php
// Pure unit checks: no Mautic bootstrap, ORM, fixtures or database connection.
require __DIR__.'/../../Security/WidgetFrameHeaders.php';
use MauticPlugin\MauticWebChatBundle\Security\WidgetFrameHeaders;
function check(bool $ok, string $label): void { if (!$ok) throw new RuntimeException($label); }
$headers=WidgetFrameHeaders::for(['macro.markets','www.macro.markets']);
$directives=[];
foreach(explode(';',$headers['Content-Security-Policy']) as $directive){$parts=preg_split('/\s+/',trim($directive));$directives[array_shift($parts)]=$parts;}
check($directives['script-src']===["'self'"],'scripts only from the plugin origin, no inline allowance');
check($directives['frame-ancestors']===["'self'",'https://macro.markets','https://www.macro.markets'],'allowed domains frame the widget');
check($directives['default-src']===["'self'"] && $directives['connect-src']===["'self'"] && $directives['style-src']===["'self'","'unsafe-inline'"],'other sources unchanged');
check(WidgetFrameHeaders::for([])['Content-Security-Policy']===str_replace(' https://macro.markets https://www.macro.markets','',$headers['Content-Security-Policy']),'no domains leaves the widget to its own origin');
$cache=array_map('trim',explode(',',$headers['Cache-Control']));
check(in_array('no-transform',$cache,true),'the edge must not inject scripts the policy refuses');
check(in_array('no-store',$cache,true),'browsers do not store the frame');
echo "widget frame header checks passed (no database)\n";

<?php

declare(strict_types=1);

namespace MauticPlugin\MauticWebChatBundle\Controller;

use Mautic\CoreBundle\Controller\CommonController;
use MauticPlugin\MauticWebChatBundle\Application\ChatService;
use MauticPlugin\MauticWebChatBundle\Entity\ChatWidget;
use MauticPlugin\MauticWebChatBundle\Entity\ChatWidgetRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class PublicController extends CommonController
{
    public function loader(Request $request, ChatWidgetRepository $widgets): Response
    {
        $widget = $widgets->findOneBy(['publicKey' => $request->query->getString('id'), 'published' => true]);
        if (!$widget instanceof ChatWidget) {
            return new Response('console.warn("Mautic Web Chat indisponível");', 404, ['Content-Type' => 'application/javascript; charset=UTF-8']);
        }
        $frame = $this->generateUrl('mautic_webchat_widget', ['publicKey' => $widget->getPublicKey()], UrlGeneratorInterface::ABSOLUTE_URL);
        $script = <<<'JS'
(function(){
  if(window.MauticWebChat)return;
  var ready=false,pending=[],identity={},context={},layout={width:400,position:'right'},draft='',isOpen=false,audioContext=null,destroyed=false,lastContext='';
  var frame=document.createElement('iframe');frame.src=FRAME_URL;frame.title='Atendimento';frame.setAttribute('allow','autoplay; clipboard-write');
  frame.style.cssText='position:fixed;z-index:2147483000;right:0;bottom:0;width:104px;height:104px;border:0;background:transparent;color-scheme:light;';
  frame.dataset.mauticWebchat='1';document.body.appendChild(frame);var targetOrigin=new URL(FRAME_URL).origin;
  function viewportBox(){var viewport=window.visualViewport;return{width:Math.round(viewport?viewport.width:innerWidth),height:Math.round(viewport?viewport.height:innerHeight),left:Math.round(viewport?viewport.offsetLeft:0),top:Math.round(viewport?viewport.offsetTop:0)};}
  function placeFrame(){
    if(!isOpen){frame.style.left='auto';frame.style.top='auto';frame.style.right=layout.position==='left'?'auto':'0';frame.style.left=layout.position==='left'?'0':'auto';frame.style.bottom='0';frame.style.width='104px';frame.style.height='104px';return;}
    var viewport=viewportBox();
    if(viewport.width<520){frame.style.left=(viewport.left+8)+'px';frame.style.top=(viewport.top+8)+'px';frame.style.right='auto';frame.style.bottom='auto';frame.style.width=Math.max(0,viewport.width-16)+'px';frame.style.height=Math.max(0,viewport.height-16)+'px';return;}
    frame.style.left='auto';frame.style.top='auto';frame.style.right=layout.position==='left'?'auto':'16px';frame.style.left=layout.position==='left'?'16px':'auto';frame.style.bottom='16px';frame.style.width=layout.width+'px';frame.style.height=Math.min(680,Math.max(0,viewport.height-32))+'px';
  }
  function onViewportChange(){if(isOpen)placeFrame();}
  window.addEventListener('resize',onViewportChange);
  if(window.visualViewport){window.visualViewport.addEventListener('resize',onViewportChange);window.visualViewport.addEventListener('scroll',onViewportChange);}
  function emit(name,detail){window.dispatchEvent(new CustomEvent('mautic-webchat:'+name,{detail:detail||{}}));}
  function unlockSound(){try{var AudioCtor=window.AudioContext||window.webkitAudioContext;if(!AudioCtor)return false;if(!audioContext)audioContext=new AudioCtor();if(audioContext.state==='suspended')audioContext.resume();return audioContext.state==='running';}catch(error){return false;}}
  function playSound(){if(!unlockSound()||!audioContext)return;var now=audioContext.currentTime;[0,0.12].forEach(function(delay,index){var oscillator=audioContext.createOscillator(),gain=audioContext.createGain();oscillator.type='sine';oscillator.frequency.value=index?740:620;gain.gain.setValueAtTime(0.0001,now+delay);gain.gain.exponentialRampToValueAtTime(0.055,now+delay+0.015);gain.gain.exponentialRampToValueAtTime(0.0001,now+delay+0.13);oscillator.connect(gain);gain.connect(audioContext.destination);oscillator.start(now+delay);oscillator.stop(now+delay+0.14);});}
  function primeSound(){unlockSound();}
  document.addEventListener('pointerdown',primeSound,{passive:true});
  document.addEventListener('keydown',primeSound,{passive:true});
  function command(action,payload){var message=Object.assign({type:'webchat.command',action:action},payload||{});if(ready&&frame.contentWindow)frame.contentWindow.postMessage(message,targetOrigin);else pending.push(message);}
  function safeUrl(value){try{var url=new URL(value);return /^https?:$/.test(url.protocol)&&!url.username&&!url.password?url.origin+url.pathname:'';}catch(error){return '';}}
  function pageContext(){var utm={};['utm_source','utm_medium','utm_campaign','utm_content','utm_term'].forEach(function(key){var value=new URLSearchParams(location.search).get(key);if(value)utm[key]=value.slice(0,255);});return {pageUrl:safeUrl(location.href),pageTitle:String(document.title||'').trim().slice(0,160),referrer:safeUrl(document.referrer),utm:utm};}
  function siteContext(){return Object.assign({locale:document.documentElement.lang||navigator.language||'pt',appearance:document.documentElement.classList.contains('dark')?'dark':'light',fontFamily:getComputedStyle(document.body).fontFamily},context);}
  function refreshContext(){if(destroyed)return;var next=Object.assign({context:siteContext()},pageContext()),signature=JSON.stringify(next);if(signature===lastContext)return;lastContext=signature;if(ready)command('configure',next);}
  var contextObserver=new MutationObserver(refreshContext);
  contextObserver.observe(document.documentElement,{attributes:true,attributeFilter:['class','lang']});
  if(document.head)contextObserver.observe(document.head,{childList:true,characterData:true,subtree:true});
  contextObserver.observe(document.body,{attributes:true,attributeFilter:['class','style']});
  var historyHooks=[];
  ['pushState','replaceState'].forEach(function(name){var original=history[name],wrapper=function(){var result=original.apply(this,arguments);refreshContext();return result;};history[name]=wrapper;historyHooks.push({name:name,original:original,wrapper:wrapper});});
  window.addEventListener('popstate',refreshContext);window.addEventListener('pageshow',refreshContext);
  var api={
    isReady:function(){return ready;},
    open:function(){command('open');},close:function(){command('close');},toggle:function(){command('toggle');},
    openWithMessage:function(message){draft=String(message||'');command('open',{message:draft});},
    identify:function(user){identity=Object.assign({},user||{});command('identify',{user:identity});},
    configure:function(next){context=Object.assign({},context,next||{});refreshContext();},
    reset:function(){identity={};draft='';context.accountPending=false;pending=pending.filter(function(message){return message.action==='configure';});command('reset');},
    destroy:function(){destroyed=true;contextObserver.disconnect();historyHooks.forEach(function(hook){if(history[hook.name]===hook.wrapper)history[hook.name]=hook.original;});window.removeEventListener('popstate',refreshContext);window.removeEventListener('pageshow',refreshContext);window.removeEventListener('message',onMessage);window.removeEventListener('resize',onViewportChange);document.removeEventListener('pointerdown',primeSound);document.removeEventListener('keydown',primeSound);if(window.visualViewport){window.visualViewport.removeEventListener('resize',onViewportChange);window.visualViewport.removeEventListener('scroll',onViewportChange);}if(audioContext)audioContext.close();frame.remove();delete window.MauticWebChat;}
  };
  window.MauticWebChat=api;
  function onMessage(event){if(event.source!==frame.contentWindow||event.origin!==targetOrigin)return;
    if(event.data&&event.data.type==='webchat.ready'){
      ready=true;frame.contentWindow.postMessage(Object.assign({type:'webchat.bootstrap',siteOrigin:location.origin,user:identity,message:draft,context:siteContext()},pageContext()),targetOrigin);refreshContext();
      pending.splice(0).forEach(function(message){frame.contentWindow.postMessage(message,targetOrigin);});emit('ready');
    }
    if(event.data&&event.data.type==='webchat.presentation'){layout.width=Math.min(480,Math.max(320,Number(event.data.width)||400));layout.position=event.data.position==='left'?'left':'right';frame.title=event.data.locale==='pt'?'Atendimento':event.data.locale==='es'?'Atención':'Support';placeFrame();}
    if(event.data&&event.data.type==='webchat.resize'){isOpen=!!event.data.open;placeFrame();}
    if(event.data&&event.data.type==='webchat.state')emit(event.data.open?'open':'close',{open:!!event.data.open});
    if(event.data&&event.data.type==='webchat.identity.refresh')emit('identity-refresh');
    if(event.data&&event.data.type==='webchat.notification'){if(!event.data.played)playSound();emit('notification',{unread:Number(event.data.unread||0)});}
    if(event.data&&event.data.type==='webchat.unread')emit('unread',{count:Number(event.data.count||0)});
    if(event.data&&event.data.type==='webchat.error')emit('error',{message:String(event.data.message||'Erro no WebChat')});
  }
  window.addEventListener('message',onMessage);
})();
JS;
        $script = str_replace('FRAME_URL', json_encode($frame, JSON_THROW_ON_ERROR), $script);
        return new Response($script, 200, ['Content-Type' => 'application/javascript; charset=UTF-8', 'Cache-Control' => 'public, max-age=300', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function widget(string $publicKey, ChatWidgetRepository $widgets): Response
    {
        $widget = $widgets->findOneBy(['publicKey' => $publicKey, 'published' => true]);
        if (!$widget instanceof ChatWidget) {
            return new Response('Chat indisponível.', 404);
        }
        $response = $this->render('@MauticWebChat/Public/widget.html.twig', [
            'publicKey' => $publicKey,
            'widgetConfig' => ['name' => $widget->getName(), 'greeting' => $widget->getGreeting(), 'offline_message' => $widget->getOfflineMessage(), 'presentation' => $widget->getPresentation(), 'accent_color' => $widget->getAccentColor(), 'require_name' => $widget->requiresName(), 'require_email' => $widget->requiresEmail(), 'require_phone' => $widget->requiresPhone()],
            'assetVersion' => (string) (@filemtime(__DIR__.'/../Assets/dist/widget-app.js') ?: time()),
        ]);
        $response->headers->remove('X-Frame-Options');
        $ancestors = array_map(static fn (string $domain): string => 'https://'.$domain, $widget->getAllowedDomains());
        $response->headers->set('Content-Security-Policy', "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; connect-src 'self'; img-src 'self' data: https:; frame-ancestors 'self' ".implode(' ', $ancestors));
        $response->headers->set('Cache-Control', 'no-store');
        return $response;
    }

    public function demo(string $publicKey, ChatWidgetRepository $widgets): Response
    {
        $widget = $widgets->findOneBy(['publicKey' => $publicKey, 'published' => true]);
        if (!$widget instanceof ChatWidget) {
            return new Response('Chat indisponível.', 404);
        }
        return $this->render('@MauticWebChat/Public/demo.html.twig', ['widget' => $widget, 'loaderUrl' => $this->generateUrl('mautic_webchat_embed', ['id' => $publicKey], UrlGeneratorInterface::ABSOLUTE_URL)]);
    }

    public function session(string $publicKey, Request $request, ChatWidgetRepository $widgets, ChatService $chat): JsonResponse
    {
        return $this->jsonCall(function () use ($publicKey, $request, $widgets, $chat): array {
            $widget = $widgets->findOneBy(['publicKey' => $publicKey]);
            if (!$widget instanceof ChatWidget) {
                throw new \DomainException('Widget não encontrado.');
            }
            return $chat->open($widget, $this->payload($request));
        });
    }

    public function history(string $publicId, Request $request, ChatService $chat): JsonResponse
    {
        return $this->jsonCall(fn (): array => ['messages' => $chat->history($chat->authenticate($publicId, $this->bearer($request)))]);
    }

    public function message(string $publicId, Request $request, ChatService $chat): JsonResponse
    {
        return $this->jsonCall(function () use ($publicId, $request, $chat): array {
            $input = $this->payload($request);
            return ['message' => $chat->messageData($chat->receiveVisitor($chat->authenticate($publicId, $this->bearer($request)), (string) ($input['body'] ?? ''), (string) ($input['client_id'] ?? ''), $input))];
        }, 201);
    }

    /** @param callable():array<string,mixed> $callback */
    private function jsonCall(callable $callback, int $status = 200): JsonResponse
    {
        try {
            return new JsonResponse($callback(), $status, ['Cache-Control' => 'no-store']);
        } catch (\DomainException $exception) {
            return new JsonResponse(['error' => $exception->getMessage(), 'code' => preg_match('/^[a-z_]+$/', $exception->getMessage()) ? $exception->getMessage() : 'request_failed'], 422, ['Cache-Control' => 'no-store']);
        } catch (\Throwable) {
            return new JsonResponse(['error' => 'Não foi possível completar a solicitação.'], 500, ['Cache-Control' => 'no-store']);
        }
    }

    /** @return array<string,mixed> */
    private function payload(Request $request): array
    {
        try {
            $value = json_decode($request->getContent(), true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \DomainException('Requisição inválida.');
        }
        if (!is_array($value)) {
            throw new \DomainException('Requisição inválida.');
        }
        return $value;
    }

    private function bearer(Request $request): string
    {
        $header = $request->headers->get('Authorization', '');
        return str_starts_with($header, 'Bearer ') ? substr($header, 7) : '';
    }
}

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
  if(window.__mauticWebChatLoaded)return;window.__mauticWebChatLoaded=true;
  var frame=document.createElement('iframe');frame.src=FRAME_URL;frame.title='Atendimento';frame.setAttribute('allow','clipboard-write');
  frame.style.cssText='position:fixed;z-index:2147483000;right:16px;bottom:16px;width:76px;height:76px;border:0;background:transparent;color-scheme:light;';
  frame.dataset.mauticWebchat='1';document.body.appendChild(frame);
  window.addEventListener('message',function(event){if(event.source!==frame.contentWindow)return;
    if(event.data&&event.data.type==='webchat.ready')frame.contentWindow.postMessage({type:'webchat.bootstrap',siteOrigin:location.origin,pageUrl:location.href,referrer:document.referrer,utm:Object.fromEntries(new URLSearchParams(location.search))},event.origin);
    if(event.data&&event.data.type==='webchat.resize'){var open=!!event.data.open;frame.style.width=open?(innerWidth<520?'calc(100vw - 16px)':'400px'):'76px';frame.style.height=open?(innerWidth<520?'calc(100vh - 16px)':'min(680px, calc(100vh - 32px))'):'76px';frame.style.right=open&&innerWidth<520?'8px':'16px';frame.style.bottom=open&&innerWidth<520?'8px':'16px';}
  });
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
            'widgetConfig' => ['name' => $widget->getName(), 'greeting' => $widget->getGreeting(), 'offline_message' => $widget->getOfflineMessage(), 'accent_color' => $widget->getAccentColor(), 'require_name' => $widget->requiresName(), 'require_email' => $widget->requiresEmail()],
            'assetVersion' => (string) (@filemtime(__DIR__.'/../Assets/dist/widget-app.js') ?: time()),
        ]);
        $response->headers->remove('X-Frame-Options');
        $ancestors = array_map(static fn (string $domain): string => 'https://'.$domain, $widget->getAllowedDomains());
        $response->headers->set('Content-Security-Policy', "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; connect-src 'self' wss: ws:; img-src 'self' data: https:; frame-ancestors 'self' ".implode(' ', $ancestors));
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
            return ['message' => $chat->messageData($chat->receiveVisitor($chat->authenticate($publicId, $this->bearer($request)), (string) ($input['body'] ?? ''), (string) ($input['client_id'] ?? '')))];
        }, 201);
    }

    /** @param callable():array<string,mixed> $callback */
    private function jsonCall(callable $callback, int $status = 200): JsonResponse
    {
        try {
            return new JsonResponse($callback(), $status, ['Cache-Control' => 'no-store']);
        } catch (\DomainException $exception) {
            return new JsonResponse(['error' => $exception->getMessage()], 422, ['Cache-Control' => 'no-store']);
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

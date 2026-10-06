<?php

declare(strict_types=1);

namespace MauticPlugin\MauticWebChatBundle\Controller;

use Mautic\CoreBundle\Controller\CommonController;
use MauticPlugin\MauticWebChatBundle\Application\ChatService;
use MauticPlugin\MauticWebChatBundle\Entity\ChatSession;
use MauticPlugin\MauticWebChatBundle\Entity\ChatSessionRepository;
use MauticPlugin\MauticWebChatBundle\Realtime\GatewayClient;
use MauticPlugin\MauticWebChatBundle\Security\RealtimeRateLimit;
use MauticPlugin\MauticWebChatBundle\Security\RealtimeTokenSigner;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class RealtimeController extends CommonController
{
    public function events(Request $request, ChatSessionRepository $sessions, ChatService $chat, RealtimeTokenSigner $tokens, GatewayClient $gateway, RealtimeRateLimit $limiter): JsonResponse
    {
        try {
            $authorization = $request->headers->get('Authorization', '');
            $claims = $tokens->verify(str_starts_with($authorization, 'Bearer ') ? substr($authorization, 7) : '');
        } catch (\DomainException) {
            return new JsonResponse(['error' => 'Unauthorized'], 401);
        }
        if (strlen($request->getContent()) > 16384) {
            return new JsonResponse(['error' => 'Payload too large'], 413);
        }
        try {
            $input = json_decode($request->getContent(), true, 16, JSON_THROW_ON_ERROR);
            if (!is_array($input)) throw new \DomainException('Evento inválido.');
            $type = (string) ($input['type'] ?? '');
            if (!in_array($type, ['message.send', 'message.delivered', 'message.read', 'typing.started', 'typing.stopped'], true)) {
                throw new \DomainException('Evento inválido.');
            }
            if (!$limiter->allow($claims['sid'], $claims['role'], $type)) {
                return new JsonResponse(['error' => 'Aguarde antes de enviar novos eventos.'], 429, ['Retry-After' => '5']);
            }
            $session = $sessions->findOneBy(['publicId' => $claims['sid']]);
            if (!$session instanceof ChatSession || 'open' !== $session->getStatus()) {
                throw new \DomainException('Sessão inválida.');
            }
            $role = $claims['role'];
            if ('message.send' === $type && 'visitor' === $role) {
                $message = $chat->receiveVisitor($session, (string) ($input['body'] ?? ''), (string) ($input['client_id'] ?? ''), $input);
                return new JsonResponse(['ok' => true, 'message' => $chat->messageData($message)]);
            }
            if (in_array($type, ['message.delivered', 'message.read'], true)) {
                $chat->receipt($session, $role, substr($type, 8), (int) ($input['message_id'] ?? 0));
                return new JsonResponse(['ok' => true]);
            }
            if (str_starts_with($type, 'typing.')) {
                // Identity and role come from the signed session, never from client input.
                $name = 'visitor' === $role ? $session->getVisitorName() : 'Atendimento';
                $gateway->publish($claims['sid'], ['type' => $type, 'role' => $role, 'name' => $name, 'expires_in' => 6]);
                return new JsonResponse(['ok' => true]);
            }
            throw new \DomainException('Evento inválido.');
        } catch (\JsonException|\DomainException $exception) {
            return new JsonResponse(['error' => $exception->getMessage()], 422);
        }
    }
}

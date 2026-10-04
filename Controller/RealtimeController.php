<?php

declare(strict_types=1);

namespace MauticPlugin\MauticWebChatBundle\Controller;

use Mautic\CoreBundle\Controller\CommonController;
use MauticPlugin\MauticWebChatBundle\Application\ChatService;
use MauticPlugin\MauticWebChatBundle\Entity\ChatSession;
use MauticPlugin\MauticWebChatBundle\Entity\ChatSessionRepository;
use MauticPlugin\MauticWebChatBundle\Security\RealtimeTokenSigner;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class RealtimeController extends CommonController
{
    public function ingest(Request $request, ChatSessionRepository $sessions, ChatService $chat, RealtimeTokenSigner $tokens): JsonResponse
    {
        $authorization = $request->headers->get('Authorization', '');
        if (!str_starts_with($authorization, 'Bearer ') || !hash_equals($tokens->gatewaySecret(), substr($authorization, 7))) {
            return new JsonResponse(['error' => 'Unauthorized'], 401);
        }
        try {
            $input = json_decode($request->getContent(), true, 32, JSON_THROW_ON_ERROR);
            $session = $sessions->findOneBy(['publicId' => (string) ($input['session'] ?? '')]);
            if (!$session instanceof ChatSession || 'open' !== $session->getStatus()) {
                throw new \DomainException('Sessão inválida.');
            }
            $type = (string) ($input['type'] ?? '');
            $role = (string) ($input['role'] ?? '');
            if ('message.send' === $type && 'visitor' === $role) {
                $message = $chat->receiveVisitor($session, (string) ($input['body'] ?? ''), (string) ($input['client_id'] ?? ''));
                return new JsonResponse(['ok' => true, 'message' => $chat->messageData($message)]);
            }
            if (in_array($type, ['message.delivered', 'message.read'], true)) {
                $chat->receipt($session, $role, substr($type, 8), (int) ($input['message_id'] ?? 0));
                return new JsonResponse(['ok' => true]);
            }
            throw new \DomainException('Evento inválido.');
        } catch (\JsonException|\DomainException $exception) {
            return new JsonResponse(['error' => $exception->getMessage()], 422);
        }
    }
}

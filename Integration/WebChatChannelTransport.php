<?php

declare(strict_types=1);

namespace MauticPlugin\MauticWebChatBundle\Integration;

use MauticPlugin\MauticInboxBundle\Contract\ChannelTransportInterface;
use MauticPlugin\MauticInboxBundle\Contract\BatchChannelTransportInterface;
use MauticPlugin\MauticWebChatBundle\Entity\ChatSessionRepository;
use MauticPlugin\MauticInboxBundle\Entity\ConversationState;
use MauticPlugin\MauticInboxBundle\Entity\OutboundRequest;
use MauticPlugin\MauticMetaBundle\Entity\MetaConversation;
use MauticPlugin\MauticWebChatBundle\Application\ChatService;
use MauticPlugin\MauticWebChatBundle\Entity\ChatMessage;
use MauticPlugin\MauticWebChatBundle\Entity\ChatMessageRepository;
use MauticPlugin\MauticWebChatBundle\Security\RealtimeTokenSigner;

final class WebChatChannelTransport implements ChannelTransportInterface, BatchChannelTransportInterface
{
    private array $primedSessions = [];
    private array $primedPreviews = [];

    public function __construct(
        private ChatService $chat,
        private ChatMessageRepository $messages,
        private RealtimeTokenSigner $tokens,
        private ChatSessionRepository $sessions,
    ) {
    }

    public function warmConversationMetadata(array $states): void
    {
        $this->primedSessions = $this->primedPreviews = [];
        $ids = array_map(static fn (ConversationState $state): int => (int) $state->getConversation()->getId(), $states);
        $sessions = $this->sessions->forConversations($ids);
        foreach ($sessions as $session) $this->primedSessions[(int) $session->getConversation()->getId()] = $session;
        $sessionIds = array_map(static fn ($session): int => (int) $session->getId(), $sessions);
        $this->primedPreviews = array_fill_keys($sessionIds, '');
        foreach ($this->messages->previews($sessionIds) as $row) $this->primedPreviews[(int) $row['session_id']] = mb_substr($row['body'], 0, 180);
    }

    public function supports(MetaConversation $conversation): bool
    {
        return 'webchat' === $conversation->getChannel();
    }

    public function replyBlockedReason(ConversationState $state): ?string
    {
        try {
            $session = $this->chat->sessionFor($state->getConversation());
        } catch (\Throwable) {
            return 'A sessão deste chat não está disponível.';
        }
        if ('open' !== $session->getStatus()) {
            return 'Este chat foi encerrado.';
        }
        if (!$session->getWidget()->isPublished()) {
            return 'O widget deste chat está desativado.';
        }
        if (!$this->tokens->isConfigured()) {
            return 'O serviço em tempo real do Web Chat ainda não foi configurado.';
        }
        return null;
    }

    public function sendHuman(ConversationState $state, OutboundRequest $request): void
    {
        $this->chat->sendHuman($state, $request);
    }

    public function sendAi(ConversationState $state, string $body, array $metadata): void
    {
        $this->chat->sendAi($state, $body, $metadata);
    }

    public function setTyping(ConversationState $state, bool $active, string $name): void
    {
        $this->chat->setTyping($state, $active, $name);
    }

    public function conversationMetadata(ConversationState $state): array
    {
        $session = $this->primedSessions[(int) $state->getConversation()->getId()] ?? $this->chat->sessionFor($state->getConversation());
        $preview = $this->primedPreviews[(int) $session->getId()] ?? null;
        if (null === $preview) {
            $latest = $this->messages->findOneBy(['session' => $session], ['id' => 'DESC']);
            $preview = $latest instanceof ChatMessage ? mb_substr($latest->getBody(), 0, 180) : '';
        }
        $name = $session->getVisitorName() ?: ($session->getContact()?->getName() ?: 'Visitante do site');
        $pageUrl = $session->getPageUrl();
        $pageHost = is_string($pageUrl) ? (string) parse_url($pageUrl, PHP_URL_HOST) : '';
        $origins = [];
        if ($pageUrl) {
            $origins[] = [
                'title' => '' !== $pageHost ? $pageHost : 'Página de origem',
                'author' => 'Web Chat',
                'body' => $pageUrl,
                'permalink' => $pageUrl,
            ];
        }

        return [
            'contact_name' => $name,
            'contact_handle' => $session->getVisitorEmail(),
            'recipient' => $session->getVisitorEmail() ?: $session->getPublicId(),
            'preview' => $preview,
            'channel' => 'webchat',
            'conversation_kind' => 'Chat do site',
            'asset' => ['id' => $session->getWidget()->getId(), 'name' => $session->getWidget()->getName(), 'channel' => 'webchat'],
            'origins' => $origins,
            'realtime' => $this->tokens->issue($session->getPublicId(), 'agent'),
            'webchat' => [
                'locale' => $session->getContext()['locale'] ?? 'pt',
                'external_id' => $session->getContext()['subject'] ?? null,
                'identity_verified' => null !== ($session->getContext()['subject'] ?? null),
                'page_url' => $pageUrl,
                'site_origin' => $session->getSiteOrigin(),
                'referrer' => $session->getReferrer(),
                'utm' => $session->getUtm(),
                'visitor_last_read_message_id' => $session->getVisitorLastReadMessageId(),
                'agent_last_read_message_id' => $session->getAgentLastReadMessageId(),
            ],
        ];
    }

    public function markRead(ConversationState $state): void
    {
        $session = $this->chat->sessionFor($state->getConversation());
        $message = $this->messages->findOneBy(['session' => $session, 'direction' => 'visitor'], ['id' => 'DESC']);
        if ($message instanceof ChatMessage) {
            $this->chat->receipt($session, 'agent', 'read', (int) $message->getId());
        }
    }
}

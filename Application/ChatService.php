<?php

declare(strict_types=1);

namespace MauticPlugin\MauticWebChatBundle\Application;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Model\LeadModel;
use MauticPlugin\MauticInboxBundle\Application\Ai\AiService;
use MauticPlugin\MauticInboxBundle\Entity\ConversationState;
use MauticPlugin\MauticInboxBundle\Entity\ConversationStateRepository;
use MauticPlugin\MauticInboxBundle\Entity\OutboundRequest;
use MauticPlugin\MauticInboxBundle\Integration\MetaInboxIntegration;
use MauticPlugin\MauticMetaBundle\Entity\MetaConversation;
use MauticPlugin\MauticMetaBundle\Entity\MetaMessage;
use MauticPlugin\MauticWebChatBundle\Entity\ChatMessage;
use MauticPlugin\MauticWebChatBundle\Entity\ChatMessageRepository;
use MauticPlugin\MauticWebChatBundle\Entity\ChatSession;
use MauticPlugin\MauticWebChatBundle\Entity\ChatSessionRepository;
use MauticPlugin\MauticWebChatBundle\Entity\ChatWidget;
use MauticPlugin\MauticWebChatBundle\Realtime\GatewayClient;
use MauticPlugin\MauticWebChatBundle\Security\RealtimeTokenSigner;
use MauticPlugin\MauticWebChatBundle\Security\WidgetOrigin;

final class ChatService
{
    public function __construct(
        private EntityManagerInterface $em,
        private ChatSessionRepository $sessions,
        private ChatMessageRepository $messages,
        private ConversationStateRepository $states,
        private MetaInboxIntegration $inbox,
        private AiService $ai,
        private LeadModel $leads,
        private GatewayClient $gateway,
        private RealtimeTokenSigner $tokens,
        private WidgetOrigin $origins,
    ) {
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function open(ChatWidget $widget, array $input): array
    {
        if (!$widget->isPublished()) {
            throw new \DomainException('Este chat não está disponível.');
        }
        $origin = $this->origins->assertAllowed($widget, (string) ($input['site_origin'] ?? ''));
        $visitorId = trim((string) ($input['visitor_id'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9_-]{16,64}$/', $visitorId)) {
            throw new \DomainException('Identificador do visitante inválido.');
        }
        $name = mb_substr(trim((string) ($input['name'] ?? '')), 0, 120);
        $email = strtolower(mb_substr(trim((string) ($input['email'] ?? '')), 0, 190));
        if ($widget->requiresName() && '' === $name) {
            throw new \DomainException('Informe seu nome para iniciar o atendimento.');
        }
        if ($widget->requiresEmail() && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \DomainException('Informe um e-mail válido para iniciar o atendimento.');
        }
        if ('' !== $email && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \DomainException('O e-mail informado não é válido.');
        }

        $plainToken = trim((string) ($input['resume_token'] ?? ''));
        $publicId = trim((string) ($input['resume_session'] ?? ''));
        $session = '' !== $publicId ? $this->sessions->findOneBy(['publicId' => $publicId, 'widget' => $widget]) : null;
        if (!$session instanceof ChatSession || !$session->tokenMatches($plainToken) || 'open' !== $session->getStatus()) {
            $session = null;
            $plainToken = bin2hex(random_bytes(32));
        }
        $contact = $session instanceof ChatSession && $session->getVisitorName() === ('' === $name ? null : $name) && $session->getVisitorEmail() === ('' === $email ? null : $email)
            ? $session->getContact() : $this->contact($name, $email);
        if (!$session instanceof ChatSession) {
            $conversation = (new MetaConversation())
                ->setAsset($widget->getAsset())
                ->setContact($contact)
                ->setChannel('webchat')
                ->setRecipient('webchat:pending:'.bin2hex(random_bytes(8)))
                ->setStatus('open');
            $session = (new ChatSession())
                ->setWidget($widget)
                ->setConversation($conversation)
                ->setContact($contact)
                ->setToken($plainToken)
                ->setVisitorId($visitorId)
                ->setSiteOrigin($origin);
            $conversation->setRecipient('webchat:'.$session->getPublicId());
            $this->em->persist($conversation);
            $this->em->persist($session);
        } elseif ($contact instanceof Lead) {
            $session->setContact($contact);
            $session->getConversation()->setContact($contact);
        }
        $session->setVisitorName('' === $name ? null : $name)
            ->setVisitorEmail('' === $email ? null : $email)
            ->setPageUrl($this->url($input['page_url'] ?? null))
            ->setReferrer($this->url($input['referrer'] ?? null))
            ->setUtm($this->utm($input['utm'] ?? []))
            ->seen();
        $this->em->persist($session);
        $this->em->persist($session->getConversation());
        $this->em->flush();

        return [
            'session' => $session->getPublicId(),
            'session_token' => $plainToken,
            'realtime' => $this->tokens->issue($session->getPublicId(), 'visitor'),
            'visitor_last_read_message_id' => $session->getVisitorLastReadMessageId(),
            'widget' => $this->widgetData($widget),
            'messages' => $this->history($session),
        ];
    }

    public function authenticate(string $publicId, string $plainToken): ChatSession
    {
        $session = $this->sessions->findOneBy(['publicId' => $publicId]);
        if (!$session instanceof ChatSession || !$session->tokenMatches($plainToken) || 'open' !== $session->getStatus()) {
            throw new \DomainException('Sessão de chat inválida ou encerrada.');
        }
        return $session;
    }

    /** @return list<array<string,mixed>> */
    public function history(ChatSession $session): array
    {
        return $this->messages->timeline($session);
    }

    public function receiveVisitor(ChatSession $session, string $body, string $clientId): ChatMessage
    {
        $body = $this->body($body);
        $clientId = $this->clientId($clientId);
        $existing = $this->messages->findOneBy(['clientId' => $clientId]);
        if ($existing instanceof ChatMessage) {
            if ($existing->getSession()->getId() !== $session->getId() || $existing->getBody() !== $body) {
                throw new \DomainException('Identificador de mensagem já utilizado.');
            }
            return $existing;
        }

        $conversation = $session->getConversation();
        $now = new \DateTimeImmutable();
        $profile = array_filter(['name' => $session->getVisitorName(), 'email' => $session->getVisitorEmail()]);
        $meta = (new MetaMessage())
            ->setAsset($conversation->getAsset())
            ->setConversation($conversation)
            ->setContact($session->getContact())
            ->setExternalId('webchat-in-'.$clientId)
            ->setChannel('webchat')
            ->setDirection('inbound')
            ->setMessageType('text')
            ->setRecipient($conversation->getRecipient())
            ->setStatus('received')
            ->setPayload(['text' => $body, 'contact' => ['profile' => $profile], 'webchat' => ['page_url' => $session->getPageUrl(), 'origin' => $session->getSiteOrigin()]]);
        $message = (new ChatMessage())
            ->setSession($session)
            ->setMetaMessage($meta)
            ->setClientId($clientId)
            ->setDirection('visitor')
            ->setBody($body)
            ->setStatus('sent')
            ->setAuthorName($session->getVisitorName());
        $conversation->setLastMessageAt($now)->setLastInboundAt($now)->setUnreadCount($conversation->getUnreadCount() + 1);
        $session->seen();
        $this->em->persist($meta);
        $this->em->persist($message);
        $this->em->persist($conversation);
        $this->em->persist($session);
        $this->em->flush();
        $this->inbox->messagePersisted($meta);
        $state = $this->states->findOneBy(['conversation' => $conversation]);
        $this->safePublish($session, ['type' => 'message.created', 'message' => $this->messageData($message)]);

        if ($state instanceof ConversationState && null !== $session->getWidget()->getAiAgentKey()) {
            // The supervised Inbox AI worker processes assignments outside HTTP/FPM.
            $this->ai->assignSystem($state, $session->getWidget()->getAiAgentKey());
        }

        return $message;
    }

    public function sendHuman(ConversationState $state, OutboundRequest $request): ChatMessage
    {
        $session = $this->sessionFor($state->getConversation());
        $message = (new ChatMessage())
            ->setSession($session)
            ->setOutboundRequest($request)
            ->setClientId('agent-'.$request->getRequestId())
            ->setDirection('agent')
            ->setBody($request->getBody())
            ->setStatus('sent')
            ->setAuthorName($request->getAuthor()->getName());
        $request->setStatus('sent');
        $session->getConversation()->setLastMessageAt(new \DateTimeImmutable());
        $this->em->persist($message);
        $this->em->persist($request);
        $this->em->persist($session->getConversation());
        $this->em->flush();
        $this->safePublish($session, ['type' => 'message.created', 'message' => $this->messageData($message)]);
        return $message;
    }

    /** @param array<string,mixed> $metadata */
    public function sendAi(ConversationState $state, string $body, array $metadata): ChatMessage
    {
        $session = $this->sessionFor($state->getConversation());
        $clientId = 'ai-'.substr(hash('sha256', (string) ($metadata['run_key'] ?? random_bytes(16))), 0, 40);
        $existing = $this->messages->findOneBy(['clientId' => $clientId]);
        if ($existing instanceof ChatMessage) {
            return $existing;
        }
        $conversation = $session->getConversation();
        $payload = ['text' => $body, '_origin' => 'inbox_ai', '_ai_agent_key' => $metadata['agent_key'] ?? '', '_ai_agent_name' => $metadata['agent_name'] ?? 'AI'];
        $meta = (new MetaMessage())
            ->setAsset($conversation->getAsset())->setConversation($conversation)->setContact($session->getContact())
            ->setExternalId('webchat-'.$clientId)->setChannel('webchat')->setDirection('outbound')->setMessageType('text')
            ->setRecipient($conversation->getRecipient())->setStatus('sent')->setPayload($payload);
        $message = (new ChatMessage())
            ->setSession($session)->setMetaMessage($meta)->setClientId($clientId)->setDirection('ai')
            ->setBody($body)->setStatus('sent')->setAuthorName((string) ($metadata['agent_name'] ?? 'AI'));
        $conversation->setLastMessageAt(new \DateTimeImmutable());
        $this->em->persist($meta);
        $this->em->persist($message);
        $this->em->persist($conversation);
        $this->em->flush();
        $this->safePublish($session, ['type' => 'message.created', 'message' => $this->messageData($message)]);
        return $message;
    }

    public function setTyping(ConversationState $state, bool $active, string $name): void
    {
        $this->safePublish($this->sessionFor($state->getConversation()), [
            'type' => $active ? 'typing.started' : 'typing.stopped',
            'role' => 'agent',
            'name' => $name,
        ]);
    }

    public function receipt(ChatSession $session, string $role, string $kind, int $messageId): void
    {
        if (!in_array($role, ['visitor', 'agent'], true) || !in_array($kind, ['delivered', 'read'], true)) {
            throw new \DomainException('Confirmação inválida.');
        }
        $target = $this->messages->find($messageId);
        if (!$target instanceof ChatMessage || $target->getSession()->getId() !== $session->getId()) {
            throw new \DomainException('Mensagem não encontrada.');
        }
        $lastRead = 'visitor' === $role ? $session->getVisitorLastReadMessageId() : $session->getAgentLastReadMessageId();
        if (!ReceiptPolicy::shouldApply($role, $kind, $target->getDirection(), $target->getStatus(), $messageId, (int) $lastRead)) {
            return;
        }
        $this->em->getConnection()->transactional(function () use ($session, $role, $kind, $messageId, $lastRead): void {
            if ('visitor' === $role) {
                $this->messages->advanceReceipts($session, $kind, (int) $lastRead, $messageId);
                if ('read' === $kind) $session->setVisitorLastReadMessageId($messageId);
            } else {
                $this->messages->markVisitorRead($session, (int) $lastRead, $messageId);
                $session->setAgentLastReadMessageId($messageId);
                $session->getConversation()->setUnreadCount(0);
                $this->em->persist($session->getConversation());
            }
            $session->seen();
            $this->em->persist($session);
            $this->em->flush();
        });
        $this->safePublish($session, ['type' => 'message.'.$kind, 'message_id' => $messageId, 'role' => $role, 'at' => gmdate(DATE_ATOM)]);
    }

    public function sessionFor(MetaConversation $conversation): ChatSession
    {
        $session = $this->sessions->findOneBy(['conversation' => $conversation]);
        if (!$session instanceof ChatSession) {
            throw new \DomainException('Sessão Web Chat não encontrada.');
        }
        return $session;
    }

    /** @return array<string,mixed> */
    public function messageData(ChatMessage $message): array
    {
        return [
            'id' => (int) $message->getId(),
            // These are the canonical timeline identifiers used by Support Inbox.
            // Carrying them over SSE lets the operator render the message in
            // the same frame without inventing a temporary key or duplicating it
            // when durable history is fetched immediately afterwards.
            'inbox_message_id' => $message->getMetaMessage()?->getId(),
            'outbound_request_id' => $message->getOutboundRequest()?->getId(),
            'client_id' => $message->getClientId(),
            'direction' => $message->getDirection(),
            'body' => $message->getBody(),
            'status' => $message->getStatus(),
            'author' => $message->getAuthorName(),
            'timestamp' => $message->getDateAdded()->format(DATE_ATOM),
        ];
    }

    /** @return array<string,mixed> */
    public function widgetData(ChatWidget $widget): array
    {
        return ['name' => $widget->getName(), 'greeting' => $widget->getGreeting(), 'offline_message' => $widget->getOfflineMessage(), 'accent_color' => $widget->getAccentColor(), 'require_name' => $widget->requiresName(), 'require_email' => $widget->requiresEmail()];
    }

    private function contact(string $name, string $email): ?Lead
    {
        if ('' === $email && '' === $name) {
            return null;
        }
        $matches = '' === $email ? [] : $this->leads->getRepository()->getLeadsByFieldValue('email', $email);
        $contact = 1 === count($matches) && $matches[0] instanceof Lead ? $matches[0] : $this->leads->getEntity();
        $parts = '' !== $name ? (preg_split('/\s+/', $name, 2) ?: []) : [];
        $fields = array_filter([
            'firstname' => $parts[0] ?? null,
            'lastname' => $parts[1] ?? null,
            'email' => '' !== $email ? $email : null,
        ], static fn (mixed $value): bool => null !== $value && '' !== $value);
        if ([] !== $fields) {
            $this->leads->setFieldValues($contact, $fields, true);
        }
        $this->leads->saveEntity($contact);
        return $contact;
    }

    private function body(string $body): string
    {
        $body = trim($body);
        if ('' === $body || mb_strlen($body) > 4000) {
            throw new \DomainException('Escreva uma mensagem de até 4.000 caracteres.');
        }
        return $body;
    }

    private function clientId(string $value): string
    {
        $value = trim($value);
        if (!preg_match('/^[A-Za-z0-9_-]{16,64}$/', $value)) {
            throw new \DomainException('Identificador de mensagem inválido.');
        }
        return $value;
    }

    private function url(mixed $value): ?string
    {
        $url = mb_substr(trim((string) $value), 0, 1000);
        return '' !== $url && preg_match('#^https?://#', $url) ? $url : null;
    }

    /** @return array<string,string> */
    private function utm(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $result = [];
        foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'] as $key) {
            $item = mb_substr(trim((string) ($value[$key] ?? '')), 0, 255);
            if ('' !== $item) {
                $result[$key] = $item;
            }
        }
        return $result;
    }

    /** @param array<string,mixed> $event */
    private function safePublish(ChatSession $session, array $event): void
    {
        try {
            $this->gateway->publish($session->getPublicId(), $event);
        } catch (\Throwable) {
            // The durable message remains available through history recovery.
        }
    }
}

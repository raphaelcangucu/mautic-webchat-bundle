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
use MauticPlugin\MauticMetaBundle\Application\WhatsApp\PhoneNormalizer;
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
        private \MauticPlugin\MauticWebChatBundle\Security\IdentityVerifier $identities,
        private PhoneNormalizer $phones = new PhoneNormalizer(),
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
        $identityToken = trim((string) ($input['identity_token'] ?? ''));
        $verified = '' !== $identityToken ? $this->identities->verify($identityToken, $widget->getPublicKey(), $origin) : null;
        $locale = in_array($input['locale'] ?? '', ['pt', 'en', 'es'], true) ? $input['locale'] : 'pt';
        if (null !== $verified) {
            foreach (['name', 'email', 'phone'] as $field) $input[$field] = $verified[$field] ?? '';
        } else {
            foreach (['name', 'email', 'phone'] as $field) if ('hidden' === $widget->fieldPolicy($field)) $input[$field] = '';
        }
        $name = mb_substr(trim((string) ($input['name'] ?? '')), 0, 120);
        $email = strtolower(mb_substr(trim((string) ($input['email'] ?? '')), 0, 190));
        $phone = trim((string) ($input['phone'] ?? ''));

        $plainToken = trim((string) ($input['resume_token'] ?? ''));
        $publicId = trim((string) ($input['resume_session'] ?? ''));
        $session = '' !== $publicId ? $this->sessions->findOneBy(['publicId' => $publicId, 'widget' => $widget]) : null;
        if (!$session instanceof ChatSession || !$session->tokenMatches($plainToken) || 'open' !== $session->getStatus() || $session->getSiteOrigin() !== $origin || ($session->getContext()['subject'] ?? null) !== ($verified['sub'] ?? null)) {
            $session = null;
            $plainToken = bin2hex(random_bytes(32));
        }
        // An authenticated existing session survives later changes to required fields.
        if ($session instanceof ChatSession) {
            $name = '' !== $name ? $name : ($session->getVisitorName() ?? '');
            $email = '' !== $email ? $email : ($session->getVisitorEmail() ?? '');
            $phone = '' !== $phone ? $phone : ($session->getVisitorPhone() ?? '');
        } elseif (null === $verified) {
            if ('required' === $widget->fieldPolicy('name') && '' === $name) {
                throw new \DomainException('name_required');
            }
            if ('required' === $widget->fieldPolicy('email') && '' === $email) {
                throw new \DomainException('email_invalid');
            }
            if ('required' === $widget->fieldPolicy('phone') && '' === $phone) {
                throw new \DomainException('phone_invalid');
            }
        }
        if ('' !== $email && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \DomainException('email_invalid');
        }
        if ('' !== $phone) {
            if (strlen($phone) > 50) {
                throw new \DomainException('phone_invalid');
            }
            try {
                $phone = '+'.$this->phones->normalize($phone, (string) ($widget->getAsset()->getSettings()['default_region'] ?? 'BR'));
            } catch (\InvalidArgumentException) {
                if (null === $verified) throw new \DomainException('phone_invalid');
                $phone = ''; // An account without a usable phone still has a signed stable ID.
            }
        }
        $contact = $session instanceof ChatSession && $session->getVisitorName() === ('' === $name ? null : $name) && $session->getVisitorEmail() === ('' === $email ? null : $email) && $session->getVisitorPhone() === ('' === $phone ? null : $phone)
            ? $session->getContact() : $this->contact($name, $email, $phone, $verified['sub'] ?? null, $locale);
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
        if (null !== $verified && $session->getId() && $contact instanceof Lead && ($session->getContext()['locale'] ?? null) !== $locale) {
            $this->leads->setFieldValues($contact, ['preferred_locale' => Presentation::contactLocale($locale)], true);
            $this->leads->saveEntity($contact);
        }
        $page = PageContext::sanitize($input, $origin);
        $session->setContext(array_replace($session->getContext(), ['locale' => $locale, 'subject' => $verified['sub'] ?? null], array_intersect_key($page, ['page_title' => true])));
        if (isset($page['page_url'])) {
            $session->setPageUrl($page['page_url']);
        }
        $session->setVisitorName('' === $name ? null : $name)
            ->setVisitorEmail('' === $email ? null : $email)
            ->setVisitorPhone('' === $phone ? null : $phone)
            ->setReferrer($this->url($input['referrer'] ?? null))
            ->setUtm(array_replace($session->getUtm(), $this->utm($input['utm'] ?? [])))
            ->seen();
        $this->em->persist($session);
        $this->em->persist($session->getConversation());
        $this->em->flush();

        return [
            'identity_verified' => null !== $verified,
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

    public function receiveVisitor(ChatSession $session, string $body, string $clientId, array $pageInput = []): ChatMessage
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

        $page = PageContext::sanitize($pageInput, $session->getSiteOrigin());
        if (isset($page['page_url'])) {
            $session->setPageUrl($page['page_url']);
            // Clear a previous page's title when the caller has no title yet.
            $session->setContext(array_replace($session->getContext(), ['page_title' => $page['page_title'] ?? '']));
        }
        $session->setContext(array_replace($session->getContext(), array_intersect_key($page, ['locale' => true])));
        $conversation = $session->getConversation();
        $now = new \DateTimeImmutable();
        $profile = array_filter(['name' => $session->getVisitorName(), 'email' => $session->getVisitorEmail(), 'phone' => $session->getVisitorPhone()]);
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
            ->setPayload(['text' => $body, 'contact' => ['profile' => $profile], 'webchat' => ['page_url' => $session->getPageUrl(), 'page_title' => $session->getContext()['page_title'] ?? '', 'origin' => $session->getSiteOrigin(), 'locale' => $session->getContext()['locale'] ?? 'pt', 'external_id' => $session->getContext()['subject'] ?? null]]);
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
            'expires_in' => $active ? 120 : 0,
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
            } else {
                $this->messages->markVisitorRead($session, (int) $lastRead, $messageId);
                $session->getConversation()->setUnreadCount(0);
                $this->em->persist($session->getConversation());
            }
            $this->messages->touchReceiptSession($session, $role, $kind, $messageId);
            $this->em->refresh($session);
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
        return ['name' => $widget->getName(), 'greeting' => $widget->getGreeting(), 'offline_message' => $widget->getOfflineMessage(), 'presentation' => $widget->getPresentation(), 'accent_color' => $widget->getAccentColor(), 'require_name' => 'required' === $widget->fieldPolicy('name'), 'require_email' => 'required' === $widget->fieldPolicy('email'), 'require_phone' => 'required' === $widget->fieldPolicy('phone')];
    }

    private function contact(string $name, string $email, string $phone, ?string $subject, string $locale): ?Lead
    {
        if (null === $subject && '' === $email && '' === $name && '' === $phone) {
            return null;
        }
        // Mautic indexes repository results by contact ID, not by position.
        $bySubject = null !== $subject ? array_values($this->leads->getRepository()->getLeadsByFieldValue('cms_external_id', $subject)) : [];
        if (count($bySubject) > 1) throw new \DomainException('identity_invalid');
        $matches = [] !== $bySubject ? $bySubject : ('' === $email ? [] : array_values($this->leads->getRepository()->getLeadsByFieldValue('email', $email)));
        if (null !== $subject && count($matches) > 1) throw new \DomainException('identity_invalid');
        $contact = 1 === count($matches) && $matches[0] instanceof Lead ? $matches[0] : $this->leads->getEntity();
        if (null !== $subject && $contact instanceof Lead) {
            $existingSubject = $contact->getFieldValue('cms_external_id');
            if ($existingSubject && $existingSubject !== $subject) throw new \DomainException('identity_invalid');
        }
        $parts = '' !== $name ? (preg_split('/\s+/', $name, 2) ?: []) : [];
        $fields = array_filter([
            'cms_external_id' => $subject,
            'preferred_locale' => null !== $subject ? Presentation::contactLocale($locale) : null,
            'firstname' => $parts[0] ?? null,
            'lastname' => $parts[1] ?? null,
            'email' => '' !== $email ? $email : null,
            'mobile' => '' !== $phone ? $phone : null,
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
        if ('' === $url || !filter_var($url, FILTER_VALIDATE_URL) || !in_array(parse_url($url, PHP_URL_SCHEME), ['http','https'], true)) return null;
        $parts = parse_url($url);
        return $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '').($parts['path'] ?? '/');
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

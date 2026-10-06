<?php

declare(strict_types=1);

namespace MauticPlugin\MauticWebChatBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Mautic\CoreBundle\Doctrine\Mapping\ClassMetadataBuilder;
use Mautic\CoreBundle\Entity\CommonEntity;
use Mautic\LeadBundle\Entity\Lead;
use MauticPlugin\MauticMetaBundle\Entity\MetaConversation;

class ChatSession extends CommonEntity
{
    private $id;
    private ?array $context = null;
    private ChatWidget $widget;
    private MetaConversation $conversation;
    private ?Lead $contact = null;
    private string $publicId = '';
    private string $tokenHash = '';
    private string $visitorId = '';
    private ?string $visitorName = null;
    private ?string $visitorEmail = null;
    private ?string $visitorPhone = null;
    private string $siteOrigin = '';
    private ?string $pageUrl = null;
    private ?string $referrer = null;
    /** @var array<string,string> */
    private array $utm = [];
    private string $status = 'open';
    private ?int $visitorLastReadMessageId = null;
    private ?int $agentLastReadMessageId = null;
    private \DateTimeInterface $dateAdded;
    private \DateTimeInterface $dateModified;
    private \DateTimeInterface $lastSeenAt;

    public function __construct()
    {
        $this->publicId = bin2hex(random_bytes(16));
        $this->dateAdded = $this->dateModified = $this->lastSeenAt = new \DateTimeImmutable();
    }

    public static function loadMetadata(ORM\ClassMetadata $metadata): void
    {
        $b = new ClassMetadataBuilder($metadata);
        $b->setTable('webchat_sessions')->setCustomRepositoryClass(ChatSessionRepository::class)
            ->addUniqueConstraint(['public_id'], 'webchat_session_public_id')
            ->addUniqueConstraint(['conversation_id'], 'webchat_session_conversation')
            ->addIndex(['widget_id', 'visitor_id', 'status'], 'webchat_session_visitor')
            ->addIndex(['status', 'last_seen_at'], 'webchat_session_status');
        $b->addId();
        $b->addNullableField('context', Types::JSON);
        $b->createManyToOne('widget', ChatWidget::class)->addJoinColumn('widget_id', 'id', false, false, 'CASCADE')->build();
        $b->createManyToOne('conversation', MetaConversation::class)->addJoinColumn('conversation_id', 'id', false, false, 'CASCADE')->build();
        $b->createManyToOne('contact', Lead::class)->addJoinColumn('contact_id', 'id', true, false, 'SET NULL')->build();
        $b->addField('publicId', Types::STRING, ['columnName' => 'public_id', 'length' => 32]);
        $b->addField('tokenHash', Types::STRING, ['columnName' => 'token_hash', 'length' => 64]);
        $b->addField('visitorId', Types::STRING, ['columnName' => 'visitor_id', 'length' => 64]);
        $b->addNullableField('visitorName', Types::STRING, 'visitor_name');
        $b->addNullableField('visitorEmail', Types::STRING, 'visitor_email');
        $b->createField('visitorPhone', Types::STRING)->columnName('visitor_phone')->length(32)->nullable()->build();
        $b->addField('siteOrigin', Types::STRING, ['columnName' => 'site_origin', 'length' => 255]);
        $b->addNullableField('pageUrl', Types::STRING, 'page_url');
        $b->addNullableField('referrer', Types::STRING);
        $b->addField('utm', Types::JSON);
        $b->addField('status', Types::STRING, ['length' => 24]);
        $b->addNullableField('visitorLastReadMessageId', Types::INTEGER, 'visitor_last_read_message_id');
        $b->addNullableField('agentLastReadMessageId', Types::INTEGER, 'agent_last_read_message_id');
        $b->addField('dateAdded', Types::DATETIME_IMMUTABLE, ['columnName' => 'date_added']);
        $b->addField('dateModified', Types::DATETIME_IMMUTABLE, ['columnName' => 'date_modified']);
        $b->addField('lastSeenAt', Types::DATETIME_IMMUTABLE, ['columnName' => 'last_seen_at']);
    }

    public function getContext(): array { return $this->context ?? []; }
    public function setContext(array $v): self { $this->context = $v; return $this->touch(); }
    public function getId(): ?int { return $this->id; }
    public function getWidget(): ChatWidget { return $this->widget; }
    public function setWidget(ChatWidget $v): self { $this->widget = $v; return $this; }
    public function getConversation(): MetaConversation { return $this->conversation; }
    public function setConversation(MetaConversation $v): self { $this->conversation = $v; return $this; }
    public function getContact(): ?Lead { return $this->contact; }
    public function setContact(?Lead $v): self { $this->contact = $v; return $this; }
    public function getPublicId(): string { return $this->publicId; }
    public function getTokenHash(): string { return $this->tokenHash; }
    public function setToken(string $plain): self { $this->tokenHash = hash('sha256', $plain); return $this; }
    public function tokenMatches(string $plain): bool { return '' !== $plain && hash_equals($this->tokenHash, hash('sha256', $plain)); }
    public function getVisitorId(): string { return $this->visitorId; }
    public function setVisitorId(string $v): self { $this->visitorId = trim($v); return $this; }
    public function getVisitorName(): ?string { return $this->visitorName; }
    public function setVisitorName(?string $v): self { $this->visitorName = '' === trim((string) $v) ? null : trim((string) $v); return $this->touch(); }
    public function getVisitorEmail(): ?string { return $this->visitorEmail; }
    public function setVisitorEmail(?string $v): self { $this->visitorEmail = '' === trim((string) $v) ? null : strtolower(trim((string) $v)); return $this->touch(); }
    public function getVisitorPhone(): ?string { return $this->visitorPhone; }
    public function setVisitorPhone(?string $v): self { $this->visitorPhone = '' === trim((string) $v) ? null : trim((string) $v); return $this->touch(); }
    public function getSiteOrigin(): string { return $this->siteOrigin; }
    public function setSiteOrigin(string $v): self { $this->siteOrigin = trim($v); return $this; }
    public function getPageUrl(): ?string { return $this->pageUrl; }
    public function setPageUrl(?string $v): self { $this->pageUrl = $v; return $this->touch(); }
    public function getReferrer(): ?string { return $this->referrer; }
    public function setReferrer(?string $v): self { $this->referrer = $v; return $this; }
    /** @return array<string,string> */
    public function getUtm(): array { return $this->utm; }
    /** @param array<string,string> $v */
    public function setUtm(array $v): self { $this->utm = $v; return $this; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $v): self { $this->status = $v; return $this->touch(); }
    public function getVisitorLastReadMessageId(): ?int { return $this->visitorLastReadMessageId; }
    public function setVisitorLastReadMessageId(?int $v): self { $this->visitorLastReadMessageId = $v; return $this->touch(); }
    public function getAgentLastReadMessageId(): ?int { return $this->agentLastReadMessageId; }
    public function setAgentLastReadMessageId(?int $v): self { $this->agentLastReadMessageId = $v; return $this->touch(); }
    public function getDateAdded(): \DateTimeInterface { return $this->dateAdded; }
    public function getDateModified(): \DateTimeInterface { return $this->dateModified; }
    public function getLastSeenAt(): \DateTimeInterface { return $this->lastSeenAt; }
    public function seen(): self { $this->lastSeenAt = new \DateTimeImmutable(); return $this->touch(); }
    private function touch(): self { $this->dateModified = new \DateTimeImmutable(); return $this; }
}

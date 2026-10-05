<?php

declare(strict_types=1);

namespace MauticPlugin\MauticWebChatBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Mautic\CoreBundle\Doctrine\Mapping\ClassMetadataBuilder;
use Mautic\CoreBundle\Entity\CommonEntity;
use MauticPlugin\MauticInboxBundle\Entity\OutboundRequest;
use MauticPlugin\MauticMetaBundle\Entity\MetaMessage;

class ChatMessage extends CommonEntity
{
    private $id;
    private ChatSession $session;
    private ?OutboundRequest $outboundRequest = null;
    private ?MetaMessage $metaMessage = null;
    private string $clientId = '';
    private string $direction = 'visitor';
    private string $body = '';
    private string $status = 'sent';
    private ?string $authorName = null;
    private \DateTimeInterface $dateAdded;
    private \DateTimeInterface $dateModified;

    public function __construct()
    {
        $this->dateAdded = $this->dateModified = new \DateTimeImmutable();
    }

    public static function loadMetadata(ORM\ClassMetadata $metadata): void
    {
        $b = new ClassMetadataBuilder($metadata);
        $b->setTable('webchat_messages')->setCustomRepositoryClass(ChatMessageRepository::class)
            ->addUniqueConstraint(['client_id'], 'webchat_message_client_id')
            ->addUniqueConstraint(['outbound_request_id'], 'webchat_message_outbound')
            ->addUniqueConstraint(['meta_message_id'], 'webchat_message_meta')
            ->addIndex(['session_id', 'id'], 'webchat_message_timeline')
            ->addIndex(['session_id', 'direction', 'id'], 'webchat_message_direction');
        $b->addId();
        $b->createManyToOne('session', ChatSession::class)->addJoinColumn('session_id', 'id', false, false, 'CASCADE')->build();
        $b->createManyToOne('outboundRequest', OutboundRequest::class)->addJoinColumn('outbound_request_id', 'id', true, false, 'SET NULL')->build();
        $b->createManyToOne('metaMessage', MetaMessage::class)->addJoinColumn('meta_message_id', 'id', true, false, 'SET NULL')->build();
        $b->addField('clientId', Types::STRING, ['columnName' => 'client_id', 'length' => 64]);
        $b->addField('direction', Types::STRING, ['length' => 16]);
        $b->addField('body', Types::TEXT);
        $b->addField('status', Types::STRING, ['length' => 24]);
        $b->addNullableField('authorName', Types::STRING, 'author_name');
        $b->addField('dateAdded', Types::DATETIME_IMMUTABLE, ['columnName' => 'date_added']);
        $b->addField('dateModified', Types::DATETIME_IMMUTABLE, ['columnName' => 'date_modified']);
    }

    public function getId(): ?int { return $this->id; }
    public function getSession(): ChatSession { return $this->session; }
    public function setSession(ChatSession $v): self { $this->session = $v; return $this; }
    public function getOutboundRequest(): ?OutboundRequest { return $this->outboundRequest; }
    public function setOutboundRequest(?OutboundRequest $v): self { $this->outboundRequest = $v; return $this; }
    public function getMetaMessage(): ?MetaMessage { return $this->metaMessage; }
    public function setMetaMessage(?MetaMessage $v): self { $this->metaMessage = $v; return $this; }
    public function getClientId(): string { return $this->clientId; }
    public function setClientId(string $v): self { $this->clientId = trim($v); return $this; }
    public function getDirection(): string { return $this->direction; }
    public function setDirection(string $v): self { $this->direction = $v; return $this; }
    public function getBody(): string { return $this->body; }
    public function setBody(string $v): self { $this->body = trim($v); return $this; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $v): self { $this->status = $v; $this->dateModified = new \DateTimeImmutable(); return $this; }
    public function getAuthorName(): ?string { return $this->authorName; }
    public function setAuthorName(?string $v): self { $this->authorName = $v; return $this; }
    public function getDateAdded(): \DateTimeInterface { return $this->dateAdded; }
    public function getDateModified(): \DateTimeInterface { return $this->dateModified; }
}

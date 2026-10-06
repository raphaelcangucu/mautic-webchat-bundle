<?php

declare(strict_types=1);

namespace MauticPlugin\MauticWebChatBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Mautic\CoreBundle\Doctrine\Mapping\ClassMetadataBuilder;
use Mautic\CoreBundle\Entity\CommonEntity;
use MauticPlugin\MauticMetaBundle\Entity\MetaAsset;

class ChatWidget extends CommonEntity
{
    private $id;
    private ?array $presentation = null;
    private MetaAsset $asset;
    private string $name = '';
    private string $publicKey = '';
    private bool $published = true;
    /** @var list<string> */
    private array $allowedDomains = [];
    private string $greeting = 'Olá! Como podemos ajudar?';
    private string $offlineMessage = 'Deixe sua mensagem e responderemos assim que possível.';
    private string $accentColor = '#4e5ba6';
    private bool $requireName = true;
    private bool $requireEmail = false;
    private bool $requirePhone = false;
    private ?string $aiAgentKey = null;
    private \DateTimeInterface $dateAdded;
    private \DateTimeInterface $dateModified;

    public function __construct()
    {
        $this->dateAdded = $this->dateModified = new \DateTimeImmutable();
        $this->publicKey = 'pub_'.bin2hex(random_bytes(12));
    }

    public static function loadMetadata(ORM\ClassMetadata $metadata): void
    {
        $b = new ClassMetadataBuilder($metadata);
        $b->setTable('webchat_widgets')->setCustomRepositoryClass(ChatWidgetRepository::class)
            ->addUniqueConstraint(['public_key'], 'webchat_widget_public_key')
            ->addIndex(['published'], 'webchat_widget_published');
        $b->addId();
        $b->addNullableField('presentation', Types::JSON);
        $b->createManyToOne('asset', MetaAsset::class)->addJoinColumn('asset_id', 'id', false, false, 'CASCADE')->build();
        $b->addField('name', Types::STRING, ['length' => 120]);
        $b->addField('publicKey', Types::STRING, ['columnName' => 'public_key', 'length' => 64]);
        $b->addField('published', Types::BOOLEAN);
        $b->addField('allowedDomains', Types::JSON, ['columnName' => 'allowed_domains']);
        $b->addField('greeting', Types::STRING, ['length' => 500]);
        $b->addField('offlineMessage', Types::STRING, ['columnName' => 'offline_message', 'length' => 500]);
        $b->addField('accentColor', Types::STRING, ['columnName' => 'accent_color', 'length' => 16]);
        $b->addField('requireName', Types::BOOLEAN, ['columnName' => 'require_name']);
        $b->addField('requireEmail', Types::BOOLEAN, ['columnName' => 'require_email']);
        $b->addField('requirePhone', Types::BOOLEAN, ['columnName' => 'require_phone', 'options' => ['default' => false]]);
        $b->addNullableField('aiAgentKey', Types::STRING, 'ai_agent_key');
        $b->addField('dateAdded', Types::DATETIME_IMMUTABLE, ['columnName' => 'date_added']);
        $b->addField('dateModified', Types::DATETIME_IMMUTABLE, ['columnName' => 'date_modified']);
    }

    public function getPresentation(): array { return $this->presentation ?? []; }
    public function setPresentation(array $v): self { $this->presentation = $v; return $this; }
    public function fieldPolicy(string $field): string {
        $required = match ($field) { 'name' => $this->requiresName(), 'email' => $this->requiresEmail(), 'phone' => $this->requiresPhone(), default => false };
        return $this->getPresentation()['fields'][$field] ?? ($required ? 'required' : 'optional');
    }
    public function getId(): ?int { return $this->id; }
    public function getAsset(): MetaAsset { return $this->asset; }
    public function setAsset(MetaAsset $v): self { $this->asset = $v; return $this->touch(); }
    public function getName(): string { return $this->name; }
    public function setName(string $v): self { $this->name = trim($v); return $this->touch(); }
    public function getPublicKey(): string { return $this->publicKey; }
    public function isPublished(): bool { return $this->published; }
    public function setPublished(bool $v): self { $this->published = $v; return $this->touch(); }
    /** @return list<string> */
    public function getAllowedDomains(): array { return $this->allowedDomains; }
    /** @param list<string> $v */
    public function setAllowedDomains(array $v): self { $this->allowedDomains = array_values(array_unique($v)); return $this->touch(); }
    public function getGreeting(): string { return $this->greeting; }
    public function setGreeting(string $v): self { $this->greeting = trim($v); return $this->touch(); }
    public function getOfflineMessage(): string { return $this->offlineMessage; }
    public function setOfflineMessage(string $v): self { $this->offlineMessage = trim($v); return $this->touch(); }
    public function getAccentColor(): string { return $this->accentColor; }
    public function setAccentColor(string $v): self { $this->accentColor = strtolower($v); return $this->touch(); }
    public function requiresName(): bool { return $this->requireName; }
    public function setRequireName(bool $v): self { $this->requireName = $v; return $this->touch(); }
    public function requiresEmail(): bool { return $this->requireEmail; }
    public function setRequireEmail(bool $v): self { $this->requireEmail = $v; return $this->touch(); }
    public function requiresPhone(): bool { return $this->requirePhone; }
    public function setRequirePhone(bool $v): self { $this->requirePhone = $v; return $this->touch(); }
    public function getAiAgentKey(): ?string { return $this->aiAgentKey; }
    public function setAiAgentKey(?string $v): self { $this->aiAgentKey = '' === trim((string) $v) ? null : trim((string) $v); return $this->touch(); }
    public function getDateAdded(): \DateTimeInterface { return $this->dateAdded; }
    public function getDateModified(): \DateTimeInterface { return $this->dateModified; }
    private function touch(): self { $this->dateModified = new \DateTimeImmutable(); return $this; }
}

<?php
// Exercises the real open/identity/session code with in-memory collaborators.
// No Mautic kernel, ORM connection, fixtures or production database is loaded.
namespace Doctrine\ORM { interface EntityManagerInterface { public function persist(object $o): void; public function flush(): void; } }
namespace Mautic\CoreBundle\Entity { class CommonEntity {} }
namespace Mautic\LeadBundle\Entity {
    class Lead {
        public function __construct(public int $id, public array $fields = []) {}
        public function getId(): int { return $this->id; }
        public function getFieldValue(string $k): mixed { return $this->fields[$k] ?? null; }
    }
}
namespace Mautic\LeadBundle\Model {
    class LeadModel {
        public function __construct(public object $repo) {}
        public function getRepository(): object { return $this->repo; }
        public function getEntity(): \Mautic\LeadBundle\Entity\Lead { throw new \RuntimeException('Unexpected contact creation'); }
        public function setFieldValues($c, array $fields, bool $merge): void { $c->fields = array_replace($c->fields, $fields); }
        public function saveEntity($c): void {}
    }
}
namespace MauticPlugin\MauticMetaBundle\Entity {
    class MetaConversation {
        public mixed $contact = null;
        public string $recipient = '';
        public function setAsset($v): self { return $this; }
        public function setContact($v): self { $this->contact = $v; return $this; }
        public function setChannel($v): self { return $this; }
        public function setRecipient($v): self { $this->recipient = $v; return $this; }
        public function setStatus($v): self { return $this; }
    }
}
namespace MauticPlugin\MauticWebChatBundle\Security {
    class WidgetOrigin {
        public function assertAllowed($w, string $o): string {
            if (!in_array($o, ['https://macro.test', 'https://other.test'], true)) throw new \DomainException('origin_invalid');
            return $o;
        }
    }
}
namespace MauticPlugin\MauticWebChatBundle\Entity {
    class ChatWidget {
        public function isPublished(): bool { return true; }
        public function getPublicKey(): string { return 'pub_test'; }
        public function fieldPolicy($f): string { return 'hidden'; }
        public function getAsset(): object { return new \stdClass(); }
        public function getName(): string { return 'Test'; }
        public function getGreeting(): string { return ''; }
        public function getOfflineMessage(): string { return ''; }
        public function getPresentation(): array { return []; }
        public function getAccentColor(): string { return '#22c55e'; }
    }
    class ChatSessionRepository {
        public array $items = [];
        public function findOneBy(array $criteria): ?ChatSession {
            foreach ($this->items as $s) if ($s->getPublicId() === $criteria['publicId'] && $s->getWidget() === $criteria['widget']) return $s;
            return null;
        }
        public function latestForIdentity($w, $c, $o, $sub): ?ChatSession {
            foreach (array_reverse($this->items) as $s) if ($s->getWidget() === $w && $s->getContact() === $c && $s->getSiteOrigin() === $o && $s->getStatus() === 'open' && ($s->getContext()['subject'] ?? null) === $sub) return $s;
            return null;
        }
    }
    class ChatMessageRepository {
        public array $history = [];
        public function timeline(ChatSession $s): array { return $this->history[spl_object_id($s->getConversation())] ?? []; }
    }
}
namespace {
    require __DIR__.'/../../Entity/ChatSession.php';
    require __DIR__.'/../../Security/IdentityVerifier.php';
    require __DIR__.'/../../Security/RealtimeTokenSigner.php';
    require __DIR__.'/../../Application/Presentation.php';
    require __DIR__.'/../../Application/PageContext.php';
    require __DIR__.'/../../Application/ChatService.php';
    use MauticPlugin\MauticWebChatBundle\Entity\ChatSession;
    use MauticPlugin\MauticWebChatBundle\Entity\ChatWidget;
    use MauticPlugin\MauticWebChatBundle\Entity\ChatSessionRepository;
    use MauticPlugin\MauticWebChatBundle\Application\ChatService;
    use Mautic\LeadBundle\Entity\Lead;
    function check(bool $ok, string $label): void { if (!$ok) throw new \RuntimeException($label); }
    $key = openssl_pkey_new(['private_key_bits' => 2048]);
    $public = openssl_pkey_get_details($key)['key'];
    $encode = static fn ($v) => rtrim(strtr(base64_encode($v), '+/', '-_'), '=');
    $signed = static function(string $sub, string $origin = 'https://macro.test') use ($key, $encode): string {
        $head = $encode(json_encode(['typ' => 'JWT', 'alg' => 'RS256']));
        $body = $encode(json_encode(['iss' => 'https://cms.test', 'aud' => 'pub_test', 'origin' => $origin, 'sub' => $sub, 'name' => 'Test Account', 'email' => 'test@example.invalid', 'iat' => time(), 'exp' => time() + 300]));
        openssl_sign($head.'.'.$body, $sig, $key, OPENSSL_ALGO_SHA256);
        return $head.'.'.$body.'.'.$encode($sig);
    };
    $contacts = [42 => new Lead(42, ['cms_external_id' => 'macro:42']), 99 => new Lead(99, ['cms_external_id' => 'macro:99'])];
    $leads = new \Mautic\LeadBundle\Model\LeadModel(new class($contacts) {
        public function __construct(private array $contacts) {}
        public function getLeadsByFieldValue($f, $v): array { return array_filter($this->contacts, fn($c) => $c->getFieldValue($f) === $v); }
    });
    $repo = new ChatSessionRepository();
    $em = new class implements \Doctrine\ORM\EntityManagerInterface {
        public array $persisted = [];
        public function persist(object $o): void { $this->persisted[] = $o; }
        public function flush(): void {}
    };
    $ref = new \ReflectionClass(ChatService::class);
    $service = $ref->newInstanceWithoutConstructor();
    $messages = new \MauticPlugin\MauticWebChatBundle\Entity\ChatMessageRepository();
    foreach (['em' => $em, 'sessions' => $repo, 'messages' => $messages, 'leads' => $leads,
        'origins' => new \MauticPlugin\MauticWebChatBundle\Security\WidgetOrigin(),
        'identities' => new \MauticPlugin\MauticWebChatBundle\Security\IdentityVerifier($public, 'https://cms.test'),
        'tokens' => new \MauticPlugin\MauticWebChatBundle\Security\RealtimeTokenSigner(str_repeat('t', 40), 'https://chat.test/chat/realtime')] as $k => $v) $ref->getProperty($k)->setValue($service, $v);
    $widget = new ChatWidget();
    $visitor = str_repeat('a', 32);
    $session = (new ChatSession())->setWidget($widget)->setConversation(new \MauticPlugin\MauticMetaBundle\Entity\MetaConversation())->setVisitorId($visitor)->setSiteOrigin('https://macro.test')->setToken('anonymous-secret');
    (new \ReflectionProperty(ChatSession::class, 'id'))->setValue($session, 1);
    $repo->items[] = $session;
    $messages->history[spl_object_id($session->getConversation())] = [['body' => 'Visitor history']];
    $oldId = $session->getPublicId();
    $input = ['visitor_id' => $visitor, 'site_origin' => 'https://macro.test', 'resume_session' => $oldId, 'resume_token' => 'anonymous-secret', 'identity_token' => $signed('macro:42'), 'locale' => 'pt'];
    $r = $service->open($widget, $input);
    check($r['identity_verified'] && $r['messages'][0]['body'] === 'Visitor history', 'Visitor history survives verified login');
    check($session->getContact() === $contacts[42] && $session->getConversation()->contact === $contacts[42], 'Promoted session and conversation use signed contact');
    check($r['session'] !== $oldId && !$session->tokenMatches('anonymous-secret'), 'Anonymous durable credential and realtime topic are revoked');
    $firstToken = $r['session_token'];
    $input2 = ['visitor_id' => str_repeat('b', 32), 'site_origin' => 'https://macro.test', 'identity_token' => $signed('macro:42'), 'locale' => 'en'];
    $r2 = $service->open($widget, $input2);
    check($r2['session'] === $r['session'] && $r2['messages'] === $r['messages'], 'Another authenticated browser recovers the same conversation');
    check($session->tokenMatches($firstToken) && $session->tokenMatches($r2['session_token']), 'Both browsers remain authorized concurrently');
    check(!str_contains(json_encode($session->getContext()), $r2['session_token']), 'Additional browser credentials are hashed');
    check($contacts[42]->fields['preferred_locale'] === 'en_US', 'Returning browser locale updates the same contact');
    $signedResume = $input2 + ['resume_session' => $r2['session'], 'resume_token' => $r2['session_token']];
    $r3 = $service->open($widget, $signedResume);
    check($r3['session_token'] === $r2['session_token'], 'Same browser does not unnecessarily rotate its grant');
    $other = $service->open($widget, ['visitor_id' => str_repeat('c', 32), 'site_origin' => 'https://macro.test', 'identity_token' => $signed('macro:99'), 'resume_session' => $r['session'], 'resume_token' => $firstToken]);
    check($other['session'] !== $r['session'] && [] === $other['messages'], 'Different signed accounts never inherit prior history');
    $anonymous = $service->open($widget, ['visitor_id' => $visitor, 'site_origin' => 'https://macro.test', 'resume_session' => $r['session'], 'resume_token' => $firstToken]);
    check($anonymous['session'] !== $r['session'] && [] === $anonymous['messages'], 'Logout cannot restore signed history as an anonymous visitor');
    $otherOrigin = $service->open($widget, ['visitor_id' => $visitor, 'site_origin' => 'https://other.test', 'identity_token' => $signed('macro:42', 'https://other.test')]);
    check($otherOrigin['session'] !== $r['session'], 'Identity recovery remains origin scoped');
    $rejected = false;
    try { $service->open($widget, array_replace($input2, ['identity_token' => 'invalid'])); }
    catch (\DomainException $e) { $rejected = 'identity_invalid' === $e->getMessage(); }
    check($rejected, 'Unverified tokens never recover account history');
    $expired = $session->getContext();
    foreach ($expired['browser_grants'] as &$g) $g['expires'] = time() - 1;
    unset($g);
    $session->setContext($expired);
    check(!$session->tokenMatches($r2['session_token']) && $session->tokenMatches($firstToken), 'Expired device grant is rejected without revoking other browsers');
    $original = new Lead(1384, ['email' => 'test@example.invalid', 'partner' => 'trafegar', 'stage' => 7]);
    $duplicate = new Lead(3735, ['email' => 'test@example.invalid']);
    $duplicateLeads = new \Mautic\LeadBundle\Model\LeadModel(new class([3735 => $duplicate, 1384 => $original]) {
        public function __construct(private array $contacts) {}
        public function getLeadsByFieldValue($f, $v): array { return array_filter($this->contacts, fn($c) => $c->getFieldValue($f) === $v); }
    });
    $ref->getProperty('leads')->setValue($service, $duplicateLeads);
    $duplicateInput = ['visitor_id' => str_repeat('d', 32), 'site_origin' => 'https://macro.test', 'identity_token' => $signed('macro:5'), 'locale' => 'pt'];
    $opened = $service->open($widget, $duplicateInput);
    $persistedSessions = array_values(array_filter($em->persisted, fn($o) => $o instanceof ChatSession));
    $duplicateSession = end($persistedSessions);
    $repo->items[] = $duplicateSession;
    $messages->history[spl_object_id($duplicateSession->getConversation())] = [['body' => 'Account with duplicate contacts']];
    check($opened['identity_verified'] && $duplicateSession->getContact() === $original && $duplicateSession->getConversation()->contact === $original, 'A real signed open accepts duplicate emails and associates the first contact');
    check($original->fields['cms_external_id'] === 'macro:5' && $original->fields['partner'] === 'trafegar' && $original->fields['stage'] === 7 && $duplicate->fields === ['email' => 'test@example.invalid'], 'Signed duplicate resolution preserves original attribution and leaves the duplicate untouched');
    $reopened = $service->open($widget, array_replace($duplicateInput, ['visitor_id' => str_repeat('e', 32)]));
    check($reopened['session'] === $opened['session'] && $reopened['messages'][0]['body'] === 'Account with duplicate contacts', 'Subsequent signed browser recovers the same conversation after duplicate resolution');
    echo "account continuity checks passed (real service; no database)\n";
}

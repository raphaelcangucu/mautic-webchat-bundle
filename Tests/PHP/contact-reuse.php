<?php
// Pure regression checks. Stubs reproduce Mautic's ID-indexed repository result;
// no application bootstrap, ORM, test kernel or database connection is loaded.
namespace Mautic\LeadBundle\Entity {
    class Lead {
        public function __construct(public array $fields = []) {}
        public function getFieldValue(string $field): mixed { return $this->fields[$field] ?? null; }
    }
}
namespace Mautic\LeadBundle\Model {
    class LeadModel {
        public int $created = 0;
        public function __construct(private object $repository) {}
        public function getRepository(): object { return $this->repository; }
        public function getEntity(): \Mautic\LeadBundle\Entity\Lead { ++$this->created; return new \Mautic\LeadBundle\Entity\Lead(); }
        public function setFieldValues(\Mautic\LeadBundle\Entity\Lead $lead, array $fields, bool $merge): void { $lead->fields = array_replace($lead->fields, $fields); }
        public function saveEntity(\Mautic\LeadBundle\Entity\Lead $lead): void {}
    }
}
namespace {
    require __DIR__.'/../../Application/Presentation.php';
    require __DIR__.'/../../Application/ChatService.php';
    use Mautic\LeadBundle\Entity\Lead;
    use Mautic\LeadBundle\Model\LeadModel;
    use MauticPlugin\MauticWebChatBundle\Application\ChatService;
    function check(bool $ok, string $label): void { if (!$ok) throw new \RuntimeException($label); }
    function service(array $subjects, array $emails): array {
        $repository = new class($subjects, $emails) {
            public function __construct(private array $subjects, private array $emails) {}
            public function getLeadsByFieldValue(string $field, string $value): array { return 'cms_external_id' === $field ? $this->subjects : $this->emails; }
        };
        $model = new LeadModel($repository);
        $reflection = new \ReflectionClass(ChatService::class);
        $service = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('leads')->setValue($service, $model);
        return [$service, $model];
    }
    $method = new \ReflectionMethod(ChatService::class, 'contact');
    $existing = new Lead(['cms_external_id' => 'macro:42']);
    [$service, $model] = service([3739 => $existing], []);
    $result = $method->invoke($service, 'Test Account', 'qa@example.invalid', '', 'macro:42', 'pt');
    check($result === $existing && 0 === $model->created, 'Signed account must reuse an ID-indexed contact');
    check('pt_BR' === $result->fields['preferred_locale'], 'Reused account locale is updated');
    $emailContact = new Lead();
    [$service, $model] = service([], [1375 => $emailContact]);
    check($method->invoke($service, 'Test Account', 'qa@example.invalid', '', 'macro:42', 'en') === $emailContact && 0 === $model->created, 'Verified email must reuse an ID-indexed contact');
    check('macro:42' === $emailContact->fields['cms_external_id'], 'Signed subject is assigned to the matched contact');
    [$service, $model] = service([], [1375 => new Lead()]);
    check($method->invoke($service, 'Visitor', 'qa@example.invalid', '', null, 'pt') instanceof Lead && 0 === $model->created, 'Visitor email match also reuses the contact');
    foreach ([[3738 => new Lead(), 3739 => new Lead()], []] as $subjects) {
        [$service, $model] = service($subjects, [1375 => new Lead(), 1376 => new Lead()]);
        try { $method->invoke($service, 'Test Account', 'qa@example.invalid', '', 'macro:42', 'pt'); throw new \RuntimeException('Ambiguous identity accepted'); }
        catch (\DomainException $e) { check('identity_invalid' === $e->getMessage() && 0 === $model->created, 'Ambiguous signed identities remain rejected'); }
    }
    [$service, $model] = service([], [1375 => new Lead(['cms_external_id' => 'macro:other'])]);
    try { $method->invoke($service, 'Test Account', 'qa@example.invalid', '', 'macro:42', 'pt'); throw new \RuntimeException('Conflicting identity accepted'); }
    catch (\DomainException $e) { check('identity_invalid' === $e->getMessage(), 'Existing conflicting subject remains protected'); }
    echo "contact reuse regression checks passed (no database)\n";
}

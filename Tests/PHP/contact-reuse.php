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
    $original = new Lead(['partner' => 'trafegar', 'stage' => 7, 'tags' => ['dep-d1', 'dep-d2']]);
    $duplicate = new Lead();
    [$service, $model] = service([], [3735 => $duplicate, 1384 => $original]);
    check($method->invoke($service, 'Test Account', 'qa@example.invalid', '', 'macro:5', 'pt') === $original && 0 === $model->created, 'Duplicate verified email selects the lowest eligible contact ID despite reversed order');
    check('macro:5' === $original->fields['cms_external_id'] && 'trafegar' === $original->fields['partner'] && 7 === $original->fields['stage'] && ['dep-d1', 'dep-d2'] === $original->fields['tags'], 'Account binding preserves original attribution and conversion fields');
    check([] === $duplicate->fields, 'Duplicate contact remains untouched');
    $bound = new Lead(['cms_external_id' => 'macro:42']);
    $boundDuplicate = new Lead(['cms_external_id' => 'macro:42']);
    $olderEmail = new Lead();
    [$service, $model] = service([3739 => $boundDuplicate, 3738 => $bound], [1375 => $olderEmail]);
    check($method->invoke($service, 'Test Account', 'qa@example.invalid', '', 'macro:42', 'pt') === $bound && 0 === $model->created, 'Existing account binding takes priority and duplicate subjects use the lowest ID');
    check([] === $olderEmail->fields && ['cms_external_id' => 'macro:42'] === $boundDuplicate->fields, 'Other matching contacts are not changed');
    $conflicting = new Lead(['cms_external_id' => 'macro:other']);
    $eligible = new Lead();
    [$service, $model] = service([], [1376 => $eligible, 1375 => $conflicting]);
    check($method->invoke($service, 'Test Account', 'qa@example.invalid', '', 'macro:42', 'pt') === $eligible && 0 === $model->created, 'Skip contacts belonging to another signed account');
    [$service, $model] = service([], [1375 => $conflicting]);
    $fresh = $method->invoke($service, 'Test Account', 'qa@example.invalid', '', 'macro:42', 'pt');
    check($fresh !== $conflicting && 1 === $model->created && 'macro:42' === $fresh->fields['cms_external_id'], 'Valid accounts can chat even when all email matches belong to another account');
    check(['cms_external_id' => 'macro:other'] === $conflicting->fields, 'Existing conflicting subject remains untouched');
    $visitorFirst = new Lead();
    $visitorDuplicate = new Lead();
    [$service, $model] = service([], [1376 => $visitorDuplicate, 1375 => $visitorFirst]);
    check($method->invoke($service, 'Visitor', 'qa@example.invalid', '', null, 'pt') === $visitorFirst && 0 === $model->created, 'Visitor duplicate email also reuses the first contact without creating more duplicates');
    echo "contact reuse regression checks passed (no database)\n";
}

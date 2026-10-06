<?php

declare(strict_types=1);

namespace MauticPlugin\MauticWebChatBundle\Entity;

use Mautic\CoreBundle\Entity\CommonRepository;

/** @extends CommonRepository<ChatSession> */
final class ChatSessionRepository extends CommonRepository
{
    public function latestForIdentity(ChatWidget $widget, \Mautic\LeadBundle\Entity\Lead $contact, string $origin, string $subject): ?ChatSession
    {
        // Contact ID alone is insufficient: anonymous sessions may share an email.
        foreach ($this->findBy(['widget' => $widget, 'contact' => $contact, 'siteOrigin' => $origin, 'status' => 'open'], ['lastSeenAt' => 'DESC', 'id' => 'DESC']) as $session) {
            if (($session->getContext()['subject'] ?? null) === $subject) return $session;
        }
        return null;
    }

    public function forConversations(array $ids): array
    {
        if ([] === $ids) return [];
        return $this->createQueryBuilder('s')->addSelect('w', 'contact')->join('s.widget', 'w')->leftJoin('s.contact', 'contact')
            ->where('IDENTITY(s.conversation) IN (:ids)')->setParameter('ids', $ids)->getQuery()->getResult();
    }
}

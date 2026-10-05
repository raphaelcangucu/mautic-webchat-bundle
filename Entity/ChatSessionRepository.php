<?php

declare(strict_types=1);

namespace MauticPlugin\MauticWebChatBundle\Entity;

use Mautic\CoreBundle\Entity\CommonRepository;

/** @extends CommonRepository<ChatSession> */
final class ChatSessionRepository extends CommonRepository
{
    public function forConversations(array $ids): array
    {
        if ([] === $ids) return [];
        return $this->createQueryBuilder('s')->addSelect('w', 'contact')->join('s.widget', 'w')->leftJoin('s.contact', 'contact')
            ->where('IDENTITY(s.conversation) IN (:ids)')->setParameter('ids', $ids)->getQuery()->getResult();
    }
}

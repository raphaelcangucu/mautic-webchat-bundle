<?php

declare(strict_types=1);

namespace MauticPlugin\MauticWebChatBundle\Entity;

use Mautic\CoreBundle\Entity\CommonRepository;
use MauticPlugin\MauticInboxBundle\Entity\OutboundRequest;
use MauticPlugin\MauticMetaBundle\Entity\MetaMessage;

/** @extends CommonRepository<ChatMessage> */
final class ChatMessageRepository extends CommonRepository
{
    /** Latest preview for all sessions on one Inbox page, without loading message relations. */
    public function previews(array $ids): array
    {
        if ([] === $ids) return [];
        return $this->createQueryBuilder('m')->select('IDENTITY(m.session) AS session_id', 'm.body')
            ->where('m.id IN (SELECT MAX(latest.id) FROM '.ChatMessage::class.' latest WHERE IDENTITY(latest.session) IN (:ids) GROUP BY latest.session)')
            ->setParameter('ids', $ids)->getQuery()->getArrayResult();
    }

    /** Scalar projection avoids hydrating relations for every history message. */
    public function timeline(ChatSession $session): array
    {
        $rows = $this->createQueryBuilder('m')->select('m.id', 'm.clientId AS client_id', 'm.direction', 'm.body', 'm.status', 'm.authorName AS author', 'm.dateAdded AS timestamp', 'IDENTITY(m.metaMessage) AS inbox_message_id', 'IDENTITY(m.outboundRequest) AS outbound_request_id')
            ->where('m.session = :session')->setParameter('session', $session)->orderBy('m.id', 'DESC')->setMaxResults(100)->getQuery()->getArrayResult();
        $agentRead = (int) $session->getAgentLastReadMessageId();
        return array_reverse(array_map(static function (array $row) use ($agentRead): array {
            $row['id'] = (int) $row['id'];
            if ('visitor' === $row['direction'] && $row['id'] <= $agentRead) $row['status'] = 'read';
            $row['timestamp'] = $row['timestamp']->format(DATE_ATOM);
            return $row;
        }, $rows));
    }

    public function markVisitorRead(ChatSession $session, int $after, int $through): void
    {
        $em = $this->getEntityManager();
        $db = $em->getConnection();
        $messages = $db->quoteIdentifier($em->getClassMetadata(ChatMessage::class)->getTableName());
        $db->executeStatement("UPDATE $messages SET status = 'read', date_modified = ? WHERE session_id = ? AND direction = 'visitor' AND id > ? AND id <= ? AND status <> 'read'", [(new \DateTimeImmutable())->format('Y-m-d H:i:s'), $session->getId(), $after, $through]);
    }

    /** Three bounded SQL updates, independent of the number of unread messages. */
    public function advanceReceipts(ChatSession $session, string $kind, int $after, int $through): void
    {
        $em = $this->getEntityManager();
        $db = $em->getConnection();
        $messages = $db->quoteIdentifier($em->getClassMetadata(ChatMessage::class)->getTableName());
        $outbound = $db->quoteIdentifier($em->getClassMetadata(OutboundRequest::class)->getTableName());
        $meta = $db->quoteIdentifier($em->getClassMetadata(MetaMessage::class)->getTableName());
        $statuses = 'read' === $kind ? "('sent','delivered')" : "('sent')";
        $where = "session_id = ? AND id > ? AND id <= ? AND direction IN ('agent','ai') AND status IN $statuses";
        $params = [$kind, (new \DateTimeImmutable())->format('Y-m-d H:i:s'), $session->getId(), $after, $through];
        $db->executeStatement("UPDATE $outbound SET status = ?, date_modified = ? WHERE id IN (SELECT outbound_request_id FROM $messages WHERE $where AND outbound_request_id IS NOT NULL)", $params);
        $db->executeStatement("UPDATE $meta SET status = ?, date_modified = ? WHERE id IN (SELECT meta_message_id FROM $messages WHERE $where AND meta_message_id IS NOT NULL)", $params);
        $db->executeStatement("UPDATE $messages SET status = ?, date_modified = ? WHERE $where", $params);
    }
}

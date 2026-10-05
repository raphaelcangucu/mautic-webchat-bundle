<?php

declare(strict_types=1);

namespace MauticPlugin\MauticWebChatBundle\Application;

/** Receipts only advance state; duplicate or echoed events never publish again. */
final class ReceiptPolicy
{
    public static function shouldApply(string $role, string $kind, string $direction, string $status, int $id, int $lastRead): bool
    {
        if ('visitor' === $role && in_array($direction, ['agent', 'ai'], true)) {
            return 'read' === $kind ? $id > $lastRead : 'sent' === $status && $id > $lastRead;
        }
        return 'agent' === $role && 'visitor' === $direction && 'read' === $kind && $id > $lastRead;
    }
}

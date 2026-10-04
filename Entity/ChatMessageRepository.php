<?php

declare(strict_types=1);

namespace MauticPlugin\MauticWebChatBundle\Entity;

use Mautic\CoreBundle\Entity\CommonRepository;

/** @extends CommonRepository<ChatMessage> */
final class ChatMessageRepository extends CommonRepository
{
}

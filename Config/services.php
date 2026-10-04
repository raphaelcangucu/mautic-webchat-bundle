<?php

declare(strict_types=1);

use Doctrine\Bundle\DoctrineBundle\DependencyInjection\Compiler\ServiceRepositoryCompilerPass;
use Mautic\CoreBundle\DependencyInjection\MauticCoreExtension;
use MauticPlugin\MauticInboxBundle\Application\Ai\AiWorker;
use MauticPlugin\MauticWebChatBundle\Application\ChatService;
use MauticPlugin\MauticWebChatBundle\Integration\WebChatChannelTransport;
use MauticPlugin\MauticWebChatBundle\Realtime\GatewayClient;
use MauticPlugin\MauticWebChatBundle\Security\RealtimeTokenSigner;
use MauticPlugin\MauticWebChatBundle\Security\WidgetOrigin;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service_closure;

return function (ContainerConfigurator $configurator): void {
    $services = $configurator->services()->defaults()->autowire()->autoconfigure()->public();
    $excludes = MauticCoreExtension::DEFAULT_EXCLUDES;
    $services->load('MauticPlugin\\MauticWebChatBundle\\', '../')->exclude('../{'.implode(',', $excludes).'}');
    $services->load('MauticPlugin\\MauticWebChatBundle\\Entity\\', '../Entity/*Repository.php')
        ->tag(ServiceRepositoryCompilerPass::REPOSITORY_SERVICE_TAG);
    $services->get(WebChatChannelTransport::class)->tag('mautic.inbox.channel_transport');
    $services->set(RealtimeTokenSigner::class)->args([
        '%env(MAUTIC_WEBCHAT_REALTIME_SECRET)%',
        '%env(MAUTIC_WEBCHAT_REALTIME_URL)%',
    ]);
    $services->set(GatewayClient::class)->autowire()->arg('$internalUrlValue', '%env(MAUTIC_WEBCHAT_REALTIME_INTERNAL_URL)%');
    $services->get(ChatService::class)->arg('$aiWorker', service_closure(AiWorker::class));
    $services->set(WidgetOrigin::class);
};

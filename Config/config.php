<?php

declare(strict_types=1);

use MauticPlugin\MauticWebChatBundle\Controller\AdminController;
use MauticPlugin\MauticWebChatBundle\Controller\PublicController;
use MauticPlugin\MauticWebChatBundle\Controller\RealtimeController;

return [
    'name' => 'Mautic Realtime Web Chat',
    'description' => 'Widget de chat em tempo real conectado ao Inbox multicanal.',
    'version' => '1.0.0',
    'author' => 'Raphael Cangucu',
    'routes' => [
        'main' => [
            'mautic_webchat_index' => ['path' => '/webchat', 'controller' => AdminController::class.'::index', 'method' => 'GET'],
            'mautic_webchat_data' => ['path' => '/webchat/api/widgets', 'controller' => AdminController::class.'::data', 'method' => 'GET'],
            'mautic_webchat_save' => ['path' => '/webchat/api/widgets', 'controller' => AdminController::class.'::save', 'method' => 'POST'],
            'mautic_webchat_update' => ['path' => '/webchat/api/widgets/{widgetId}', 'controller' => AdminController::class.'::save', 'method' => 'PUT', 'requirements' => ['widgetId' => '\\d+']],
            'mautic_webchat_delete' => ['path' => '/webchat/api/widgets/{widgetId}', 'controller' => AdminController::class.'::delete', 'method' => 'DELETE', 'requirements' => ['widgetId' => '\\d+']],
            'mautic_webchat_health' => ['path' => '/webchat/api/realtime/health', 'controller' => AdminController::class.'::health', 'method' => 'GET'],
        ],
        'public' => [
            'mautic_webchat_embed' => ['path' => '/chat/embed.js', 'controller' => PublicController::class.'::loader', 'method' => 'GET', 'defaults' => ['_stateless' => true]],
            'mautic_webchat_loader' => ['path' => '/chat/generate.js', 'controller' => PublicController::class.'::loader', 'method' => 'GET', 'defaults' => ['_stateless' => true]],
            'mautic_webchat_widget' => ['path' => '/chat/widget/{publicKey}', 'controller' => PublicController::class.'::widget', 'method' => 'GET', 'defaults' => ['_stateless' => true], 'requirements' => ['publicKey' => 'pub_[a-zA-Z0-9_-]+']],
            'mautic_webchat_demo' => ['path' => '/chat/demo/{publicKey}', 'controller' => PublicController::class.'::demo', 'method' => 'GET', 'defaults' => ['_stateless' => true], 'requirements' => ['publicKey' => 'pub_[a-zA-Z0-9_-]+']],
            'mautic_webchat_session' => ['path' => '/chat/api/{publicKey}/sessions', 'controller' => PublicController::class.'::session', 'method' => 'POST', 'defaults' => ['_stateless' => true], 'requirements' => ['publicKey' => 'pub_[a-zA-Z0-9_-]+']],
            'mautic_webchat_history' => ['path' => '/chat/api/sessions/{publicId}/history', 'controller' => PublicController::class.'::history', 'method' => 'GET', 'defaults' => ['_stateless' => true], 'requirements' => ['publicId' => '[a-f0-9]{32}']],
            'mautic_webchat_message' => ['path' => '/chat/api/sessions/{publicId}/messages', 'controller' => PublicController::class.'::message', 'method' => 'POST', 'defaults' => ['_stateless' => true], 'requirements' => ['publicId' => '[a-f0-9]{32}']],
            'mautic_webchat_realtime_ingest' => ['path' => '/chat/api/realtime/ingest', 'controller' => RealtimeController::class.'::ingest', 'method' => 'POST', 'defaults' => ['_stateless' => true]],
        ],
    ],
    'menu' => [
        'main' => [
            'mautic.webchat.menu' => [
                'id' => 'mautic_webchat',
                'route' => 'mautic_webchat_index',
                'access' => 'inbox:conversations:view',
                'iconClass' => 'ri-message-3-line',
                'priority' => 20,
            ],
        ],
    ],
];

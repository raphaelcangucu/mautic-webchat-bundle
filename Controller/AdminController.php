<?php

declare(strict_types=1);

namespace MauticPlugin\MauticWebChatBundle\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\CoreBundle\Controller\CommonController;
use Mautic\CoreBundle\Helper\UserHelper;
use MauticPlugin\MauticInboxBundle\Application\Ai\AiStore;
use MauticPlugin\MauticMetaBundle\Entity\MetaAsset;
use MauticPlugin\MauticWebChatBundle\Entity\ChatSessionRepository;
use MauticPlugin\MauticWebChatBundle\Entity\ChatWidget;
use MauticPlugin\MauticWebChatBundle\Entity\ChatWidgetRepository;
use MauticPlugin\MauticWebChatBundle\Realtime\GatewayClient;
use MauticPlugin\MauticWebChatBundle\Security\RealtimeTokenSigner;
use MauticPlugin\MauticWebChatBundle\Security\WidgetOrigin;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class AdminController extends CommonController
{
    public function index(UserHelper $users): Response
    {
        $this->admin($users);
        return $this->delegateView([
            'contentTemplate' => '@MauticWebChat/Admin/index.html.twig',
            'passthroughVars' => ['mauticContent' => 'webchat', 'route' => $this->generateUrl('mautic_webchat_index')],
            'viewParameters' => ['assetVersion' => (string) (@filemtime(__DIR__.'/../Assets/dist/admin-app.js') ?: time())],
        ]);
    }

    public function data(UserHelper $users, ChatWidgetRepository $widgets, ChatSessionRepository $sessions, EntityManagerInterface $em, AiStore $ai, CsrfTokenManagerInterface $csrfTokens): JsonResponse
    {
        $this->admin($users);
        $items = array_map(function (ChatWidget $widget) use ($sessions): array {
            return $this->widget($widget) + ['sessions' => $sessions->count(['widget' => $widget])];
        }, $widgets->findBy([], ['dateModified' => 'DESC']));
        $assets = array_values(array_map(static fn (MetaAsset $asset): array => ['id' => (int) $asset->getId(), 'name' => $asset->getName(), 'type' => $asset->getType()->value], array_filter($em->getRepository(MetaAsset::class)->findBy(['isPublished' => true], ['name' => 'ASC']), static fn (MetaAsset $asset): bool => 'active' === $asset->getStatus())));
        $agents = array_map(static fn (array $agent): array => ['key' => $agent['key'], 'name' => $agent['name'], 'enabled' => !empty($agent['enabled'])], $ai->all('agent'));
        return new JsonResponse(['items' => $items, 'assets' => $assets, 'agents' => array_values($agents), 'csrf' => $csrfTokens->getToken('mautic_webchat')->getValue()]);
    }

    public function save(Request $request, UserHelper $users, ChatWidgetRepository $widgets, EntityManagerInterface $em, WidgetOrigin $origins, AiStore $ai, ?int $widgetId = null): JsonResponse
    {
        $this->admin($users);
        $this->csrf($request);
        try {
            $input = json_decode($request->getContent(), true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return new JsonResponse(['error' => 'Requisição inválida.'], 400);
        }
        $widget = null === $widgetId ? new ChatWidget() : $widgets->find($widgetId);
        if (!$widget instanceof ChatWidget) {
            return new JsonResponse(['error' => 'Widget não encontrado.'], 404);
        }
        $name = mb_substr(trim((string) ($input['name'] ?? '')), 0, 120);
        $asset = $em->find(MetaAsset::class, (int) ($input['asset_id'] ?? 0));
        $color = strtolower(trim((string) ($input['accent_color'] ?? '#4e5ba6')));
        $domains = [];
        foreach ((array) ($input['allowed_domains'] ?? []) as $domain) {
            if (null !== ($normalized = $origins->normalizeDomain((string) $domain))) {
                $domains[] = $normalized;
            }
        }
        $agentKey = trim((string) ($input['ai_agent_key'] ?? ''));
        if ('' === $name || !$asset instanceof MetaAsset || [] === $domains || !preg_match('/^#[0-9a-f]{6}$/', $color)) {
            return new JsonResponse(['error' => 'Informe nome, conta, cor e ao menos um domínio válido.'], 422);
        }
        if ('' !== $agentKey && !$ai->get('agent', $agentKey)) {
            return new JsonResponse(['error' => 'O agente selecionado não existe.'], 422);
        }
        $widget->setName($name)->setAsset($asset)->setPublished(!empty($input['published']))->setAllowedDomains($domains)
            ->setGreeting(mb_substr(trim((string) ($input['greeting'] ?? 'Olá! Como podemos ajudar?')), 0, 500))
            ->setOfflineMessage(mb_substr(trim((string) ($input['offline_message'] ?? 'Deixe sua mensagem e responderemos assim que possível.')), 0, 500))
            ->setAccentColor($color)->setRequireName(!empty($input['require_name']))->setRequireEmail(!empty($input['require_email']))
            ->setRequirePhone(array_key_exists('require_phone', $input) ? !empty($input['require_phone']) : $widget->requiresPhone())->setAiAgentKey($agentKey);
        $em->persist($widget);
        $em->flush();
        return new JsonResponse(['item' => $this->widget($widget)], null === $widgetId ? 201 : 200);
    }

    public function delete(int $widgetId, Request $request, UserHelper $users, ChatWidgetRepository $widgets, EntityManagerInterface $em): JsonResponse
    {
        $this->admin($users);
        $this->csrf($request);
        $widget = $widgets->find($widgetId);
        if (!$widget instanceof ChatWidget) {
            return new JsonResponse(['error' => 'Widget não encontrado.'], 404);
        }
        $widget->setPublished(false);
        $em->persist($widget);
        $em->flush();
        return new JsonResponse(['ok' => true]);
    }

    public function health(UserHelper $users, GatewayClient $gateway, RealtimeTokenSigner $tokens): JsonResponse
    {
        $this->admin($users);
        return new JsonResponse(['configured' => $tokens->isConfigured(), 'gateway' => $gateway->health()]);
    }

    /** @return array<string,mixed> */
    private function widget(ChatWidget $widget): array
    {
        $loader = $this->generateUrl('mautic_webchat_embed', ['id' => $widget->getPublicKey()], UrlGeneratorInterface::ABSOLUTE_URL);
        return [
            'id' => (int) $widget->getId(), 'name' => $widget->getName(), 'public_key' => $widget->getPublicKey(), 'published' => $widget->isPublished(),
            'allowed_domains' => $widget->getAllowedDomains(), 'greeting' => $widget->getGreeting(), 'offline_message' => $widget->getOfflineMessage(),
            'accent_color' => $widget->getAccentColor(), 'require_name' => $widget->requiresName(), 'require_email' => $widget->requiresEmail(), 'require_phone' => $widget->requiresPhone(),
            'ai_agent_key' => $widget->getAiAgentKey(), 'asset_id' => (int) $widget->getAsset()->getId(), 'asset_name' => $widget->getAsset()->getName(),
            'embed' => '<script async src="'.$loader.'"></script>',
            'demo_url' => $this->generateUrl('mautic_webchat_demo', ['publicKey' => $widget->getPublicKey()], UrlGeneratorInterface::ABSOLUTE_URL),
        ];
    }

    private function admin(UserHelper $users): void
    {
        if (!$users->getUser(true)?->isAdmin()) {
            throw $this->createAccessDeniedException();
        }
    }

    private function csrf(Request $request): void
    {
        if (!$this->isCsrfTokenValid('mautic_webchat', $request->headers->get('X-CSRF-Token', ''))) {
            throw $this->createAccessDeniedException();
        }
    }
}

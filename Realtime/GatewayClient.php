<?php

declare(strict_types=1);

namespace MauticPlugin\MauticWebChatBundle\Realtime;

use MauticPlugin\MauticWebChatBundle\Security\RealtimeTokenSigner;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class GatewayClient
{
    public function __construct(private HttpClientInterface $http, private RealtimeTokenSigner $tokens, private string $internalUrlValue)
    {
    }

    /** @param array<string,mixed> $event */
    public function publish(string $sessionId, array $event): void
    {
        $response = $this->http->request('POST', $this->internalUrl().'/publish', [
            'headers' => ['Authorization' => 'Bearer '.$this->tokens->gatewaySecret()],
            'json' => ['session' => $sessionId, 'event' => $event],
            'timeout' => 2.0,
        ]);
        if (202 !== $response->getStatusCode()) {
            throw new \RuntimeException('Realtime gateway rejected the event.');
        }
    }

    /** @return array<string,mixed> */
    public function health(): array
    {
        try {
            $response = $this->http->request('GET', $this->internalUrl().'/health', ['timeout' => 1.5]);
            return 200 === $response->getStatusCode() ? $response->toArray(false) : ['ok' => false];
        } catch (\Throwable $exception) {
            return ['ok' => false, 'error' => $exception->getMessage()];
        }
    }

    private function internalUrl(): string
    {
        $url = trim($this->internalUrlValue);
        return '' !== $url ? rtrim($url, '/') : 'http://127.0.0.1:8790';
    }
}

<?php

declare(strict_types = 1);

namespace Tests\Support;

use ContribMergeException;
use HttpResponse;
use HttpTransport;

/**
 * An HttpTransport that answers a queued response per request and records
 * everything that was sent, so a test can assert both the GitHub calls and --
 * by their order -- that the base is retargeted before the push.
 */
final class FakeHttpTransport implements HttpTransport
{
    /** @var list<array{method: string, url: string, body: string|null}> */
    public array $requests = [];

    /** @var list<array{method: string, urlContains: string, response: HttpResponse}> */
    private array $queued = [];

    /**
     * @param array<mixed, mixed> $body
     */
    public function queueJson(string $method, string $urlContains, int $status, array $body): void
    {
        $encoded = json_encode($body);
        $this->queued[] = [
            'method' => $method,
            'urlContains' => $urlContains,
            'response' => new HttpResponse($status, $encoded === false ? '{}' : $encoded),
        ];
    }

    public function queueRaw(string $method, string $urlContains, int $status, string $body): void
    {
        $this->queued[] = [
            'method' => $method,
            'urlContains' => $urlContains,
            'response' => new HttpResponse($status, $body),
        ];
    }

    public function send(string $method, string $url, array $headers, ?string $body = null): HttpResponse
    {
        $this->requests[] = ['method' => $method, 'url' => $url, 'body' => $body];

        // `/pulls/667` is a prefix of `/pulls/667/commits`, so the most
        // specific queued pattern wins rather than the first one queued.
        $bestIndex = null;
        $bestLength = -1;
        foreach ($this->queued as $index => $queued) {
            if ($queued['method'] !== $method || ! str_contains($url, $queued['urlContains'])) {
                continue;
            }
            $length = strlen($queued['urlContains']);
            if ($length > $bestLength) {
                $bestLength = $length;
                $bestIndex = $index;
            }
        }

        if ($bestIndex === null) {
            throw new ContribMergeException('Unexpected HTTP request: ' . $method . ' ' . $url);
        }

        $response = $this->queued[$bestIndex]['response'];
        array_splice($this->queued, $bestIndex, 1);

        return $response;
    }

    /**
     * @return list<array{method: string, path: string}>
     */
    public function mutations(): array
    {
        $mutations = [];
        foreach ($this->requests as $request) {
            if ($request['method'] === 'GET') {
                continue;
            }
            $mutations[] = [
                'method' => $request['method'],
                'path' => (string) preg_replace('#^https://api\.github\.com#', '', $request['url']),
            ];
        }

        return $mutations;
    }

    /**
     * The `base` value of every PATCH, in the order they were sent.
     *
     * @return string[]
     */
    public function patchedBases(): array
    {
        $bases = [];
        foreach ($this->requests as $request) {
            if ($request['method'] !== 'PATCH' || $request['body'] === null) {
                continue;
            }
            $decoded = json_decode($request['body'], true);
            if (is_array($decoded) && isset($decoded['base']) && is_string($decoded['base'])) {
                $bases[] = $decoded['base'];
            }
        }

        return $bases;
    }
}

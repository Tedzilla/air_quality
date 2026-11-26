<?php

declare(strict_types=1);

namespace AirQuality\Http;

use AirQuality\Ranking\RankingException;
use AirQuality\Ranking\RankingService;

final class Application
{
    public function __construct(private readonly RankingService $ranking)
    {
    }

    public function handle(string $method, string $path, array $query): void
    {
        $path = rtrim($path, '/') ?: '/';

        if ($method === 'GET' && $path === '/') {
            $this->renderHome();
            return;
        }

        if ($method === 'GET' && $path === '/api/status') {
            $this->json(200, $this->ranking->status());
            return;
        }

        if ($method === 'GET' && $path === '/api/ranking') {
            try {
                $this->json(200, $this->ranking->rank(
                    isset($query['from']) ? (string) $query['from'] : null,
                    isset($query['to']) ? (string) $query['to'] : null,
                ));
            } catch (RankingException $e) {
                $this->json(400, ['error' => $e->getMessage()]);
            }
            return;
        }

        $this->json(404, ['error' => 'Unknown endpoint. Use GET /api/ranking?from=YYYY-MM-DD&to=YYYY-MM-DD or GET /api/status.']);
    }

    private function renderHome(): void
    {
        $status = $this->ranking->status();
        $window = $status['data_window'];
        $defaultTo = $window['to'] ?? (new \DateTimeImmutable('today', new \DateTimeZone('UTC')))->format('Y-m-d');
        $defaultFrom = $window['from'] ?? (new \DateTimeImmutable('-6 months', new \DateTimeZone('UTC')))->format('Y-m-d');
        $min = $window['from'] ?? $defaultFrom;
        $max = $window['to'] ?? $defaultTo;

        header('Content-Type: text/html; charset=UTF-8');
        require dirname(__DIR__, 2) . '/templates/home.php';
    }

    private function json(int $status, array $payload): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }
}

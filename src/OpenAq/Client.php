<?php

declare(strict_types=1);

namespace AirQuality\OpenAq;

use AirQuality\Config;
use AirQuality\Fallback\HourlySource;

/**
 * Minimal OpenAQ v3 client. Free keys allow 60 requests/minute, so requests are spaced
 * out and a 429 response waits for the reset window before retrying.
 */
final class Client implements HourlySource
{
    private const MIN_INTERVAL_SECONDS = 1.1;
    private const PAGE_LIMIT = 1000;
    private const MAX_RETRIES = 3;

    private float $lastRequestAt = 0.0;

    public function __construct(private readonly Config $config)
    {
        if ($config->openAqApiKey === null) {
            throw new \LogicException('OpenAQ client needs OPENAQ_API_KEY');
        }
    }

    public function name(): string
    {
        return 'openaq';
    }

    public function sensorsNear(float $lat, float $lon, \DateTimeImmutable $activeSince): array
    {
        $data = $this->get('/locations', [
            'coordinates' => sprintf('%.5f,%.5f', $lat, $lon),
            'radius' => Config::OPENAQ_RADIUS_METERS,
            'parameters_id' => Config::OPENAQ_PM25_PARAMETER_ID,
            'limit' => 100,
        ]);

        $sensors = [];
        foreach ($data['results'] ?? [] as $location) {
            if (!empty($location['isMobile'])) {
                continue;
            }
            $last = $location['datetimeLast']['utc'] ?? null;
            if ($last === null || new \DateTimeImmutable($last) < $activeSince) {
                continue;
            }
            foreach ($location['sensors'] ?? [] as $sensor) {
                if (($sensor['parameter']['id'] ?? null) !== Config::OPENAQ_PM25_PARAMETER_ID) {
                    continue;
                }
                $sensors[] = [
                    'id' => (string) $sensor['id'],
                    'label' => trim(($location['name'] ?? '') . ' (' . ($location['provider']['name'] ?? 'OpenAQ') . ')'),
                ];
            }
        }

        return $sensors;
    }

    public function hourly(string $sensorId, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $rows = [];
        for ($page = 1; ; $page++) {
            $data = $this->get('/sensors/' . rawurlencode($sensorId) . '/hours', [
                'datetime_from' => $from->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
                'datetime_to' => $to->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
                'limit' => self::PAGE_LIMIT,
                'page' => $page,
            ]);
            $results = $data['results'] ?? [];

            foreach ($results as $hour) {
                $value = $hour['value'] ?? null;
                $start = $hour['period']['datetimeFrom']['utc'] ?? null;
                $coverage = (float) ($hour['coverage']['percentCoverage'] ?? 0);
                if (!is_numeric($value) || $value < 0 || $start === null) {
                    continue;
                }
                if ($coverage < Config::OPENAQ_MIN_HOUR_COVERAGE_PERCENT) {
                    continue;
                }
                if (!empty($hour['flagInfo']['hasFlags'])) {
                    continue;
                }
                $rows[] = [
                    'observed_at' => (new \DateTimeImmutable($start))
                        ->setTimezone(new \DateTimeZone('UTC'))
                        ->format('Y-m-d\TH:i:s'),
                    'pm25' => (float) $value,
                ];
            }

            if (count($results) < self::PAGE_LIMIT) {
                break;
            }
        }

        return $rows;
    }

    private function get(string $path, array $query): array
    {
        $url = $this->config->openAqApiBase . $path . '?' . http_build_query($query);

        for ($attempt = 1; ; $attempt++) {
            $this->throttle();

            $headers = [];
            $ch = curl_init($url);
            if ($ch === false) {
                throw new \RuntimeException('Unable to start HTTP request to OpenAQ');
            }
            curl_setopt_array($ch, [
                CURLOPT_HTTPHEADER => ['Accept: application/json', 'X-API-Key: ' . $this->config->openAqApiKey],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 60,
                CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$headers): int {
                    $parts = explode(':', $line, 2);
                    if (count($parts) === 2) {
                        $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
                    }
                    return strlen($line);
                },
            ]);
            $body = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($body === false) {
                throw new \RuntimeException('OpenAQ request failed: ' . $error);
            }

            if ($status === 429 && $attempt <= self::MAX_RETRIES) {
                $reset = (int) ($headers['x-ratelimit-reset'] ?? 0);
                sleep(max(5, min(65, $reset)));
                continue;
            }

            if ($status === 401 || $status === 403) {
                throw new \RuntimeException('OpenAQ rejected the API key (HTTP ' . $status . '). Check OPENAQ_API_KEY.');
            }

            if ($status !== 200) {
                throw new \RuntimeException('OpenAQ returned HTTP ' . $status . ' for ' . $path);
            }

            try {
                $decoded = json_decode((string) $body, true, flags: JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                throw new \RuntimeException('OpenAQ returned invalid JSON for ' . $path);
            }

            return is_array($decoded) ? $decoded : [];
        }
    }

    private function throttle(): void
    {
        $wait = $this->lastRequestAt + self::MIN_INTERVAL_SECONDS - microtime(true);
        if ($wait > 0) {
            usleep((int) ($wait * 1_000_000));
        }
        $this->lastRequestAt = microtime(true);
    }
}

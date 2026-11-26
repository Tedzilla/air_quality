<?php

declare(strict_types=1);

namespace AirQuality\Eea;

use AirQuality\Config;

final class Client
{
    public function __construct(private readonly Config $config)
    {
    }

    public function downloadCityParquetZip(
        string $city,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        string $destinationZip,
    ): int {
        $body = [
            'countries' => [Config::COUNTRY],
            'cities' => [$city],
            'pollutants' => [Config::POLLUTANT],
            'dataset' => Config::EEA_DATASET_UTD,
            'dateTimeStart' => $from->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            'dateTimeEnd' => $to->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            'aggregationType' => 'hour',
            'source' => 'API',
        ];

        $payload = $this->post('/ParquetFile', $body);
        file_put_contents($destinationZip, $payload);

        return strlen($payload);
    }

    private function post(string $path, array $body): string
    {
        $url = $this->config->eeaApiBase . $path;
        $json = json_encode($body, JSON_THROW_ON_ERROR);

        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('Unable to start HTTP request to EEA');
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/zip'],
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 180,
        ]);

        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new \RuntimeException('EEA request failed: ' . $error);
        }

        if ($status !== 200 && $status !== 206) {
            throw new \RuntimeException('EEA returned HTTP ' . $status . ' for ' . $path);
        }

        if (!str_starts_with($response, 'PK')) {
            throw new \RuntimeException('EEA did not return a parquet zip for the requested filters');
        }

        return $response;
    }
}

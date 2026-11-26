<?php

declare(strict_types=1);

namespace AirQuality\Ranking;

use AirQuality\Config;
use AirQuality\Storage\Database;

final class RankingService
{
    public function __construct(
        private readonly Database $database,
        /** @var list<string> */
        private readonly array $enabledSources = ['eea'],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function rank(?string $fromRaw, ?string $toRaw): array
    {
        $fromDate = $this->parseDate($fromRaw, 'from');
        $toDate = $this->parseDate($toRaw, 'to');

        if ($fromDate > $toDate) {
            throw new RankingException('The start date must be on or before the end date.');
        }

        $today = new \DateTimeImmutable('today', new \DateTimeZone('UTC'));
        if ($fromDate > $today || $toDate > $today) {
            throw new RankingException('Dates cannot be in the future.');
        }

        $window = $this->database->dataWindow();
        if ($window === null) {
            throw new RankingException('No local air-quality data yet. Wait for the first EEA download to finish.');
        }

        $windowFrom = new \DateTimeImmutable($window['from'], new \DateTimeZone('UTC'));
        $windowTo = new \DateTimeImmutable($window['to'], new \DateTimeZone('UTC'));
        if ($toDate < $windowFrom || $fromDate > $windowTo) {
            throw new RankingException(sprintf(
                'No stored data for that period. Available local history is %s to %s.',
                $window['from'],
                $window['to']
            ));
        }

        $from = $fromDate->format('Y-m-d') . 'T00:00:00';
        $toExclusive = $toDate->modify('+1 day')->format('Y-m-d') . 'T00:00:00';
        $ranked = $this->database->ranking($from, $toExclusive);

        // Hours that have already ended in the requested period; today's later hours cannot have data yet.
        $periodEnd = min(
            new \DateTimeImmutable($toExclusive, new \DateTimeZone('UTC')),
            new \DateTimeImmutable(gmdate('Y-m-d\TH:00:00'), new \DateTimeZone('UTC')),
        );
        $expectedHours = max(1, intdiv($periodEnd->getTimestamp() - $fromDate->getTimestamp(), 3600));

        $rankedNames = array_map(static fn (array $row): string => $row['city'], $ranked);
        $unavailable = [];
        foreach (Config::CITIES as $city) {
            if (!in_array($city, $rankedNames, true)) {
                $unavailable[] = [
                    'city' => $city,
                    'reason' => 'None of the data sources (EEA, OpenAQ, sensor.community) has valid hourly PM2.5 measurements for this city in the selected period.',
                ];
            }
        }

        if ($ranked === []) {
            throw new RankingException('No valid PM2.5 measurements in the selected period for the tracked cities.');
        }

        $ranked = array_map(fn (array $row): array => $this->describe($row, $expectedHours), $ranked);

        // Cities measured mostly by reference monitors are ranked first; low-cost-only cities follow,
        // each group sorted by average. Only a high-confidence city can be named the cleanest, unless
        // no city has reference data in the period.
        $high = array_values(array_filter($ranked, static fn (array $r): bool => $r['confidence'] === 'high'));
        $low = array_values(array_filter($ranked, static fn (array $r): bool => $r['confidence'] === 'low'));
        $ranked = array_merge($high, $low);
        $cleanest = $high[0] ?? $low[0];

        return [
            'from' => $fromDate->format('Y-m-d'),
            'to' => $toDate->format('Y-m-d'),
            'pollutant' => Config::POLLUTANT,
            'unit' => 'µg/m³',
            'cleanest' => $cleanest,
            'cleanest_basis' => $high !== []
                ? 'Chosen among cities measured mainly by reference monitors (EEA or OpenAQ reference stations).'
                : 'No city has reference-monitor data in this period; chosen from low-cost sensors, lower confidence.',
            'ranking' => $ranked,
            'confidence_rule' => sprintf(
                'high = at least %d%% of covered hours come from reference monitors; low = mainly low-cost sensors.',
                Config::REFERENCE_SHARE_FOR_HIGH_CONFIDENCE * 100
            ),
            'coverage_threshold_percent' => Config::COVERAGE_THRESHOLD * 100,
            'unavailable' => $unavailable,
            'data_window' => [
                'from' => $window['from'],
                'to' => $window['to'],
                'samples' => $window['samples'],
            ],
            'last_updated' => $this->database->meta('last_ingest_at'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function status(): array
    {
        return [
            'cities' => Config::CITIES,
            'pollutant' => Config::POLLUTANT,
            'unit' => 'µg/m³',
            'source' => 'European Environment Agency air quality download service',
            'source_priority' => Config::SOURCES,
            'enabled_sources' => $this->enabledSources,
            'fallback_rule' => 'A fallback source fills missing hours for a city when the sources ranked above it cover less than '
                . (Config::COVERAGE_THRESHOLD * 100) . '% of the history window.',
            'history_months' => Config::HISTORY_MONTHS,
            'data_window' => $this->database->dataWindow(),
            'last_updated' => $this->database->meta('last_ingest_at'),
            'last_ingest_error' => $this->database->meta('last_ingest_error') ?: null,
            'last_ingest_summary' => $this->decodeSummary($this->database->meta('last_ingest_summary')),
        ];
    }

    /**
     * @param array{city: string, average_pm25: float, sample_count: int, station_count: int,
     *     hours_covered: int, reference_hours: int, hours_by_source: array<string, int>} $row
     * @return array<string, mixed>
     */
    private function describe(array $row, int $expectedHours): array
    {
        $sources = array_keys($row['hours_by_source']);
        $percent = min(100.0, round($row['hours_covered'] / $expectedHours * 100, 1));
        $referenceShare = $row['hours_covered'] > 0 ? $row['reference_hours'] / $row['hours_covered'] : 0.0;

        return [
            'city' => $row['city'],
            'average_pm25' => $row['average_pm25'],
            'sample_count' => $row['sample_count'],
            'station_count' => $row['station_count'],
            'sources' => $sources,
            'confidence' => $referenceShare >= Config::REFERENCE_SHARE_FOR_HIGH_CONFIDENCE ? 'high' : 'low',
            'coverage' => [
                'hours_expected' => $expectedHours,
                'hours_covered' => $row['hours_covered'],
                'hours_by_source' => $row['hours_by_source'],
                'hours_from_reference_monitors' => $row['reference_hours'],
                'percent' => $percent,
                'complete' => $percent >= Config::COVERAGE_THRESHOLD * 100,
            ],
        ];
    }

    private function parseDate(?string $value, string $field): \DateTimeImmutable
    {
        if ($value === null || trim($value) === '') {
            throw new RankingException("Missing required query parameter: {$field} (use YYYY-MM-DD).");
        }

        $trimmed = trim($value);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $trimmed)) {
            throw new RankingException("Invalid {$field} date. Use YYYY-MM-DD.");
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $trimmed, new \DateTimeZone('UTC'));
        $errors = \DateTimeImmutable::getLastErrors();
        $hasDateErrors = is_array($errors)
            && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0);
        if ($date === false || $hasDateErrors) {
            throw new RankingException("Invalid {$field} date. Use a real calendar date as YYYY-MM-DD.");
        }

        return $date;
    }

    private function decodeSummary(?string $json): ?array
    {
        if ($json === null || $json === '') {
            return null;
        }

        try {
            $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }
}

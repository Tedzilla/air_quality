<?php

declare(strict_types=1);

namespace AirQuality\Storage;

use AirQuality\Config;
use PDO;

final class Database
{
    private PDO $pdo;

    public function __construct(Config $config)
    {
        $this->pdo = new PDO('sqlite:' . $config->sqlitePath(), options: [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $this->pdo->exec('PRAGMA journal_mode = WAL');
        $this->migrate();
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    private function migrate(): void
    {
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS cities (
                id INTEGER PRIMARY KEY,
                name TEXT NOT NULL UNIQUE
            );

            CREATE TABLE IF NOT EXISTS stations (
                sampling_point TEXT PRIMARY KEY,
                city_id INTEGER NOT NULL,
                FOREIGN KEY (city_id) REFERENCES cities(id)
            );

            CREATE TABLE IF NOT EXISTS measurements (
                sampling_point TEXT NOT NULL,
                city_id INTEGER NOT NULL,
                observed_at TEXT NOT NULL,
                pm25 REAL NOT NULL,
                PRIMARY KEY (sampling_point, observed_at)
            );

            CREATE INDEX IF NOT EXISTS idx_measurements_city_time
                ON measurements (city_id, observed_at);

            CREATE TABLE IF NOT EXISTS meta (
                key TEXT PRIMARY KEY,
                value TEXT NOT NULL
            );
        SQL);

        // Databases created before OpenAQ support have no source columns.
        $this->addColumnIfMissing('stations', 'source', "TEXT NOT NULL DEFAULT 'eea'");
        $this->addColumnIfMissing('stations', 'label', 'TEXT');
        $this->addColumnIfMissing('measurements', 'source', "TEXT NOT NULL DEFAULT 'eea'");
        // Set by the outlier filter; excluded samples stay stored but are ignored by coverage and ranking.
        $this->addColumnIfMissing('measurements', 'excluded', 'INTEGER NOT NULL DEFAULT 0');
        // 'reference' = regulatory monitor, 'low_cost' = citizen/low-cost sensor.
        $this->addColumnIfMissing('stations', 'grade', "TEXT NOT NULL DEFAULT 'low_cost'");
        $this->pdo->exec("UPDATE stations SET grade = 'reference' WHERE source = 'eea' AND grade <> 'reference'");

        $insert = $this->pdo->prepare('INSERT OR IGNORE INTO cities (name) VALUES (:name)');
        foreach (Config::CITIES as $city) {
            $insert->execute(['name' => $city]);
        }
    }

    private function addColumnIfMissing(string $table, string $column, string $definition): void
    {
        $columns = $this->pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll();
        foreach ($columns as $info) {
            if ($info['name'] === $column) {
                return;
            }
        }
        $this->pdo->exec(sprintf('ALTER TABLE %s ADD COLUMN %s %s', $table, $column, $definition));
    }

    public function cityId(string $name): int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM cities WHERE name = :name');
        $stmt->execute(['name' => $name]);
        $id = $stmt->fetchColumn();
        if ($id === false) {
            throw new \RuntimeException('Unknown city: ' . $name);
        }

        return (int) $id;
    }

    /** @return array<int, string> */
    public function citiesById(): array
    {
        $rows = $this->pdo->query('SELECT id, name FROM cities ORDER BY name')->fetchAll();
        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['id']] = $row['name'];
        }

        return $map;
    }

    public function setMeta(string $key, string $value): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO meta (key, value) VALUES (:key, :value)
             ON CONFLICT(key) DO UPDATE SET value = excluded.value'
        );
        $stmt->execute(['key' => $key, 'value' => $value]);
    }

    public function meta(string $key): ?string
    {
        $stmt = $this->pdo->prepare('SELECT value FROM meta WHERE key = :key');
        $stmt->execute(['key' => $key]);
        $value = $stmt->fetchColumn();

        return $value === false ? null : (string) $value;
    }

    public function replaceStation(
        string $samplingPoint,
        int $cityId,
        string $source = 'eea',
        ?string $label = null,
        string $grade = 'reference',
    ): void {
        $stmt = $this->pdo->prepare(
            'INSERT INTO stations (sampling_point, city_id, source, label, grade)
             VALUES (:sp, :city, :source, :label, :grade)
             ON CONFLICT(sampling_point) DO UPDATE SET
                city_id = excluded.city_id,
                source = excluded.source,
                label = COALESCE(excluded.label, stations.label),
                grade = excluded.grade'
        );
        $stmt->execute([
            'sp' => $samplingPoint,
            'city' => $cityId,
            'source' => $source,
            'label' => $label,
            'grade' => $grade,
        ]);
    }

    /**
     * Marks hourly samples that disagree wildly with the other sensors of the same source in the same
     * city-hour (a broken or badly placed sensor). Needs Config::OUTLIER_MIN_SENSORS sensors in that hour.
     * A sample is an outlier when it is more than OUTLIER_RATIO times the median of the other sensors
     * and also more than OUTLIER_MIN_DIFF µg/m³ above it. Re-evaluated from scratch on every call.
     *
     * @return int number of samples excluded
     */
    public function flagOutliers(int $cityId, string $source): int
    {
        $this->pdo->beginTransaction();
        try {
            $reset = $this->pdo->prepare('UPDATE measurements SET excluded = 0 WHERE city_id = :city AND source = :source');
            $reset->execute(['city' => $cityId, 'source' => $source]);

            $select = $this->pdo->prepare(
                'SELECT observed_at, sampling_point, pm25 FROM measurements
                 WHERE city_id = :city AND source = :source ORDER BY observed_at'
            );
            $select->execute(['city' => $cityId, 'source' => $source]);

            $mark = $this->pdo->prepare(
                'UPDATE measurements SET excluded = 1 WHERE sampling_point = :sp AND observed_at = :at'
            );
            $excluded = 0;
            $hour = null;
            $samples = [];
            $flush = function () use (&$samples, &$hour, &$excluded, $mark): void {
                if (count($samples) >= Config::OUTLIER_MIN_SENSORS) {
                    foreach ($samples as $samplingPoint => $value) {
                        $others = $samples;
                        unset($others[$samplingPoint]);
                        $median = self::median(array_values($others));
                        if ($value > $median * Config::OUTLIER_RATIO && $value - $median > Config::OUTLIER_MIN_DIFF) {
                            $mark->execute(['sp' => $samplingPoint, 'at' => $hour]);
                            $excluded++;
                        }
                    }
                }
                $samples = [];
            };
            foreach ($select as $row) {
                if ($row['observed_at'] !== $hour) {
                    $flush();
                    $hour = $row['observed_at'];
                }
                $samples[$row['sampling_point']] = (float) $row['pm25'];
            }
            $flush();

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return $excluded;
    }

    /** @param list<float> $values */
    private static function median(array $values): float
    {
        sort($values);
        $n = count($values);
        $mid = intdiv($n, 2);

        return $n % 2 === 1 ? $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2;
    }

    /** Latest stored hour for one sampling point, or null. */
    public function latestObservation(string $samplingPoint): ?string
    {
        $stmt = $this->pdo->prepare('SELECT max(observed_at) FROM measurements WHERE sampling_point = :sp');
        $stmt->execute(['sp' => $samplingPoint]);
        $value = $stmt->fetchColumn();

        return $value === false || $value === null ? null : (string) $value;
    }

    /**
     * Distinct hours in [$from, $toExclusive) that have at least one measurement for the city.
     * With $sources, only those sources count; without it, any source does.
     *
     * @param list<string>|null $sources
     */
    public function coveredHours(int $cityId, string $from, string $toExclusive, ?array $sources = null): int
    {
        $sql = 'SELECT COUNT(DISTINCT observed_at) FROM measurements
                WHERE city_id = ? AND observed_at >= ? AND observed_at < ? AND excluded = 0';
        $params = [$cityId, $from, $toExclusive];
        if ($sources !== null) {
            if ($sources === []) {
                return 0;
            }
            $sql .= ' AND source IN (' . implode(', ', array_fill(0, count($sources), '?')) . ')';
            array_push($params, ...$sources);
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /** Longest run of consecutive hours with no data for the city, in [$from, $toExclusive). */
    public function longestGapHours(int $cityId, string $from, string $toExclusive): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT DISTINCT observed_at FROM measurements
             WHERE city_id = :city AND observed_at >= :from AND observed_at < :to AND excluded = 0
             ORDER BY observed_at'
        );
        $stmt->execute(['city' => $cityId, 'from' => $from, 'to' => $toExclusive]);

        $utc = new \DateTimeZone('UTC');
        $previous = (new \DateTimeImmutable($from, $utc))->getTimestamp() - 3600;
        $longest = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $observedAt) {
            $current = (new \DateTimeImmutable((string) $observedAt, $utc))->getTimestamp();
            $longest = max($longest, intdiv($current - $previous, 3600) - 1);
            $previous = $current;
        }
        $end = (new \DateTimeImmutable($toExclusive, $utc))->getTimestamp();

        return max($longest, intdiv($end - $previous, 3600) - 1);
    }

    /**
     * @param list<array{sampling_point: string, observed_at: string, pm25: float}> $rows
     */
    public function upsertMeasurements(int $cityId, array $rows, string $source = 'eea'): int
    {
        if ($rows === []) {
            return 0;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO measurements (sampling_point, city_id, observed_at, pm25, source)
             VALUES (:sp, :city, :at, :pm25, :source)
             ON CONFLICT(sampling_point, observed_at) DO UPDATE SET
                pm25 = excluded.pm25,
                city_id = excluded.city_id,
                source = excluded.source'
        );

        $count = 0;
        $this->pdo->beginTransaction();
        try {
            foreach ($rows as $row) {
                $stmt->execute([
                    'sp' => $row['sampling_point'],
                    'city' => $cityId,
                    'at' => $row['observed_at'],
                    'pm25' => $row['pm25'],
                    'source' => $source,
                ]);
                $count++;
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return $count;
    }

    public function pruneOlderThan(string $fromInclusive): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM measurements WHERE observed_at < :from');
        $stmt->execute(['from' => $fromInclusive]);
    }

    public function dataWindow(): ?array
    {
        $row = $this->pdo->query(
            'SELECT min(observed_at) AS min_at, max(observed_at) AS max_at, count(*) AS n FROM measurements'
        )->fetch();

        if (!$row || (int) $row['n'] === 0) {
            return null;
        }

        return [
            'from' => substr((string) $row['min_at'], 0, 10),
            'to' => substr((string) $row['max_at'], 0, 10),
            'samples' => (int) $row['n'],
        ];
    }

    /**
     * City averages over every hourly sample in [$from, $toExclusive).
     *
     * Sources are ranked by Config::SOURCES. For each city-hour, only samples from the highest-priority
     * source that has data for that hour are used, so a station published by several sources is never
     * counted twice and lower-quality sources only fill gaps.
     *
     * @return list<array{city_id: int, city: string, average_pm25: float, sample_count: int, station_count: int,
     *     hours_covered: int, reference_hours: int, hours_by_source: array<string, int>}>
     */
    public function ranking(string $from, string $toExclusive): array
    {
        $priority = 'CASE m.source';
        foreach (Config::SOURCES as $rank => $source) {
            $priority .= sprintf(' WHEN %s THEN %d', $this->pdo->quote($source), $rank);
        }
        $priority .= ' ELSE ' . count(Config::SOURCES) . ' END';

        $sql = <<<SQL
            WITH ranked AS (
                SELECT m.*, {$priority} AS source_rank, COALESCE(s.grade, 'low_cost') AS grade
                FROM measurements m
                LEFT JOIN stations s ON s.sampling_point = m.sampling_point
                WHERE m.observed_at >= :from AND m.observed_at < :to AND m.excluded = 0
            ),
            tagged AS (
                SELECT ranked.*, MIN(source_rank) OVER (PARTITION BY city_id, observed_at) AS best_rank
                FROM ranked
            ),
            chosen AS (
                SELECT * FROM tagged WHERE source_rank = best_rank
            )
            SELECT
                c.id AS city_id,
                c.name AS city,
                AVG(ch.pm25) AS average_pm25,
                COUNT(*) AS sample_count,
                COUNT(DISTINCT ch.sampling_point) AS station_count,
                COUNT(DISTINCT ch.observed_at) AS hours_covered,
                COUNT(DISTINCT CASE WHEN ch.grade = 'reference' THEN ch.observed_at END) AS reference_hours,
                (SELECT group_concat(source || '=' || n) FROM (
                    SELECT x.source, COUNT(DISTINCT x.observed_at) AS n
                    FROM chosen x WHERE x.city_id = c.id GROUP BY x.source
                )) AS hours_by_source
            FROM cities c
            JOIN chosen ch ON ch.city_id = c.id
            GROUP BY c.id, c.name
            ORDER BY average_pm25 ASC, c.name ASC
        SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['from' => $from, 'to' => $toExclusive]);

        $rows = [];
        foreach ($stmt as $row) {
            $bySource = [];
            foreach (explode(',', (string) $row['hours_by_source']) as $pair) {
                if (str_contains($pair, '=')) {
                    [$source, $count] = explode('=', $pair, 2);
                    $bySource[$source] = (int) $count;
                }
            }
            // Keep priority order for stable output.
            $order = array_flip(Config::SOURCES);
            uksort($bySource, static fn (string $a, string $b): int => ($order[$a] ?? 99) <=> ($order[$b] ?? 99));

            $rows[] = [
                'city_id' => (int) $row['city_id'],
                'city' => $row['city'],
                'average_pm25' => round((float) $row['average_pm25'], 2),
                'sample_count' => (int) $row['sample_count'],
                'station_count' => (int) $row['station_count'],
                'hours_covered' => (int) $row['hours_covered'],
                'reference_hours' => (int) $row['reference_hours'],
                'hours_by_source' => $bySource,
            ];
        }

        return $rows;
    }
}

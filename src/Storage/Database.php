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

        $insert = $this->pdo->prepare('INSERT OR IGNORE INTO cities (name) VALUES (:name)');
        foreach (Config::CITIES as $city) {
            $insert->execute(['name' => $city]);
        }
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

    public function replaceStation(string $samplingPoint, int $cityId): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO stations (sampling_point, city_id) VALUES (:sp, :city)
             ON CONFLICT(sampling_point) DO UPDATE SET city_id = excluded.city_id'
        );
        $stmt->execute(['sp' => $samplingPoint, 'city' => $cityId]);
    }

    /**
     * @param list<array{sampling_point: string, observed_at: string, pm25: float}> $rows
     */
    public function upsertMeasurements(int $cityId, array $rows): int
    {
        if ($rows === []) {
            return 0;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO measurements (sampling_point, city_id, observed_at, pm25)
             VALUES (:sp, :city, :at, :pm25)
             ON CONFLICT(sampling_point, observed_at) DO UPDATE SET
                pm25 = excluded.pm25,
                city_id = excluded.city_id'
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
     * @return list<array{city_id: int, city: string, average_pm25: float, sample_count: int, station_count: int}>
     */
    public function ranking(string $from, string $toExclusive): array
    {
        $sql = <<<'SQL'
            SELECT
                c.id AS city_id,
                c.name AS city,
                AVG(m.pm25) AS average_pm25,
                COUNT(*) AS sample_count,
                COUNT(DISTINCT m.sampling_point) AS station_count
            FROM cities c
            JOIN measurements m ON m.city_id = c.id
            WHERE m.observed_at >= :from AND m.observed_at < :to
            GROUP BY c.id, c.name
            ORDER BY average_pm25 ASC, c.name ASC
        SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['from' => $from, 'to' => $toExclusive]);

        $rows = [];
        foreach ($stmt as $row) {
            $rows[] = [
                'city_id' => (int) $row['city_id'],
                'city' => $row['city'],
                'average_pm25' => round((float) $row['average_pm25'], 2),
                'sample_count' => (int) $row['sample_count'],
                'station_count' => (int) $row['station_count'],
            ];
        }

        return $rows;
    }
}

<?php

declare(strict_types=1);

namespace AirQuality\Ingest;

use AirQuality\Config;

final class ParquetReader
{
    public function __construct(private readonly Config $config)
    {
    }

    /**
     * @return list<array{sampling_point: string, observed_at: string, pm25: float}>
     */
    public function extractHourlyPm25(string $parquetDir, string $csvPath): array
    {
        $files = $this->listParquetFiles($parquetDir);
        if ($files === []) {
            return [];
        }

        $listSql = implode(', ', array_map(
            static fn (string $path): string => "'" . str_replace("'", "''", $path) . "'",
            $files
        ));

        $escapedCsv = str_replace("'", "''", $csvPath);
        $sql = <<<SQL
COPY (
    SELECT
        Samplingpoint AS sampling_point,
        strftime("Start", '%Y-%m-%dT%H:%M:%S') AS observed_at,
        CAST(Value AS DOUBLE) AS pm25
    FROM read_parquet([{$listSql}])
    WHERE Validity IN (1, 2, 3)
      AND Value IS NOT NULL
      AND Value >= 0
      AND AggType = 'hour'
) TO '{$escapedCsv}' (HEADER, DELIMITER ',');
SQL;

        $this->runDuckDb($sql);

        if (!is_file($csvPath)) {
            return [];
        }

        $handle = fopen($csvPath, 'r');
        if ($handle === false) {
            throw new \RuntimeException('Cannot read converted CSV: ' . $csvPath);
        }

        $header = fgetcsv($handle);
        $rows = [];
        while (($data = fgetcsv($handle)) !== false) {
            if ($header === false || count($data) < 3) {
                continue;
            }
            $rows[] = [
                'sampling_point' => (string) $data[0],
                'observed_at' => (string) $data[1],
                'pm25' => (float) $data[2],
            ];
        }
        fclose($handle);

        return $rows;
    }

    /** @return list<string> */
    private function listParquetFiles(string $dir): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->isFile() && str_ends_with(strtolower($file->getFilename()), '.parquet')) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    private function runDuckDb(string $sql): void
    {
        $descriptor = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open([$this->config->duckdbBin, '-c', $sql], $descriptor, $pipes);
        if (!is_resource($process)) {
            throw new \RuntimeException('Cannot start DuckDB to read EEA parquet files');
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);

        if ($code !== 0) {
            throw new \RuntimeException(trim('DuckDB failed: ' . $stderr . ' ' . $stdout));
        }
    }
}

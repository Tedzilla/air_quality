<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/Autoload.php';

use AirQuality\Config;
use AirQuality\Eea\Client;
use AirQuality\Ingest\Ingestor;
use AirQuality\Ingest\ParquetReader;
use AirQuality\Storage\Database;

$config = Config::fromEnvironment();
$database = new Database($config);

try {
    $ingestor = new Ingestor(
        $config,
        $database,
        new Client($config),
        new ParquetReader($config),
    );
    $summary = $ingestor->run();
    fwrite(STDOUT, json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
} catch (Throwable $e) {
    $database->setMeta('last_ingest_error', $e->getMessage());
    fwrite(STDERR, 'Ingest failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

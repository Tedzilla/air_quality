<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/Autoload.php';

use AirQuality\Config;

$config = Config::fromEnvironment();
$seconds = $config->refreshHours * 3600;
$ingest = dirname(__DIR__) . '/bin/ingest.php';

while (true) {
    sleep($seconds);
    passthru('php ' . escapeshellarg($ingest), $code);
    if ($code !== 0) {
        fwrite(STDERR, date('c') . " scheduled ingest exited with {$code}\n");
    }
}

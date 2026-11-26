<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/Autoload.php';

use AirQuality\Config;
use AirQuality\Http\Application;
use AirQuality\Ranking\RankingService;
use AirQuality\Storage\Database;

$config = Config::fromEnvironment();
$database = new Database($config);
$app = new Application(new RankingService($database, array_values(array_filter([
    'eea',
    $config->openAqApiKey !== null ? 'openaq' : null,
    $config->sensorCommunityEnabled ? 'sensor_community' : null,
]))));

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$app->handle($_SERVER['REQUEST_METHOD'] ?? 'GET', $path, $_GET);

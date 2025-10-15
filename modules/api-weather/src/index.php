<?php

declare(strict_types=1);

namespace App;

require_once __DIR__ . '/../vendor/autoload.php';

use App\Controllers\WeatherController;
use App\Services\WeatherService;
use App\Services\ExternalWeatherService;
use Monolog\Level;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

define('API_ID', uniqid('api-weather'));

/** @var Logger */
$logger = new Logger(API_ID);
$logger->pushHandler(new StreamHandler('php://stdout'), Level::Debug);
$logger->pushProcessor(function ($record) {
    $record->extra['api_id'] = API_ID;
    return $record;
});

$requestUri = $_SERVER['REQUEST_URI'];
$requestMethod = $_SERVER['REQUEST_METHOD'];

// Parse the URL to remove query parameters
$path = parse_url($requestUri, PHP_URL_PATH);

$weatherService = new WeatherService();
$externalWeatherService = new ExternalWeatherService($logger);
$weatherController = new WeatherController($weatherService, $externalWeatherService);

if ($requestMethod === 'GET') {
    if ($path === '/health') {
        $weatherController->health();
    } elseif (preg_match('#^/api/weather/external/(.+)$#', $path, $matches)) {
        $city = urldecode($matches[1]);
        $weatherController->getExternalWeather($city);
    } elseif (preg_match('#^/api/weather/(.+)$#', $path, $matches)) {
        $city = urldecode($matches[1]);
        $weatherController->getWeather($city);
    } else {
        $logger->warning('Not found', ['path' => $path, 'method' => $requestMethod]);
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Not found']);
    }
} else {
    $logger->warning('Method not allowed', ['path' => $path, 'method' => $requestMethod]);
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Method not allowed']);
}
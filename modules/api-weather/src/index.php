<?php

declare(strict_types=1);

namespace App;

require_once __DIR__ . '/../vendor/autoload.php';

use App\Controllers\WeatherController;
use App\Services\WeatherService;

$requestUri = $_SERVER['REQUEST_URI'];
$requestMethod = $_SERVER['REQUEST_METHOD'];

// Parse the URL to remove query parameters
$path = parse_url($requestUri, PHP_URL_PATH);

$weatherService = new WeatherService();
$weatherController = new WeatherController($weatherService);

if ($requestMethod === 'GET') {
    if ($path === '/health') {
        $weatherController->health();
    } elseif (preg_match('#^/api/weather/(.+)$#', $path, $matches)) {
        $city = urldecode($matches[1]);
        $weatherController->getWeather($city);
    } else {
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Not found']);
    }
} else {
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Method not allowed']);
}
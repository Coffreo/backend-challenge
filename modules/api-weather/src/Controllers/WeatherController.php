<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\WeatherService;

class WeatherController
{
    private WeatherService $weatherService;

    public function __construct(WeatherService $weatherService)
    {
        $this->weatherService = $weatherService;
    }

    public function getWeather(string $city): void
    {
        header('Content-Type: application/json');
        
        $weatherData = $this->weatherService->getWeatherForCity($city);
        echo json_encode($weatherData);
    }

    public function health(): void
    {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'ok']);
    }
}
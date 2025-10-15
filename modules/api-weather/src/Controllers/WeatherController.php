<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\WeatherService;
use App\Services\ExternalWeatherService;

class WeatherController
{
    private WeatherService $weatherService;
    private ExternalWeatherService $externalWeatherService;

    public function __construct(WeatherService $weatherService, ExternalWeatherService $externalWeatherService = null)
    {
        $this->weatherService = $weatherService;
        $this->externalWeatherService = $externalWeatherService ?: new ExternalWeatherService();
    }

    public function getWeather(string $city): void
    {
        header('Content-Type: application/json');
        
        $weatherData = $this->weatherService->getWeatherForCity($city);
        echo json_encode($weatherData);
    }

    public function getExternalWeather(string $city): void
    {
        header('Content-Type: application/json');
        
        $weatherData = $this->externalWeatherService->getWeatherForCity($city);
        echo json_encode($weatherData);
    }

    public function health(): void
    {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'ok']);
    }
}
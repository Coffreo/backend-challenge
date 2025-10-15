<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\WeatherService;
use App\Services\ExternalWeatherService;

/**
 * Handles weather API endpoints and health checks.
 */
class WeatherController
{
    private WeatherService $weatherService;
    private ExternalWeatherService $externalWeatherService;

    public function __construct(WeatherService $weatherService, ExternalWeatherService $externalWeatherService = null)
    {
        $this->weatherService = $weatherService;
        $this->externalWeatherService = $externalWeatherService ?: new ExternalWeatherService();
    }

    /**
     * Returns random weather data for a given city.
     *
     * @param string $city The city name
     */
    public function getWeather(string $city): void
    {
        header('Content-Type: application/json');
        
        $weatherData = $this->weatherService->getWeatherForCity($city);
        echo json_encode($weatherData);
    }

    /**
     * Returns weather data from external API with fallback to random data.
     *
     * @param string $city The city name
     */
    public function getExternalWeather(string $city): void
    {
        header('Content-Type: application/json');
        
        $weatherData = $this->externalWeatherService->getWeatherForCity($city);
        echo json_encode($weatherData);
    }

    /**
     * Health check endpoint.
     */
    public function health(): void
    {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'ok']);
    }
}
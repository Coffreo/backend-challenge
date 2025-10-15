<?php

declare(strict_types=1);

namespace App\Services;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Generates random weather data for testing purposes.
 */
class WeatherService
{
    private const CONDITIONS = ['sunny', 'cloudy', 'rainy', 'snowy', 'stormy'];
    private LoggerInterface $logger;
    
    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?: new NullLogger();
    }
    
    /**
     * Generates random weather data for a city.
     *
     * @param string $city The city name
     * @return array Weather data with city, temperature, condition, humidity, wind_speed and timestamp
     */
    public function getWeatherForCity(string $city): array
    {
        $weatherData = [
            'city' => $city,
            'temperature' => rand(-10, 35),
            'condition' => self::CONDITIONS[array_rand(self::CONDITIONS)],
            'humidity' => rand(20, 90),
            'wind_speed' => rand(0, 50),
            'timestamp' => date('c')
        ];

        $this->logger->info('Generated random weather data', [
            'city' => $city,
            'service' => 'WeatherService',
            'temperature' => $weatherData['temperature'],
            'condition' => $weatherData['condition'],
            'data_type' => 'random'
        ]);

        return $weatherData;
    }
}
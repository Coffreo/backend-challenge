<?php

declare(strict_types=1);

namespace App\Services;

class WeatherService
{
    private const CONDITIONS = ['sunny', 'cloudy', 'rainy', 'snowy', 'stormy'];
    
    public function getWeatherForCity(string $city): array
    {
        return [
            'city' => $city,
            'temperature' => rand(-10, 35),
            'condition' => self::CONDITIONS[array_rand(self::CONDITIONS)],
            'humidity' => rand(20, 90),
            'wind_speed' => rand(0, 50),
            'timestamp' => date('c')
        ];
    }
}
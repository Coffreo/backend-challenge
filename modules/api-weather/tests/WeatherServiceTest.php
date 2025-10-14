<?php

declare(strict_types=1);

namespace App\Tests;

use App\Services\WeatherService;
use PHPUnit\Framework\TestCase;

class WeatherServiceTest extends TestCase
{
    private WeatherService $weatherService;

    protected function setUp(): void
    {
        $this->weatherService = new WeatherService();
    }

    public function testGetWeatherForCity(): void
    {
        $city = 'Paris';
        $weather = $this->weatherService->getWeatherForCity($city);

        $this->assertIsArray($weather);
        $this->assertEquals($city, $weather['city']);
        $this->assertIsInt($weather['temperature']);
        $this->assertGreaterThanOrEqual(-10, $weather['temperature']);
        $this->assertLessThanOrEqual(35, $weather['temperature']);
        $this->assertContains($weather['condition'], ['sunny', 'cloudy', 'rainy', 'snowy', 'stormy']);
        $this->assertIsInt($weather['humidity']);
        $this->assertGreaterThanOrEqual(20, $weather['humidity']);
        $this->assertLessThanOrEqual(90, $weather['humidity']);
        $this->assertIsInt($weather['wind_speed']);
        $this->assertGreaterThanOrEqual(0, $weather['wind_speed']);
        $this->assertLessThanOrEqual(50, $weather['wind_speed']);
        $this->assertArrayHasKey('timestamp', $weather);
    }
}
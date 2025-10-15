<?php

declare(strict_types=1);

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class ExternalWeatherService
{
    private Client $httpClient;
    private string $apiKey;
    private WeatherService $fallbackService;
    private LoggerInterface $logger;

    // Mapping OpenWeatherMap conditions to our format
    private const CONDITION_MAPPING = [
        'Clear' => 'sunny',
        'Clouds' => 'cloudy',
        'Rain' => 'rainy',
        'Drizzle' => 'rainy',
        'Snow' => 'snowy',
        'Thunderstorm' => 'stormy',
        'Mist' => 'cloudy',
        'Smoke' => 'cloudy',
        'Haze' => 'cloudy',
        'Dust' => 'cloudy',
        'Fog' => 'cloudy',
        'Sand' => 'cloudy',
        'Ash' => 'cloudy',
        'Squall' => 'stormy',
        'Tornado' => 'stormy'
    ];

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?: new NullLogger();
        $this->fallbackService = new WeatherService();

        $baseUrl = $_ENV['OPENWEATHERMAP_BASE_URL'] ?? 'https://api.openweathermap.org/data/2.5/';
        $this->apiKey = $_ENV['OPENWEATHERMAP_API_KEY'] ?? '';

        $this->httpClient = new Client([
            'base_uri' => $baseUrl,
            'timeout' => 10,
        ]);
    }

    public function getWeatherForCity(string $city): array
    {
        // Check if API key is configured
        if (empty($this->apiKey)) {
            $this->logger->warning('OpenWeatherMap API key not configured, using fallback');
            return $this->fallbackService->getWeatherForCity($city);
        }

        try {
            $response = $this->httpClient->get('weather', [
                'query' => [
                    'q' => $city,
                    'appid' => $this->apiKey,
                    'units' => 'metric' // Get temperature in Celsius directly
                ]
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if (!$data || !isset($data['main']) || !isset($data['weather'][0])) {
                throw new \Exception('Invalid response format from OpenWeatherMap');
            }

            $mappedData = $this->mapOpenWeatherData($data, $city);

            $this->logger->info('Successfully fetched weather data from OpenWeatherMap', [
                'city' => $city,
                'temperature' => $mappedData['temperature'],
                'condition' => $mappedData['condition']
            ]);

            return $mappedData;

        } catch (RequestException $e) {
            $this->logger->error('OpenWeatherMap API request failed', [
                'city' => $city,
                'error' => $e->getMessage(),
                'status_code' => $e->getResponse()?->getStatusCode()
            ]);

            // Fallback to random data
            return $this->getFallbackData($city);

        } catch (\Exception $e) {
            $this->logger->error('Error processing OpenWeatherMap response', [
                'city' => $city,
                'error' => $e->getMessage()
            ]);

            // Fallback to random data
            return $this->getFallbackData($city);
        }
    }

    private function mapOpenWeatherData(array $data, string $city): array
    {
        $temperature = (int) round($data['main']['temp']);
        $condition = $this->mapCondition($data['weather'][0]['main'] ?? 'Clear');
        $humidity = (int) ($data['main']['humidity'] ?? 50);
        $windSpeed = (int) round(($data['wind']['speed'] ?? 0) * 3.6); // m/s to km/h

        return [
            'city' => $city,
            'temperature' => $temperature,
            'condition' => $condition,
            'humidity' => $humidity,
            'wind_speed' => $windSpeed,
            'timestamp' => date('c')
        ];
    }

    private function mapCondition(string $openWeatherCondition): string
    {
        return self::CONDITION_MAPPING[$openWeatherCondition] ?? 'cloudy';
    }

    private function getFallbackData(string $city): array
    {
        $this->logger->info('Using fallback weather data', ['city' => $city]);
        return $this->fallbackService->getWeatherForCity($city);
    }
}

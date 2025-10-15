<?php

declare(strict_types=1);

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Fetches weather data from OpenWeatherMap API with fallback to random data.
 */
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
        $this->fallbackService = new WeatherService($this->logger);

        $baseUrl = $_ENV['OPENWEATHERMAP_BASE_URL'] ?? 'https://api.openweathermap.org/data/2.5/';
        $this->apiKey = $_ENV['OPENWEATHERMAP_API_KEY'] ?? '';

        $this->httpClient = new Client([
            'base_uri' => $baseUrl,
            'timeout' => 10,
        ]);
    }

    /**
     * Fetches weather data for a city from OpenWeatherMap API or returns random data as fallback.
     *
     * @param string $city The city name
     * @return array Weather data with city, temperature, condition, humidity, wind_speed and timestamp
     */
    public function getWeatherForCity(string $city): array
    {
        // Check if API key is configured
        if (empty($this->apiKey)) {
            $this->logger->info('No API key configured, using fallback random data', [
                'city' => $city,
                'service' => 'ExternalWeatherService'
            ]);
            return $this->fallbackService->getWeatherForCity($city);
        }

        try {
            $this->logger->debug('Calling OpenWeatherMap API', [
                'city' => $city,
                'service' => 'ExternalWeatherService'
            ]);
            
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

            $this->logger->info('Successfully fetched real weather data from OpenWeatherMap', [
                'city' => $city,
                'service' => 'ExternalWeatherService',
                'temperature' => $mappedData['temperature'],
                'condition' => $mappedData['condition']
            ]);

            return $mappedData;

        } catch (RequestException $e) {
            $this->logger->warning('OpenWeatherMap API request failed, using fallback', [
                'city' => $city,
                'service' => 'ExternalWeatherService',
                'error' => $e->getMessage(),
                'fallback' => 'random_data'
            ]);

            return $this->getFallbackData($city);

        } catch (\Exception $e) {
            $this->logger->error('Error processing OpenWeatherMap response, using fallback', [
                'city' => $city,
                'service' => 'ExternalWeatherService',
                'error' => $e->getMessage(),
                'fallback' => 'random_data'
            ]);

            return $this->getFallbackData($city);
        }
    }

    /**
     * Maps OpenWeatherMap API response to our standard format.
     *
     * @param array $data Raw API response data
     * @param string $city The city name
     * @return array Formatted weather data
     */
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

    /**
     * Maps OpenWeatherMap weather condition to our standard format.
     *
     * @param string $openWeatherCondition The condition from OpenWeatherMap API
     * @return string Mapped condition (sunny, cloudy, rainy, snowy, stormy)
     */
    private function mapCondition(string $openWeatherCondition): string
    {
        return self::CONDITION_MAPPING[$openWeatherCondition] ?? 'cloudy';
    }

    private function getFallbackData(string $city): array
    {
        $this->logger->debug('Using fallback random weather data', [
            'city' => $city,
            'service' => 'ExternalWeatherService',
            'fallback_service' => 'WeatherService'
        ]);
        
        return $this->fallbackService->getWeatherForCity($city);
    }
}

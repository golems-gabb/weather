<?php

namespace Drupal\weather;

use Drupal\Core\Http\ClientFactory;
use Drupal\Component\Serialization\Json;
use Drupal\Component\Utility\NestedArray;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;

/**
 * A service that get data from openweathermap.
 *
 * Check if the city is entered correctly.
 */
class WeatherClient {

  /**
   * The HTTP client to fetch the feed data with.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * The CacheBackendInterface.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  protected $cacheBin;

  /**
   * The TimeInterface.
   *
   * @var \Drupal\Component\Datetime\TimeInterface
   */
  protected $time;

  /**
   * The ConfigFactoryInterface.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configs;

  /**
   * The key id.
   *
   * @var bool
   */
  protected $hasKey;

  /**
   * The city value.
   *
   * @var array|\Drupal\Core\Config\ImmutableConfig|false|mixed|null
   */
  protected $city;

  /**
   * The code value.
   *
   * @var array|\Drupal\Core\Config\ImmutableConfig|false|mixed|null
   */
  protected $code;

  /**
   * The options value.
   *
   * @var array[]
   */
  protected $defaultRequestOptions;

  /**
   * The ModuleHandlerInterface service.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * WeatherClient constructor.
   *
   * @param \Drupal\Core\Http\ClientFactory $http_client_factory
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache_bin
   * @param \Drupal\Component\Datetime\TimeInterface $time
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configs
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   */
  public function __construct(ClientFactory $http_client_factory, CacheBackendInterface $cache_bin, TimeInterface $time, ConfigFactoryInterface $configs, ModuleHandlerInterface $module_handler) {
    $this->cacheBin = $cache_bin;
    $this->time = $time;
    $this->configs = $configs->get('weather.settings');
    $this->moduleHandler = $module_handler;
    $key = $this->configs->get('weather_api_key');
    $city = $this->configs->get('weather_default_city');
    $code = $this->configs->get('weather_default_country_code');
    $this->hasKey = !empty($city);
    $this->city = $city ?? FALSE;
    $this->code = $code ?? FALSE;
    $endpoint = ($this->configs->get('weather_api_endpoint') ?? 'http://api.openweathermap.org/data/2.5/weather');
    $this->httpClient = $http_client_factory->fromOptions([
      'base_uri' => $endpoint,
      'timeout' => 9.0,
    ]);
    $this->defaultRequestOptions = [
      \GuzzleHttp\RequestOptions::QUERY => [
        'appid' => $key,
      ],
    ];
  }

  /**
   * Get whether from the api.openweathermap.org.
   *
   * @param null $city
   * @param null $code
   *
   * @return |null
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  public function getWeather($city = NULL, $code = NULL) {
    if (empty($this->hasKey)) {
      return NULL;
    }
    if (empty($city)) {
      if (empty($this->city) || empty($this->code)) {
        return NULL;
      }
      $city = $this->city;
      if (empty($code)) {
        $code = $this->code;
      }
    }
    $code = !empty($code) ? mb_strtolower($code) : $code;
    $cid = 'weather:' . $city . ':' . ($code ?? 'no_code');
    $cache = $this->cacheBin->get($cid, TRUE);
    if (isset($cache->expire) && $cache->expire < $this->time->getCurrentTime()) {
      $this->cacheBin->delete($cid);
      $cache = FALSE;
    }
    if (!isset($cache->data) || empty($cache->data)) {
      $q = [];
      $q[] = $city;
      if (!empty($code)) {
        $q[] = $code;
      }
      $requestsParametrs = NestedArray::mergeDeep($this->defaultRequestOptions, [
        \GuzzleHttp\RequestOptions::QUERY => [
          'q' => implode(',', $q),
          'units' => 'metric',
        ],
      ]);
      $response = $this->httpClient->request('GET', '', $requestsParametrs);
      $code = $response->getStatusCode();
      $content = '';
      if ($code >= 200 && $code < 300) {
        $content = $response->getBody()->getContents();
        if (!empty($content)) {
          $content = Json::decode($content);
        }
        $this->cacheBin->set($cid, $content, $this->time->getCurrentTime() + 90);
      }
      $cache = (object) ['data' => $content];
    }
    return $cache->data;
  }

  /**
   * Check whether the city is entered correctly.
   *
   * {@inheritdoc}
   */
  public function getCity($city = NULL, $country = NULL) {
    $result['city'] = $result['country'] = FALSE;
    if (empty($country)) {
      $result['country'] = NULL;
    }
    if (empty($city)) {
      $result['city'] = NULL;
    }
    $city = mb_strtolower($city);
    $country = mb_strtolower($country);
    $modulePath = $this->moduleHandler->getModule('weather')->getPath();
    $root = $modulePath . '/json/world-cities.json';
    $content = file_get_contents($root);
    if (!empty($content)) {
      $content = Json::decode($content);
      foreach ($content as $value) {
        if (!empty($value['country']) && !empty($value['name'])) {
          if (!empty($city) && !empty($country)) {
            if ($country === mb_strtolower($value['country']) && $city === mb_strtolower($value['name'])) {
              $result['city'] = $result['country'] = TRUE;
              return $result;
            }
          }
          else {
            if ($city === mb_strtolower($value['name'])) {
              $result['city'] = TRUE;
              return $result;
            }
          }
        }
      }
    }
    return $result;
  }

}

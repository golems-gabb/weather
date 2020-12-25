<?php

namespace Drupal\weather\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use CommerceGuys\Addressing\Country\CountryRepository;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;

/**
 * Configuration form for Weather settings.
 */
class WeatherSettingsForm extends ConfigFormBase implements ContainerInjectionInterface {

  /**
   * The country variable name.
   *
   * @var string
   */
  protected $code = 'weather_default_country_code';

  /**
   * The city variable name.
   *
   * @var string
   */
  protected $city = 'weather_default_city';

  /**
   * The key variable name.
   *
   * @var string
   */
  protected $key = 'weather_api_key';

  /**
   * The endpoint variable name.
   *
   * @var string
   */
  protected $endpoint = 'weather_api_endpoint';

  /**
   * The settings variable name.
   *
   * @var string
   */
  protected $weatherSettings = 'weather.settings';

  /**
   * The CountryRepository service.
   *
   * @var \CommerceGuys\Addressing\Country\CountryRepository
   */
  protected $countryRepository;

  /**
   * Constructs for settings form.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   */
  public function __construct(ConfigFactoryInterface $config_factory) {
    parent::__construct($config_factory);
    $this->countryRepository = new CountryRepository();
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $instance = parent::create($container);
    $instance->weatherClient = $container->get('weather.weather_client');
    return $instance;
  }

  /**
   * Form id.
   *
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'weather_settings_form';
  }

  /**
   * Configs names.
   *
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [$this->weatherSettings];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config($this->weatherSettings);
    $form[$this->endpoint] = [
      '#title' => $this->t('Weather API endpoint'),
      '#type' => 'url',
      '#default_value' => $config->get($this->endpoint),
      '#description' => $this->t('The API endpoint used for requests. Like http://api.openweathermap.org/data/2.5/weather.'),
      '#required' => TRUE,
    ];
    $form[$this->key] = [
      '#title' => $this->t('Weather API key'),
      '#type' => 'textfield',
      '#default_value' => $config->get($this->key),
      '#description' => $this->t('The API key used for requests.'),
      '#required' => TRUE,
    ];
    $countryList = $this->countryRepository->getList();
    $form[$this->code] = [
      '#type' => 'select',
      '#title' => $this->t('Country'),
      '#description' => $this->t('Select a country'),
      '#default_value' => !empty($config->get($this->code)) ? mb_strtoupper($config->get($this->code)) : 0,
      '#options' => $countryList ?? [],
    ];
    $form[$this->city] = [
      '#title' => $this->t('City'),
      '#type' => 'textfield',
      '#default_value' => $config->get($this->city),
      '#description' => $this->t('The city used for default.'),
      '#required' => TRUE,
    ];
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);
    $message = '';
    $values = $form_state->getValues();
    $countryList = $this->countryRepository->getList();
    $country = !empty($values[$this->code]) && !empty($countryList) && $countryList[$values[$this->code]] ? $countryList[$values[$this->code]] : NULL;
    $isCorrect = $this->weatherClient->getCity($values[$this->city], $country);
    if ($isCorrect['city'] === FALSE && $isCorrect['country'] === FALSE) {
      $message = $this->t('There is no such city in this country!');
    }
    elseif ($isCorrect['city'] === FALSE) {
      $message = $this->t('There is no such city!');
    }
    if (!empty($message)) {
      $form_state->setErrorByName($this->city, $message);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config($this->weatherSettings)
      ->set($this->endpoint, $form_state->getValue($this->endpoint))
      ->set($this->key, $form_state->getValue($this->key))
      ->set($this->code, $form_state->getValue($this->code))
      ->set($this->city, $form_state->getValue($this->city))
      ->save();
    parent::submitForm($form, $form_state);
  }

}

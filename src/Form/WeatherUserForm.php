<?php

namespace Drupal\weather\Form;

use Drupal\Core\Url;
use Drupal\Core\Render\Markup;
use Drupal\Core\Form\FormBase;
use Drupal\weather\WeatherClient;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use CommerceGuys\Addressing\Country\CountryRepository;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;

/**
 * Page and form for select country, city and get Weather.
 */
class WeatherUserForm extends FormBase implements ContainerInjectionInterface {

  /**
   * The weather_client service.
   *
   * @var \Drupal\weather\WeatherClient
   */
  protected $weatherClient;

  /**
   * The CountryRepository service.
   *
   * @var \CommerceGuys\Addressing\Country\CountryRepository
   */
  protected $countryRepository;

  /**
   * The ImmutableConfig service.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected $configs;

  /**
   * Constructs an Weather object.
   *
   * @param \Drupal\weather\WeatherClient $weather_client
   *   The weather_client service.
   */
  public function __construct(WeatherClient $weather_client) {
    $this->weatherClient = $weather_client;
    $this->countryRepository = new CountryRepository();
    $this->configs = \Drupal::config('weather.settings');
  }


  /**
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *
   * @return \Drupal\weather\Form\WeatherUserForm|static
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('weather.weather_client')
    );
  }

  /**
   * Form id.
   *
   * @return string
   */
  public function getFormId() {
    return 'weather_user_form';
  }

  /**
   * Form build.
   *
   * @param array $form
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   * @param null $city
   * @param null $code
   *
   * @return array
   */
  public function buildForm(array $form, FormStateInterface $form_state, $city = NULL, $code = NULL) {
    $message = '';
    $code = !empty($code) ? mb_strtoupper($code) : 0;
    $countryList[0] = 'None';
    $countryList += $this->countryRepository->getList();
    $form['city'] = [
      '#title' => $this->t('City'),
      '#description' => $this->t('Enter the city.'),
      '#type' => 'textfield',
      '#maxlength' => 128,
      '#default_value' => $city ?? '',
    ];
    $form['code'] = [
      '#type' => 'select',
      '#title' => $this->t('Country'),
      '#description' => $this->t('Select a country'),
      '#default_value' => $code,
      '#options' => $countryList,
    ];
    $form[]['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Submit'),
    ];
    if (empty($form_state->getUserInput())) {
      $country = !empty($code) && !empty($countryList[$code]) ? $countryList[$code] : '';
      $isCorrect = $this->weatherClient->getCity($city, $country);
      if ($isCorrect['city'] === FALSE && $isCorrect['country'] === FALSE) {
        $message = $this->t('There is no such city in this country!');
      }
      elseif ($isCorrect['city'] === FALSE) {
        $message = $this->t('There is no such city!');
      }
      if ($isCorrect['country'] === NULL) {
        $code = '';
      }
      if (!empty($message)) {
        \Drupal::messenger()
          ->addMessage($message, MessengerInterface::TYPE_ERROR);
      }
      else {
        $data = $this->weatherClient->getWeather($city, $code);
      }
      if (!empty($data) && !empty($data['main']['temp'])) {
        $text[] = $this->t('Temp: ') . $data['main']['temp'];
        $text[] = $this->t('Humidity: ') . $data['main']['humidity'];
        if (!empty($data['wind']['speed'])) {
          $text[] = $this->t('Wind: ') . $data['wind']['speed'];
        }
        $form['fieldset'] = [
          '#type' => 'fieldset',
          '#title' => $this->t($this->getTitle($country, $city)),
          '#collapsible' => FALSE,
          '#collapsed' => FALSE,
          '#tree' => FALSE,
        ];
        $form['fieldset']['info'] = [
          '#type' => 'markup',
          '#markup' => Markup::create(implode('</br>', $text)),
        ];
      }
    }
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function getTitle($country = NULL, $city = NULL) {
    $title = '';
    if (empty($city) && empty($country)) {
      if (!empty($this->configs->get('weather_default_city'))) {
        $city = $this->configs->get('weather_default_city');
      }
      if (!empty($code = $this->configs->get('weather_default_country_code'))) {
        $code = mb_strtoupper($code);
        $countryList = $this->countryRepository->getList();
        $country = !empty($countryList) && !empty($countryList[$code]) ? $countryList[$code] : '';
      }
    }
    if (!empty($country)) {
      $title .= ' Country: ' . $country . ',';
    }
    if (!empty($city)) {
      $title .= ' City: ' . $city;
    }
    $title = !empty($title) ? 'Weather for:' . $title : 'Weather:';
    return $title;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);
    $message = '';
    $values = $form_state->getValues();
    $countryList = $this->countryRepository->getList();
    $country = !empty($values['code']) && !empty($countryList) && $countryList[$values['code']] ? $countryList[$values['code']] : NULL;
    $isCorrect = $this->weatherClient->getCity($values['city'], $country);
    if ($isCorrect['city'] === FALSE && $isCorrect['country'] === FALSE) {
      $message = $this->t('There is no such city in this country!');
    }
    elseif ($isCorrect['city'] === FALSE) {
      $message = $this->t('There is no such city!');
    }
    if (!empty($message)) {
      $form_state->setErrorByName('city', $message);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $values = $form_state->getValues();
    if (!empty($values['city']) && !empty($values['code'])) {
      $url = Url::fromRoute('weather.country_page', [
        'city' => $values['city'],
        'code' => $values['code'],
      ]);
    }
    elseif (!empty($values['city'])) {
      $url = Url::fromRoute('weather.city_page', [
        'city' => $values['city'],
      ]);
    }
    else {
      $url = Url::fromRoute('weather.page');
    }
    $form_state->setRedirectUrl($url);
  }

}

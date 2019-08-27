<?php

namespace Drupal\nbg_currency\Plugin\Block;

use ABGEO\NBG\Currency;
use ABGEO\NBG\Exception\InvalidCurrencyException;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use ReflectionClass;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a 'NBGCurrency' Block.
 *
 * @Block(
 *   id = "nbg_currency",
 *   admin_label = @Translation("NBG Currency"),
 * )
 */
class NBGCurrencyBlock extends BlockBase implements ContainerFactoryPluginInterface {
  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  private $loggerFactory;

  /**
   * Drupal HTTP Client.
   *
   * @var GuzzleHttp\Client
   */
  private $client;

  /**
   * Cache backend.
   *
   * @var Drupal\Core\Cache\CacheBackendInterface
   */
  private $cacheBackend;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('logger.factory'),
      $container->get('http_client'),
      $container->get('cache.default')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    LoggerChannelFactoryInterface $logger_factory,
    Client $client,
    CacheBackendInterface $cache
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);

    $this->loggerFactory = $logger_factory;
    $this->client = $client;
    $this->cacheBackend = $cache;
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    $config = $this->getConfiguration();

    // Get codes state from config.
    $currency_codes = $config['nbg_currency_currencies'];
    // Filter only selected codes.
    $currency_codes = array_filter($currency_codes, function ($value, $key) {
      return $value !== 0;
    }, ARRAY_FILTER_USE_BOTH);

    // Get data for given currency codes.
    $currency_data = $this->getCurrencyData($currency_codes);

    return [
      '#theme' => 'nbg_currency',
      '#attached' => [
        'library' => [
          'nbg_currency/nbg-currency',
        ],
      ],
      '#currency_data' => $currency_data,
      '#cache' => [
        'max-age' => $config['nbg_currency_cache'],
      ],
    ];
  }

  /**
   * Get Currency Names from cache or openexchangerates API.
   *
   * @return array
   *   Currency names.
   */
  private function getCurrencyNames() {
    $cache = $this->cacheBackend;
    $cid = 'nbg_currency.currency_names';

    // Get data from cache if exists.
    if ($cached_names = $cache->get($cid)) {
      return $cached_names->data;
    }

    $cached_names = [];
    try {
      // Get Currency names from openexchangerates API.
      $response = $this->client
        ->get('https://openexchangerates.org/api/currencies.json');

      // Decode from JSON.
      $cached_names = Json::decode($response->getBody());
    }
    catch (RequestException $e) {
      $this->loggerFactory
        ->get('nbg_currency')
        ->error($e);
    }

    // Save data in cache.
    $cache->set($cid, $cached_names);

    return $cached_names;
  }

  /**
   * Get currency data from cache or API.
   *
   * @param $currency_codes
   *   Currency codes.
   *
   * @return array
   *   Cached currency data.
   */
  public function getCurrencyData($currency_codes) {
    $cache = $this->cacheBackend;
    $currency_names = $this->getCurrencyNames();
    $config = $this->getConfiguration();
    $cid = 'nbg_currency.currency_data';

    // Get data from cache if exists.
    if ($currency_data = $cache->get($cid)) {
      return $currency_data->data;
    }

    $currency_data = [];
    foreach ($currency_codes as $k => $v) {
      try {
        // Create new Currency class for given code.
        $currency = new Currency($k);

        // Get current currency data from class.
        $currency_data[$k] = [
          'title' => isset($currency_names[$k]) ?
            $currency_names[$k] . ' (' . $k . ')' : $k,
          'description' => $currency->getDescription(),
          'currency' => $currency->getCurrency(),
          'rate' => $currency->getRate(),
          'change' => round($currency->getChange(), 4),
        ];
      }
      catch (InvalidCurrencyException $e) {
        $this->loggerFactory
          ->get('nbg_currency')
          ->error($e);
      }
      catch (\SoapFault $e) {
        $this->loggerFactory
          ->get('nbg_currency')
          ->error($e);
      }
    }

    // Cache data.
    $cache->set(
      $cid,
      $currency_data,
      time() + $config['nbg_currency_cache']
    );

    return $currency_data;
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state) {
    $form = parent::blockForm($form, $form_state);

    $config = $this->getConfiguration();
    $currency_names = $this->getCurrencyNames();

    // Get constants from Currency class.
    $o_class = new ReflectionClass(Currency::class);
    $currency_constants = $o_class->getConstants();

    // Add Currency Code Checkbox options.
    $currencies = [];
    foreach ($currency_constants as $k => $currency) {
      if (strpos($k, 'CURRENCY_') === 0) {
        $currencies[$currency] = isset($currency_names[$currency]) ?
          $currency_names[$currency] . ' (' . $currency . ')' : $currency;
      }
    }

    // Cache selector.
    $form['nbg_currency_cache'] = [
      '#type' => 'select',
      '#title' => $this->t('Cache'),
      '#options' => [
        0 => $this->t('No Cache'),
        1800 => $this->t('30 min'),
        3600 => $this->t('1 hour'),
        86400 => $this->t('One day'),
      ],
      '#default_value' => $config['nbg_currency_cache'] ?? NULL,
      '#description' => $this->t('Time for cache the currency.'),
    ];

    // Container for filtering currencies.
    $form['nbg_currency'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['currency-filter'],
      ],
    ];

    // Currency filter.
    $form['nbg_currency']['nbg_currency_filter'] = [
      '#type' => 'search',
      '#title' => $this->t('Filter currency'),
      '#size' => 30,
      '#placeholder' => $this->t('Filter by name'),
      '#description' => $this->t('Enter part of a currency'),
      '#attributes' => [
        'class' => ['currency-filter-search'],
      ],
    ];

    // Create checkboxes group for Currency Codes.
    $form['nbg_currency_currencies'] = [
      '#type' => 'checkboxes',
      '#options' => $currencies,
      '#title' => $this->t('Currency Codes'),
      '#description' => $this->t('Select Currency Codes for displaying in block.'),
      '#default_value' => $config['nbg_currency_currencies'] ?? [],
      '#attributes' => [
        'class' => ['nbg-currency'],
      ],
    ];

    // Attach block settings library.
    $form['#attached']['library'][] = 'nbg_currency/nbg-currency.settings';

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state) {
    parent::blockSubmit($form, $form_state);
    $values = $form_state->getValues();
    $cache = $this->cacheBackend;

    $this->configuration['nbg_currency_currencies'] = $values['nbg_currency_currencies'];
    $this->configuration['nbg_currency_cache'] = $values['nbg_currency_cache'];

    // Delete cached currency data.
    $cache->delete('nbg_currency.currency_data');
  }

}

<?php

namespace Drupal\nbg_currency\Plugin\Block;

use ABGEO\NBG\Currency;
use ABGEO\NBG\Exception\InvalidCurrencyException;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Form\FormStateInterface;
use ReflectionClass;

/**
 * Provides a 'NBGCurrency' Block.
 *
 * @Block(
 *   id = "nbg_currency",
 *   admin_label = @Translation("NBG Currency"),
 * )
 */
class NBGCurrencyBlock extends BlockBase
{
  /**
   * {@inheritdoc}
   */
  public function build()
  {
    $currency_codes = $this->configuration['nbg_currency_currencies'];
    $currency_codes = array_filter($currency_codes, function ($value, $key) {
      return $value !== 0;
    }, ARRAY_FILTER_USE_BOTH);

    $currency_data = array();
    foreach ($currency_codes as $k => $v) {
      try {
        $currency = new Currency($k);

        $currency_data[$k] = [
          'currency' => $currency->getCurrency(),
          'rate' => $currency->getRate(),
          'change' => round($currency->getChange(), 4),
        ];
      } catch (InvalidCurrencyException $e) {
        \Drupal::logger('nbg_currency')->error($e->getMessage());
      } catch (\SoapFault $e) {
        \Drupal::logger('nbg_currency')->error($e->getMessage());
      }
    }

    return [
      '#theme' => 'nbg_currency',
      '#currency_data' => $currency_data,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state)
  {
    $form = parent::blockForm($form, $form_state);

    $config = $this->getConfiguration();

    // Get Currency names from openexchangerates API.
    $curl_options = array(
      CURLOPT_HEADER => false,
      CURLOPT_URL => 'https://openexchangerates.org/api/currencies.json',
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_SSL_VERIFYPEER => false
    );
    $ch = curl_init();
    curl_setopt_array($ch, $curl_options);
    $response = curl_exec($ch);
    curl_close($ch);

    $currency_names = Json::decode($response);

    // Get constants from Currency class.
    $o_class = new ReflectionClass(Currency::class);
    $NBG_currency_constants = $o_class->getConstants();

    // Add Currency Code Checkbox options.
    $currencies = [];
    foreach ($NBG_currency_constants as $k => $currency) {
      if (strpos($k, 'CURRENCY_') === 0) {
        $currencies[$currency] = isset($currency_names[$currency]) ?
          $currency_names[$currency] . ' (' . $currency . ')' : $currency;
      }
    }

    $form['nbg_currency_currencies'] = [
      '#type' => 'checkboxes',
      '#options' => $currencies,
      '#title' => $this->t('Currency Codes'),
      '#description' => $this->t('Select Currency Codes for displaying in block.'),
      '#default_value' => $config['nbg_currency_currencies'] ?? '',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state)
  {
    parent::blockSubmit($form, $form_state);
    $values = $form_state->getValues();
    $this->configuration['nbg_currency_currencies'] = $values['nbg_currency_currencies'];
  }
}
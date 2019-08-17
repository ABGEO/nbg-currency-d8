<?php

namespace Drupal\nbg_currency\Plugin\Block;

use Drupal\Core\Block\BlockBase;

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
  public function build() {
    $currency_data = array();

    return [
      '#theme' => 'nbg_currency',
      '#currency_data' => $currency_data,
    ];
  }
}
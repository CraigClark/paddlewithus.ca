<?php

namespace Drupal\picc_ext\Plugin\views\field;

use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\picc_discount\SaleManager;
use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Displays a sale badge when any session of a program is on sale.
 *
 * Rolls a product's variations up to a single badge using the shared
 * SaleManager, so the card, the discount and the price always agree.
 *
 * @ViewsField("picc_sale_badge")
 */
class SaleBadge extends FieldPluginBase {

  /**
   * The sale manager.
   *
   * @var \Drupal\picc_discount\SaleManager
   */
  protected SaleManager $saleManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->saleManager = $container->get('picc_discount.sale_manager');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function query() {
    // No query changes; the product is loaded from the row in render().
  }

  /**
   * {@inheritdoc}
   */
  public function render(ResultRow $values) {
    $product = $this->getEntity($values);
    if (!$product instanceof ProductInterface) {
      return '';
    }

    $sale = $this->saleManager->getProductSale($product);
    if (!$sale) {
      return '';
    }

    $ends_label = '';
    if (!empty($sale['ends'])) {
      $ends_label = $this->t('Sale ends @date', [
        '@date' => $this->saleManager->formatSaleEndDate($sale['ends']),
      ]);
    }

    return [
      '#type' => 'inline_template',
      '#template' => '<div class="picc-sale mb-2"><span class="badge badge-error badge-lg font-semibold">{{ off }}</span><div class="text-sm mt-1">{% if label %}<strong>{{ label }}</strong>{% if ends_label %} · {% endif %}{% endif %}{{ ends_label }}</div></div>',
      '#context' => [
        'off' => $this->t('@percent% off', ['@percent' => $sale['percent']]),
        'label' => $sale['label'],
        'ends_label' => $ends_label,
      ],
      // Rebuild the card when the sale campaign changes (percent, end date,
      // name); variation changes already invalidate the product cache tag. The
      // max-age expires the badge exactly when the sale ends.
      '#cache' => [
        'tags' => $sale['term']->getCacheTags(),
        'max-age' => $this->saleManager->secondsUntilSaleEnd($sale['term']),
      ],
    ];
  }

}

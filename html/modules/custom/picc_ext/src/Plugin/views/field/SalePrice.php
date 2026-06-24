<?php

namespace Drupal\picc_ext\Plugin\views\field;

use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\picc_discount\SaleManager;
use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Shows the sale price and a short explanation of the sale for a session.
 *
 * Renders the original price struck through, the discounted price in red, and
 * a note with the percentage off, the campaign name, and the end date — so a
 * visitor who lands directly on the product page understands why the price is
 * discounted. Renders nothing when the variation is not on sale, so the row
 * template falls back to the normal price.
 *
 * Carries the sale term's cache tags and a max-age that expires when the sale
 * ends, so the display can never show a stale sale after the discount has
 * stopped applying at checkout.
 *
 * @ViewsField("picc_sale_price")
 */
class SalePrice extends FieldPluginBase {

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
    // No query changes; the variation is loaded from the row in render().
  }

  /**
   * {@inheritdoc}
   */
  public function render(ResultRow $values) {
    $variation = $this->getEntity($values);
    if (!$variation instanceof ProductVariationInterface) {
      return '';
    }

    $sale = $this->saleManager->getVariationSaleDisplay($variation);
    $price = $variation->getPrice();
    if (!$sale || !$price) {
      return '';
    }

    // Display rounding only; Commerce computes the charged amount precisely in
    // the Sale promotion offer. The "$" format matches the existing price
    // display in this view.
    $sale_number = (float) $price->getNumber() * (100 - $sale['percent']) / 100;

    return [
      '#type' => 'inline_template',
      '#template' => '<span class="line-through opacity-70">{{ original }}</span> <span class="text-error font-semibold">{{ sale }}</span> {{ suffix }}<div class="text-sm mt-1"><span class="badge badge-error">{{ off }}</span>{% if label %} {{ label }}{% endif %}{% if ends %} · {{ ends }}{% endif %}</div>',
      '#context' => [
        'original' => '$' . number_format((float) $price->getNumber(), 2),
        'sale' => '$' . number_format($sale_number, 2),
        'suffix' => $this->t('per person.'),
        'off' => $this->t('@percent% off', ['@percent' => $sale['percent']]),
        'label' => $sale['label'],
        'ends' => $sale['ends_formatted'] ? $this->t('Sale ends @date', ['@date' => $sale['ends_formatted']]) : '',
      ],
      '#cache' => [
        'tags' => $sale['term']->getCacheTags(),
        'max-age' => $this->saleManager->secondsUntilSaleEnd($sale['term']),
      ],
    ];
  }

}

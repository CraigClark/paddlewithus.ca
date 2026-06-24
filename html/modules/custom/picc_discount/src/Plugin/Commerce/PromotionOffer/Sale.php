<?php

namespace Drupal\picc_discount\Plugin\Commerce\PromotionOffer;

use Drupal\commerce_order\Adjustment;
use Drupal\commerce_promotion\Entity\PromotionInterface;
use Drupal\commerce_promotion\Plugin\Commerce\PromotionOffer\PromotionOfferBase;
use Drupal\Core\Entity\EntityInterface;
use Drupal\picc_discount\SaleManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Discounts sessions that are on sale, by the percentage on their sale type.
 *
 * The percentage and end date come from the sale_type taxonomy term referenced
 * by the variation's field_sale_type, via the shared SaleManager. The term is
 * the single source of truth, so this discount always matches the badge and
 * struck-through price shown on the front end.
 *
 * @CommercePromotionOffer(
 *   id = "picc_sale",
 *   label = @Translation("PICC Sale (per-session, from sale type)"),
 *   entity_type = "commerce_order",
 * )
 */
class Sale extends PromotionOfferBase {

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
  public function apply(EntityInterface $entity, PromotionInterface $promotion) {
    $this->assertEntity($entity);
    /** @var \Drupal\commerce_order\Entity\OrderInterface $order */
    $order = $entity;

    foreach ($order->getItems() as $order_item) {
      $variation = $order_item->getPurchasedEntity();
      if (!$variation) {
        continue;
      }

      $term = $this->saleManager->getVariationSale($variation);
      if (!$term) {
        continue;
      }

      $unit_price = $order_item->getUnitPrice();
      if (!$unit_price) {
        continue;
      }

      // Convert the percentage to a decimal (50% => 0.50). Registrations are
      // one participant per order item, so discounting the unit price is the
      // whole-line discount, matching the family discount offer.
      $decimal = (string) ($this->saleManager->getPercent($term) / 100);
      $amount = $unit_price->multiply($decimal);

      $order_item->addAdjustment(new Adjustment([
        'type' => 'promotion',
        // The sale's name (e.g. "Summer sale"), shown on the order.
        'label' => $term->label(),
        'amount' => $amount->multiply('-1'),
        'source_id' => $promotion->id(),
        'percentage' => $decimal,
      ]));
    }
  }

}

<?php

namespace Drupal\picc_discount\Plugin\Commerce\PromotionOffer;

use Drupal\commerce_order\Adjustment;
use Drupal\commerce_promotion\Entity\PromotionInterface;
use Drupal\commerce_promotion\Plugin\Commerce\PromotionOffer\PromotionOfferBase;
use Drupal\Core\Entity\EntityInterface;

/**
 * Provides a graduated family discount based on participant count per variant.
 *
 * @CommercePromotionOffer(
 *   id = "picc_family_discount",
 *   label = @Translation("PICC Family Discount (Graduated)"),
 *   entity_type = "commerce_order",
 * )
 */
class FamilyDiscount extends PromotionOfferBase {

  /**
   * {@inheritdoc}
   */
  public function apply(EntityInterface $entity, PromotionInterface $promotion) {
    $this->assertEntity($entity);
    /** @var \Drupal\commerce_order\Entity\OrderInterface $order */
    $order = $entity;

    // Group order items by variation ID.
    $variation_groups = [];
    foreach ($order->getItems() as $order_item) {
      $purchased_entity = $order_item->getPurchasedEntity();
      if (!$purchased_entity) {
        continue;
      }
      
      $variation_id = $purchased_entity->id();
      $variation_groups[$variation_id][] = $order_item;
    }

    // Process each variation group.
    foreach ($variation_groups as $variation_id => $order_items) {
      $this->applyVariationGroupDiscount($order_items, $promotion);
    }
  }

  /**
   * Apply graduated discount to a group of order items for the same variation.
   *
   * @param array $order_items
   *   Array of order items for the same variation.
   * @param \Drupal\commerce_promotion\Entity\PromotionInterface $promotion
   *   The promotion entity.
   */
  protected function applyVariationGroupDiscount(array $order_items, PromotionInterface $promotion) {
    if (empty($order_items)) {
      return;
    }

    // Get the variation from the first order item.
    $first_item = reset($order_items);
    $variation = $first_item->getPurchasedEntity();
    if (!$variation) {
      return;
    }

    // Get discount values from variation fields.
    $discount_1 = $this->getFieldValue($variation, 'field_discount_1');
    $discount_2 = $this->getFieldValue($variation, 'field_discount_2');
    $discount_3 = $this->getFieldValue($variation, 'field_discount_3');
    $discount_4 = $this->getFieldValue($variation, 'field_discount_4');
    $discount_5_up = $this->getFieldValue($variation, 'field_discount_5_up');

    // If all discounts are 0, skip this variation.
    if ($discount_1 == 0 && $discount_2 == 0 && $discount_3 == 0 && 
        $discount_4 == 0 && $discount_5_up == 0) {
      return;
    }

    // Build discount map.
    $discount_map = [
      1 => $discount_1,
      2 => $discount_2,
      3 => $discount_3,
      4 => $discount_4,
    ];

    // Apply discount to each order item based on its position.
    $count = count($order_items);
    $position = 1;
    
    foreach ($order_items as $order_item) {
      // Determine discount percentage for this position.
      if ($position <= 4) {
        $discount_percentage = $discount_map[$position];
      } else {
        // Position 5 and up.
        $discount_percentage = $discount_5_up;
      }

      // Skip if no discount for this position.
      if ($discount_percentage == 0) {
        $position++;
        continue;
      }

      // Calculate discount amount.
      $unit_price = $order_item->getUnitPrice();
      if (!$unit_price) {
        $position++;
        continue;
      }

      // Convert percentage to decimal (25% = 0.25).
      $discount_decimal = (string)($discount_percentage / 100);
      $discount_amount = $unit_price->multiply($discount_decimal);

      // Add adjustment to order item.
      $order_item->addAdjustment(new Adjustment([
        'type' => 'promotion',
        'label' => $this->t('Family Discount'),
        'amount' => $discount_amount->multiply('-1'),
        'source_id' => $promotion->id(),
        'percentage' => $discount_decimal,
      ]));

      $position++;
    }
  }

  /**
   * Get field value from variation, returning 0 if field doesn't exist.
   *
   * @param \Drupal\Core\Entity\EntityInterface $variation
   *   The variation entity.
   * @param string $field_name
   *   The field name.
   *
   * @return int
   *   The field value or 0 if field doesn't exist.
   */
  protected function getFieldValue(EntityInterface $variation, $field_name) {
    if (!$variation->hasField($field_name)) {
      return 0;
    }

    $field = $variation->get($field_name);
    if ($field->isEmpty()) {
      return 0;
    }

    return (int) $field->value;
  }

}

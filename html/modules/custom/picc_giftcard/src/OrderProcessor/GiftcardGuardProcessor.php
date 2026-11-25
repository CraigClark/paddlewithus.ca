<?php

namespace Drupal\picc_giftcard\OrderProcessor;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\OrderProcessorInterface;
use Drupal\picc_giftcard\GiftcardOrderHelper;

/**
 * Safety checks that run just before commerce_giftcard applies gift cards.
 *
 * - Removes disabled gift cards from the order. commerce_giftcard's own
 *   processor doesn't check the status (issue 3620707).
 * - Gift card lines never get promotion discounts, so a $250 card always
 *   costs $250.
 *
 * Gift cards can't pay for gift card lines. That cap is applied after
 * commerce_giftcard, by GiftcardCapProcessor.
 */
class GiftcardGuardProcessor implements OrderProcessorInterface {

  /**
   * {@inheritdoc}
   */
  public function process(OrderInterface $order): void {
    $buys_giftcard = GiftcardOrderHelper::hasGiftcardPurchase($order);

    if ($buys_giftcard) {
      foreach ($order->getItems() as $item) {
        if ($item->bundle() !== GiftcardOrderHelper::ORDER_ITEM_TYPE) {
          continue;
        }
        $kept = [];
        $removed = FALSE;
        foreach ($item->getAdjustments() as $adjustment) {
          if ($adjustment->getType() !== 'promotion') {
            $kept[] = $adjustment;
            continue;
          }
          $removed = TRUE;
          // "Display inclusive" promotions also lower the unit price. Put
          // the discount back on the unit price before dropping it.
          if ($adjustment->isIncluded() && $item->getUnitPrice() && (float) $item->getQuantity() > 0) {
            $per_unit = $adjustment->getAmount()->divide((string) $item->getQuantity());
            $item->setUnitPrice($item->getUnitPrice()->subtract($per_unit), $item->isUnitPriceOverridden());
          }
        }
        if ($removed) {
          $item->setAdjustments($kept);
        }
      }
    }

    if (!$order->hasField('commerce_giftcards') || $order->get('commerce_giftcards')->isEmpty()) {
      return;
    }

    $field = $order->get('commerce_giftcards');
    // Walk backwards so removing an item doesn't shift the ones still to check.
    for ($delta = $field->count() - 1; $delta >= 0; $delta--) {
      $giftcard = $field->get($delta)->entity;
      if (!$giftcard || !$giftcard->isEnabled()) {
        $field->removeItem($delta);
      }
    }
  }

}

<?php

namespace Drupal\picc_giftcard;

use Drupal\commerce_order\Entity\OrderInterface;

/**
 * Small helpers shared by hooks, the order processor and the subscriber.
 */
class GiftcardOrderHelper {

  /**
   * The order item type used for gift card purchases.
   */
  const ORDER_ITEM_TYPE = 'giftcard_purchase';

  /**
   * Whether the order has at least one gift card purchase line.
   */
  public static function hasGiftcardPurchase(?OrderInterface $order): bool {
    if (!$order) {
      return FALSE;
    }
    foreach ($order->getItems() as $item) {
      if ($item->bundle() === static::ORDER_ITEM_TYPE) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Whether every line on the order is a gift card purchase.
   */
  public static function onlyGiftcardPurchases(?OrderInterface $order): bool {
    if (!$order || !$order->hasItems()) {
      return FALSE;
    }
    foreach ($order->getItems() as $item) {
      if ($item->bundle() !== static::ORDER_ITEM_TYPE) {
        return FALSE;
      }
    }
    return TRUE;
  }

}

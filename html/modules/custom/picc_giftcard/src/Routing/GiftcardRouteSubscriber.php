<?php

namespace Drupal\picc_giftcard\Routing;

use Drupal\Core\Routing\RouteSubscriberBase;
use Symfony\Component\Routing\RouteCollection;

/**
 * Turns off commerce_giftcard's "Refund gift card" page on orders.
 *
 * It raises the order's total (a paid order then shows money owing) and its
 * transaction records no order and no reason (contrib issue 3620716).
 * Refunds to a card are made with Add transaction instead, which records
 * who, when and why.
 */
class GiftcardRouteSubscriber extends RouteSubscriberBase {

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection): void {
    if ($route = $collection->get('commerce_giftcard.refund_form')) {
      $route->setRequirements(['_access' => 'FALSE']);
    }
  }

}

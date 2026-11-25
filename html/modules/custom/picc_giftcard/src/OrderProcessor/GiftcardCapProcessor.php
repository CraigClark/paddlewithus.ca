<?php

namespace Drupal\picc_giftcard\OrderProcessor;

use Drupal\commerce_order\Adjustment;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\OrderProcessorInterface;
use Drupal\commerce_price\Price;
use Drupal\picc_giftcard\GiftcardOrderHelper;

/**
 * Gift cards can pay for everything on an order except gift card lines.
 *
 * Runs after commerce_giftcard's processor. If an order mixes a program and
 * a gift card purchase, the applied cards cover the program and the gift
 * card lines are left to pay by credit card. Adjustments are trimmed in the
 * order the cards were applied.
 */
class GiftcardCapProcessor implements OrderProcessorInterface {

  /**
   * {@inheritdoc}
   */
  public function process(OrderInterface $order): void {
    $giftcard_lines = GiftcardOrderHelper::giftcardLinesTotal($order);
    if (!$giftcard_lines || !$giftcard_lines->isPositive()) {
      return;
    }
    $adjustments = $order->getAdjustments();
    $covered = NULL;
    foreach ($adjustments as $adjustment) {
      if ($adjustment->getType() === 'commerce_giftcard') {
        $amount = $adjustment->getAmount()->multiply('-1');
        $covered = $covered ? $covered->add($amount) : $amount;
      }
    }
    if (!$covered) {
      return;
    }

    // What the order costs before gift cards, minus the gift card lines.
    $total_before_cards = $order->getTotalPrice()->add($covered);
    $allowed = $total_before_cards->subtract($giftcard_lines);
    if ($allowed->isNegative()) {
      $allowed = new Price('0', $allowed->getCurrencyCode());
    }
    if (!$covered->greaterThan($allowed)) {
      return;
    }

    $left = $allowed;
    $kept = [];
    foreach ($adjustments as $adjustment) {
      if ($adjustment->getType() !== 'commerce_giftcard') {
        $kept[] = $adjustment;
        continue;
      }
      $amount = $adjustment->getAmount()->multiply('-1');
      $use = $amount->greaterThan($left) ? $left : $amount;
      if ($use->isPositive()) {
        $values = $adjustment->toArray();
        $values['amount'] = $use->multiply('-1');
        $kept[] = new Adjustment($values);
        $left = $left->subtract($use);
      }
    }
    $order->setAdjustments($kept);
  }

}

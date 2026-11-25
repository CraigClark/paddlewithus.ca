<?php

namespace Drupal\picc_giftcard;

use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Finds the gift cards that belong to a person.
 *
 * Two kinds:
 * - Bought: cards the person paid for (they own them). Usually gifts for
 *   someone else, so they're never offered at checkout.
 * - Used: cards the person has redeemed on at least one of their own orders.
 *   These are "theirs" for checking a balance and using the rest.
 */
class GiftcardLookup {

  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Cards the user bought, newest first.
   *
   * @return \Drupal\commerce_giftcard\Entity\GiftcardInterface[]
   *   The gift cards.
   */
  public function boughtBy(int $uid): array {
    if (!$uid) {
      return [];
    }
    $storage = $this->entityTypeManager->getStorage('commerce_giftcard');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', $uid)
      ->sort('id', 'DESC')
      ->execute();
    return $ids ? array_values($storage->loadMultiple($ids)) : [];
  }

  /**
   * Cards the user has redeemed on one of their placed orders.
   *
   * @return \Drupal\commerce_giftcard\Entity\GiftcardInterface[]
   *   The gift cards, keyed by ID.
   */
  public function usedBy(int $uid): array {
    if (!$uid) {
      return [];
    }
    $order_ids = $this->entityTypeManager->getStorage('commerce_order')->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', $uid)
      ->condition('state', 'draft', '<>')
      ->execute();
    if (!$order_ids) {
      return [];
    }
    $transaction_storage = $this->entityTypeManager->getStorage('commerce_giftcard_transaction');
    $transaction_ids = $transaction_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('reference_type', 'commerce_order')
      ->condition('reference_id', array_values($order_ids), 'IN')
      ->execute();
    $cards = [];
    foreach ($transaction_storage->loadMultiple($transaction_ids) as $transaction) {
      if ($card = $transaction->getGiftCard()) {
        $cards[$card->id()] = $card;
      }
    }
    return $cards;
  }

  /**
   * Used cards that still have money on them and are enabled.
   *
   * @return \Drupal\commerce_giftcard\Entity\GiftcardInterface[]
   *   The gift cards, keyed by ID.
   */
  public function usableBy(int $uid): array {
    return array_filter($this->usedBy($uid), function ($card) {
      $balance = $card->getBalance();
      return $card->isEnabled() && $balance && $balance->isPositive();
    });
  }

}

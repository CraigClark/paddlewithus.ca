<?php

namespace Drupal\picc_giftcard\EventSubscriber;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\picc_giftcard\GiftcardMailer;
use Drupal\picc_giftcard\GiftcardOrderHelper;
use Drupal\state_machine\Event\WorkflowTransitionEvent;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * After an order is placed, finishes the gift cards it bought.
 *
 * commerce_giftcard creates the cards in place.pre_transition. This runs in
 * place.post_transition, so the cards exist. It copies To, From and the
 * message from each order item onto its cards, then emails the buyer.
 */
class GiftcardOrderSubscriber implements EventSubscriberInterface {

  /**
   * Fields copied from the order item to the gift card.
   */
  const COPIED_FIELDS = ['field_recipient_name', 'field_from_name', 'field_giftcard_message'];

  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly GiftcardMailer $mailer,
    protected readonly LoggerInterface $logger,
    protected readonly LanguageManagerInterface $languageManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      'commerce_order.place.post_transition' => ['onPlace', -100],
      'commerce_order.cancel.post_transition' => ['onCancel', -100],
    ];
  }

  /**
   * Copies recipient fields and sends the gift card email.
   */
  public function onPlace(WorkflowTransitionEvent $event): void {
    /** @var \Drupal\commerce_order\Entity\OrderInterface $order */
    $order = $event->getEntity();
    $this->renameUsageNotes($order);
    if (!GiftcardOrderHelper::hasGiftcardPurchase($order)) {
      return;
    }

    $giftcards = [];
    foreach ($order->getItems() as $item) {
      if ($item->bundle() !== GiftcardOrderHelper::ORDER_ITEM_TYPE) {
        continue;
      }
      foreach ($this->loadGiftcardsForItem((int) $item->id()) as $giftcard) {
        $changed = FALSE;
        foreach (static::COPIED_FIELDS as $field) {
          if ($item->hasField($field) && $giftcard->hasField($field) && $giftcard->get($field)->isEmpty() && !$item->get($field)->isEmpty()) {
            $giftcard->set($field, $item->get($field)->value);
            $changed = TRUE;
          }
        }
        if ($changed) {
          $giftcard->save();
        }
        $giftcards[] = $giftcard;
      }
    }

    // Never let an email problem stop an order from being placed. The cards
    // already exist, and an admin can resend from the gift card's page.
    try {
      // The email and PDF lead with the language the order was placed in.
      $this->mailer->sendForOrder($order, $giftcards, $this->languageManager->getCurrentLanguage()->getId());
    }
    catch (\Throwable $e) {
      $this->logger->error('Gift card email for order @id failed: @message', ['@id' => $order->id(), '@message' => $e->getMessage()]);
    }
  }

  /**
   * Loads the gift cards created for an order item, oldest first.
   *
   * commerce_giftcard links each new card to its order item through the
   * card's first transaction.
   *
   * @return \Drupal\commerce_giftcard\Entity\GiftcardInterface[]
   *   The gift cards.
   */
  protected function loadGiftcardsForItem(int $item_id): array {
    $transaction_storage = $this->entityTypeManager->getStorage('commerce_giftcard_transaction');
    $ids = $transaction_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('reference_type', 'commerce_order_item')
      ->condition('reference_id', $item_id)
      ->sort('id')
      ->execute();
    $giftcards = [];
    foreach ($transaction_storage->loadMultiple($ids) as $transaction) {
      $giftcard = $transaction->getGiftCard();
      if ($giftcard) {
        $giftcards[$giftcard->id()] = $giftcard;
      }
    }
    return array_values($giftcards);
  }

  /**
   * Puts gift card money back on the cards when an order is cancelled.
   *
   * Adds a transaction per card for what's still taken off by this order,
   * linked to the order. Safe to run twice: it only returns what the
   * order's transactions haven't already returned.
   */
  public function onCancel(WorkflowTransitionEvent $event): void {
    /** @var \Drupal\commerce_order\Entity\OrderInterface $order */
    $order = $event->getEntity();
    $storage = $this->entityTypeManager->getStorage('commerce_giftcard_transaction');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('reference_type', 'commerce_order')
      ->condition('reference_id', $order->id())
      ->execute();
    $net = [];
    foreach ($storage->loadMultiple($ids) as $transaction) {
      $card_id = $transaction->getGiftcardId();
      $amount = $transaction->getAmount();
      if ($card_id && $amount) {
        $net[$card_id] = isset($net[$card_id]) ? $net[$card_id]->add($amount) : $amount;
      }
    }
    $number = $order->getOrderNumber() ?: $order->id();
    $this->turnOffCardsBought($order, (string) $number);
    foreach ($net as $card_id => $amount) {
      if (!$amount->isNegative()) {
        continue;
      }
      try {
        $storage->create([
          'giftcard' => $card_id,
          'amount' => $amount->multiply('-1'),
          'reference_type' => 'commerce_order',
          'reference_id' => $order->id(),
          'comment' => 'Returned: order ' . $number . ' cancelled.',
        ])->save();
      }
      catch (\Throwable $e) {
        $this->logger->error('Could not return gift card money for cancelled order @number: @message', ['@number' => $number, '@message' => $e->getMessage()]);
      }
    }
  }

  /**
   * Rewrites "Used on order with id 458." to "Used on order 390."
   *
   * commerce_giftcard writes the note before the order has its number, so
   * this runs after the order is placed.
   */
  protected function renameUsageNotes($order): void {
    $number = $order->getOrderNumber();
    if (!$number) {
      return;
    }
    $storage = $this->entityTypeManager->getStorage('commerce_giftcard_transaction');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('reference_type', 'commerce_order')
      ->condition('reference_id', $order->id())
      ->execute();
    foreach ($storage->loadMultiple($ids) as $transaction) {
      $comment = (string) $transaction->getComment();
      if (str_starts_with($comment, 'Used on order') && $comment !== 'Used on order ' . $number . '.') {
        $transaction->set('comment', 'Used on order ' . $number . '.')->save();
      }
    }
  }

  /**
   * Turns off cards that a cancelled order bought.
   *
   * Otherwise the buyer could be refunded and still spend the card. If a
   * card was already partly used, it's still turned off, and the note says
   * how much is left so an admin can decide what to do.
   */
  protected function turnOffCardsBought($order, string $number): void {
    $item_ids = array_map(fn($item) => $item->id(), array_filter($order->getItems(), fn($item) => $item->bundle() === GiftcardOrderHelper::ORDER_ITEM_TYPE));
    if (!$item_ids) {
      return;
    }
    $storage = $this->entityTypeManager->getStorage('commerce_giftcard_transaction');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('reference_type', 'commerce_order_item')
      ->condition('reference_id', array_values($item_ids), 'IN')
      ->execute();
    foreach ($storage->loadMultiple($ids) as $transaction) {
      $card = $transaction->getGiftCard();
      if (!$card || !$card->isEnabled()) {
        continue;
      }
      // This note replaces the generic "Card turned off." history line.
      $skip = &drupal_static('picc_giftcard_skip_change_log', []);
      $skip[$card->id()] = TRUE;
      $card->setStatus(FALSE)->save();
      unset($skip[$card->id()]);
      $balance = $card->getBalance();
      $storage->create([
        'giftcard' => $card->id(),
        'amount' => new \Drupal\commerce_price\Price('0', $balance->getCurrencyCode()),
        'reference_type' => 'commerce_order',
        'reference_id' => $order->id(),
        'comment' => 'Order ' . $number . ' that bought this card was cancelled. Card turned off with $' . number_format((float) $balance->getNumber(), 2) . ' on it.',
      ])->save();
    }
  }

}

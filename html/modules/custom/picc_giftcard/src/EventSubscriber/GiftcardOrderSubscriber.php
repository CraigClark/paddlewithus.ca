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
    ];
  }

  /**
   * Copies recipient fields and sends the gift card email.
   */
  public function onPlace(WorkflowTransitionEvent $event): void {
    /** @var \Drupal\commerce_order\Entity\OrderInterface $order */
    $order = $event->getEntity();
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

}

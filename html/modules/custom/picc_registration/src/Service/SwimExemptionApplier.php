<?php

declare(strict_types=1);

namespace Drupal\picc_registration\Service;

use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Applies swim test exemptions based on the program's no-swim-test flag.
 *
 * Programs (commerce_product of type activity) can be flagged with
 * field_no_swim_test = TRUE to indicate that participants registered to
 * the program don't need a swim test. When applied, this sets
 * field_swim_status = 'exempt' on the participant profile, but only if
 * the participant's current status is 'none' (still pending). Already
 * evaluated statuses (passed, failed, attested) are never overwritten.
 */
final class SwimExemptionApplier {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Apply exemption to the participant referenced by one order item.
   *
   * @return bool
   *   TRUE if the participant was newly marked exempt, FALSE if no change
   *   was needed (program not flagged, status already set, etc.).
   */
  public function applyToOrderItem(OrderItemInterface $order_item): bool {
    $variation = $order_item->getPurchasedEntity();
    if (!$variation || !$variation->hasField('product_id')) {
      return FALSE;
    }

    $product = $variation->getProduct();
    if (!$product || !$product->hasField('field_no_swim_test')) {
      return FALSE;
    }

    if (empty($product->get('field_no_swim_test')->value)) {
      return FALSE;
    }

    if (!$order_item->hasField('field_participant')) {
      return FALSE;
    }
    $participant_id = $order_item->get('field_participant')->target_id;
    if (!$participant_id) {
      return FALSE;
    }

    $profile = $this->entityTypeManager->getStorage('profile')->load($participant_id);
    if (!$profile || $profile->bundle() !== 'participant') {
      return FALSE;
    }

    if (!$profile->hasField('field_swim_status')) {
      return FALSE;
    }

    $current = $profile->get('field_swim_status')->value;
    if ($current !== 'none') {
      return FALSE;
    }

    $profile->set('field_swim_status', 'exempt');
    $profile->save();

    $this->logger->notice('Marked participant @pid exempt from swim test (registered to @program).', [
      '@pid' => $participant_id,
      '@program' => $product->label(),
    ]);

    return TRUE;
  }

  /**
   * Sweep all activity_registration order items and apply exemptions.
   *
   * @return array
   *   Stats: ['scanned' => int, 'updated' => int, 'programs' => int].
   */
  public function applyToAll(): array {
    $product_storage = $this->entityTypeManager->getStorage('commerce_product');
    $flagged_ids = $product_storage->getQuery()
      ->condition('type', 'activity')
      ->condition('field_no_swim_test', 1)
      ->accessCheck(FALSE)
      ->execute();

    if (empty($flagged_ids)) {
      return ['scanned' => 0, 'updated' => 0, 'programs' => 0];
    }

    $variation_storage = $this->entityTypeManager->getStorage('commerce_product_variation');
    $variation_ids = $variation_storage->getQuery()
      ->condition('product_id', $flagged_ids, 'IN')
      ->accessCheck(FALSE)
      ->execute();

    if (empty($variation_ids)) {
      return ['scanned' => 0, 'updated' => 0, 'programs' => count($flagged_ids)];
    }

    $order_item_storage = $this->entityTypeManager->getStorage('commerce_order_item');
    $order_item_ids = $order_item_storage->getQuery()
      ->condition('type', 'activity_registration')
      ->condition('purchased_entity', $variation_ids, 'IN')
      ->accessCheck(FALSE)
      ->execute();

    $scanned = 0;
    $updated = 0;
    foreach ($order_item_storage->loadMultiple($order_item_ids) as $item) {
      $scanned++;
      if ($this->applyToOrderItem($item)) {
        $updated++;
      }
    }

    return [
      'scanned' => $scanned,
      'updated' => $updated,
      'programs' => count($flagged_ids),
    ];
  }

}

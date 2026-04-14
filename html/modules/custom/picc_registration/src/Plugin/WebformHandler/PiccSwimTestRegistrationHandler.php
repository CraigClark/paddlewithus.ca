<?php

namespace Drupal\picc_registration\Plugin\WebformHandler;

use Drupal\Core\Form\FormStateInterface;
use Drupal\webform\Plugin\WebformHandlerBase;
use Drupal\webform\WebformSubmissionInterface;
use Drupal\profile\Entity\Profile;
use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_order\Entity\OrderItem;
use Drupal\commerce_product\Entity\ProductVariation;
use Drupal\commerce_price\Price;
use Drupal\Core\Url;

/**
 * Registers participants for swim test slots (no checkout, $0, immediate).
 *
 * @WebformHandler(
 *   id = "picc_swim_test_registration_handler",
 *   label = @Translation("PICC Swim Test Registration Handler"),
 *   category = @Translation("PICC"),
 *   description = @Translation("Creates completed Commerce orders for swim test registration. No cart or checkout."),
 *   cardinality = \Drupal\webform\Plugin\WebformHandlerInterface::CARDINALITY_SINGLE,
 *   results = \Drupal\webform\Plugin\WebformHandlerInterface::RESULTS_PROCESSED,
 * )
 */
class PiccSwimTestRegistrationHandler extends WebformHandlerBase {

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state, WebformSubmissionInterface $webform_submission) {
    $data = $webform_submission->getData();
    $current_user = \Drupal::currentUser();
    $user_id = $current_user->id();

    $selected_participants = array_filter($data['participants'] ?? []);

    if (empty($selected_participants)) {
      \Drupal::messenger()->addError($this->t('Please select at least one participant.'));
      return;
    }

    \Drupal::logger('picc_registration')->notice('Processing swim test registration for user @uid, @count participants, variation @variation', [
      '@uid' => $user_id,
      '@count' => count($selected_participants),
      '@variation' => $data['variation_id'] ?? 'unknown',
    ]);

    try {
      // Step 1: Validate variation exists and is a swim test slot.
      $variation = $this->validateVariation($data);

      // Step 2: Validate slot date hasn't passed.
      $this->validateSessionDate($variation);

      // Step 3: Validate participants are under 15 and eligible.
      $this->validateParticipants($selected_participants, $variation);

      // Step 4: Atomically check stock + create completed order.
      $order = $this->createOrderWithStockLock($selected_participants, $user_id, $variation);

      // Step 5: Redirect back to the product page.
      $product = $variation->getProduct();
      if ($product) {
        $product_url = $product->toUrl();
        $form_state->setRedirectUrl($product_url);
      }

      \Drupal::logger('picc_registration')->notice('Swim test registration complete: order @oid with @count participants', [
        '@oid' => $order->id(),
        '@count' => count($selected_participants),
      ]);

      \Drupal::messenger()->addStatus($this->t('Registration confirmed! You will receive a confirmation by email.'));

    }
    catch (\Exception $e) {
      \Drupal::logger('picc_registration')->error('Swim test registration failed for user @uid: @message', [
        '@uid' => $user_id,
        '@message' => $e->getMessage(),
      ]);
      \Drupal::messenger()->addError($e->getMessage());
    }
  }

  /**
   * Validates and loads the product variation.
   *
   * @throws \Exception
   */
  protected function validateVariation($data) {
    $variation_id = $data['variation_id'] ?? NULL;

    if (empty($variation_id)) {
      throw new \Exception($this->t('No swim test slot specified.'));
    }

    $variation = ProductVariation::load($variation_id);

    if (!$variation) {
      throw new \Exception($this->t('Swim test slot not found.'));
    }

    if ($variation->bundle() !== 'swim_test_slot') {
      throw new \Exception($this->t('Invalid registration type.'));
    }

    if (!$variation->isPublished()) {
      throw new \Exception($this->t('This swim test slot is no longer available.'));
    }

    return $variation;
  }

  /**
   * Validates that the swim test slot date hasn't passed.
   *
   * @throws \Exception
   */
  protected function validateSessionDate($variation) {
    if (!$variation->hasField('field_date_range')) {
      return;
    }

    $date_range = $variation->get('field_date_range')->first();
    if (!$date_range) {
      return;
    }

    $end_date = new \DateTime($date_range->end_value);
    $now = new \DateTime('now');

    if ($end_date < $now) {
      throw new \Exception($this->t('This swim test slot has already passed. Please select a different slot.'));
    }
  }

  /**
   * Validates participants are under 15 and eligible for swim test.
   *
   * Under 15 = birth date is after Dec 31 of (current year - 15).
   *
   * @throws \Exception
   */
  protected function validateParticipants($participant_ids, $variation) {
    $current_year = (int) date('Y');
    $cutoff = new \DateTime(($current_year - 15) . '-12-31');
    $ineligible = [];
    $already_cleared = [];

    foreach ($participant_ids as $profile_id) {
      $profile = Profile::load($profile_id);
      if (!$profile) {
        continue;
      }

      $name_field = $profile->get('field_name')->first();
      $name = $name_field ? trim(($name_field->given ?? '') . ' ' . ($name_field->family ?? '')) : $this->t('Unknown');

      // Check birth date exists.
      $birth_date_value = $profile->get('field_birth_date')->value;
      if (empty($birth_date_value)) {
        $ineligible[] = $this->t('@name (no birth date on file)', ['@name' => $name]);
        continue;
      }

      // Check under 15 as of Dec 31 this year.
      $birth_date = new \DateTime($birth_date_value);
      if ($birth_date <= $cutoff) {
        $ineligible[] = $this->t('@name (15 or older this year — use swim attestation instead)', ['@name' => $name]);
        continue;
      }

      // Check swim status — reject already passed or exempt.
      $swim_status = $profile->get('field_swim_status')->value ?? 'none';
      if (in_array($swim_status, ['passed', 'exempt'])) {
        $already_cleared[] = $name;
        continue;
      }
    }

    if (!empty($ineligible)) {
      throw new \Exception($this->t('The following participants cannot register for a swim test: @list', [
        '@list' => implode(', ', $ineligible),
      ]));
    }

    if (!empty($already_cleared)) {
      \Drupal::messenger()->addStatus($this->t('The following participants already meet swim requirements and were skipped: @names', [
        '@names' => implode(', ', $already_cleared),
      ]));
    }
  }

  /**
   * Atomically checks stock and creates a completed order under a lock.
   *
   * @throws \Exception
   */
  protected function createOrderWithStockLock($participant_ids, $user_id, $variation) {
    $variation_id = $variation->id();
    $lock_name = 'picc_swim_test_stock_' . $variation_id;
    /** @var \Drupal\Core\Lock\LockBackendInterface $lock */
    $lock = \Drupal::lock();

    $lock_acquired = $lock->acquire($lock_name, 15.0);
    if (!$lock_acquired) {
      $lock->wait($lock_name, 10);
      $lock_acquired = $lock->acquire($lock_name, 15.0);
    }

    if (!$lock_acquired) {
      throw new \Exception($this->t('The registration system is busy. Please try again in a moment.'));
    }

    try {
      $this->checkStockAvailability($variation, $participant_ids);
      return $this->createCompletedOrder($participant_ids, $user_id, $variation);
    }
    finally {
      $lock->release($lock_name);
    }
  }

  /**
   * Check stock availability for the variation.
   *
   * @throws \Exception
   */
  protected function checkStockAvailability($variation, $participant_ids) {
    $variation_id = $variation->id();

    $stock_service_manager = \Drupal::service('commerce_stock.service_manager');
    $stock_service = $stock_service_manager->getService($variation);

    if (!$stock_service || $stock_service->getId() === 'always_in_stock') {
      return;
    }

    // Count participants that will actually be added (exclude already registered).
    $participants_to_add = 0;
    foreach ($participant_ids as $profile_id) {
      $profile = Profile::load($profile_id);
      if (!$profile) {
        continue;
      }
      // Skip already cleared participants (they won't get order items).
      $swim_status = $profile->get('field_swim_status')->value ?? 'none';
      if (in_array($swim_status, ['passed', 'exempt'])) {
        continue;
      }
      // Skip if already registered for any future swim test.
      if ($this->getExistingFutureSwimTestRegistration($profile_id)) {
        continue;
      }
      $participants_to_add++;
    }

    if ($participants_to_add === 0) {
      return;
    }

    $stock_checker = $stock_service->getStockChecker();
    if (!$stock_checker) {
      return;
    }

    $location_storage = \Drupal::entityTypeManager()->getStorage('commerce_stock_location');
    $locations = $location_storage->loadByProperties(['status' => TRUE]);

    if (empty($locations)) {
      return;
    }

    $available = $stock_checker->getTotalStockLevel($variation, $locations);

    // Account for items in draft carts (not yet committed to stock).
    $draft_cart_count = $this->countDraftCartItemsForVariation($variation_id);
    $effective_available = $available - $draft_cart_count;

    \Drupal::logger('picc_registration')->notice('Swim test stock check: variation @vid has @raw raw, @draft in draft carts, @effective effective, requesting @requested', [
      '@vid' => $variation_id,
      '@raw' => $available,
      '@draft' => $draft_cart_count,
      '@effective' => $effective_available,
      '@requested' => $participants_to_add,
    ]);

    if ($effective_available < $participants_to_add) {
      $spots = max(0, $effective_available);
      if ($spots > 0) {
        throw new \Exception($this->t('Sorry, only @available spot(s) remain for this swim test slot. You selected @requested participants.', [
          '@available' => $spots,
          '@requested' => $participants_to_add,
        ]));
      }
      else {
        throw new \Exception($this->t('Sorry, this swim test slot is full. Please select a different slot.'));
      }
    }
  }

  /**
   * Creates a completed order with swim test registration items.
   *
   * Unlike activity registration, this skips the cart/checkout flow
   * and completes the order immediately (swim tests are free).
   */
  protected function createCompletedOrder($participant_ids, $user_id, $variation) {
    $variation_id = $variation->id();
    $skipped_completed = [];
    $skipped_cleared = [];
    $new_order_items = [];

    foreach ($participant_ids as $profile_id) {
      $profile = Profile::load($profile_id);
      $name_field = $profile ? $profile->get('field_name')->first() : NULL;
      $name = $name_field ? trim(($name_field->given ?? '') . ' ' . ($name_field->family ?? '')) : $this->t('Participant');

      // Skip already cleared.
      $swim_status = $profile ? ($profile->get('field_swim_status')->value ?? 'none') : 'none';
      if (in_array($swim_status, ['passed', 'exempt'])) {
        $skipped_cleared[] = $name;
        continue;
      }

      // Check if already registered for ANY future swim test slot.
      $existing = $this->getExistingFutureSwimTestRegistration($profile_id);
      if ($existing) {
        $msg = $existing['time']
          ? $this->t('@name is already registered for a swim test on @date at @time', [
              '@name' => $name,
              '@date' => $existing['date'],
              '@time' => $existing['time'],
            ])
          : $this->t('@name is already registered for a swim test on @date', [
              '@name' => $name,
              '@date' => $existing['date'],
            ]);
        $skipped_completed[] = $msg;
        continue;
      }

      // Create order item — always $0.
      $order_item = OrderItem::create([
        'type' => 'swim_test_registration',
        'purchased_entity' => $variation,
        'quantity' => 1,
        'unit_price' => new Price('0', 'CAD'),
        'field_participant' => $profile_id,
        'field_swim_test_status' => 'pending',
      ]);

      try {
        $order_item->save();
        $new_order_items[] = $order_item;
      }
      catch (\Exception $e) {
        $error_message = $e->getMessage();
        if (strpos($error_message, 'stock') !== FALSE || strpos($error_message, 'capacity') !== FALSE) {
          throw new \Exception($this->t('Sorry, this swim test slot is full. Please select a different slot.'));
        }
        throw $e;
      }
    }

    // Handle messaging for skipped participants.
    if (!empty($skipped_completed)) {
      \Drupal::messenger()->addWarning($this->t('Already registered for this slot: @names', [
        '@names' => implode(', ', $skipped_completed),
      ]));
    }

    // Note: skipped_cleared messaging is handled by validateParticipants().

    if (empty($new_order_items)) {
      throw new \Exception($this->t('No new registrations were created. All selected participants are either already registered or already meet swim requirements.'));
    }

    // Create a new order and complete it immediately.
    $store = $this->getStore();
    $user = \Drupal\user\Entity\User::load($user_id);

    $order = Order::create([
      'type' => 'default',
      'state' => 'draft',
      'mail' => $user->getEmail(),
      'uid' => $user_id,
      'store_id' => $store->id(),
      'order_items' => $new_order_items,
      'cart' => FALSE,
    ]);
    $order->save();

    // Set titles on order items (after order save).
    $order_item_storage = \Drupal::entityTypeManager()->getStorage('commerce_order_item');
    foreach ($order->getItems() as $item) {
      $fresh_item = $order_item_storage->load($item->id());
      if (!$fresh_item) {
        continue;
      }

      $participant_id = $fresh_item->get('field_participant')->target_id;
      if ($participant_id) {
        $profile = Profile::load($participant_id);
        $purchased_entity = $fresh_item->getPurchasedEntity();

        if ($profile && $purchased_entity) {
          $name_field = $profile->get('field_name')->first();
          $name = $name_field ? trim(($name_field->given ?? '') . ' ' . ($name_field->family ?? '')) : 'Participant';
          $fresh_item->setTitle($purchased_entity->getTitle() . ' - ' . $name);
          $fresh_item->save();
        }
      }
    }

    // Transition to completed — triggers stock decrement.
    $order->getState()->applyTransitionById('place');
    $order->save();

    // Build confirmation names list.
    $registered_names = [];
    foreach ($new_order_items as $item) {
      $pid = $item->get('field_participant')->target_id;
      $profile = Profile::load($pid);
      if ($profile) {
        $name_field = $profile->get('field_name')->first();
        $registered_names[] = $name_field ? trim(($name_field->given ?? '') . ' ' . ($name_field->family ?? '')) : '';
      }
    }

    $date_range = $variation->get('field_date_range')->first();
    $date_str = $date_range ? (new \DateTime($date_range->value))->format('F j, Y') : '';

    \Drupal::messenger()->addStatus($this->t('Registered @names for the swim test on @date.', [
      '@names' => implode(', ', array_filter($registered_names)),
      '@date' => $date_str,
    ]));

    return $order;
  }

  /**
   * Check if participant is already registered for ANY future swim test slot.
   *
   * Returns the variation details if found (for the user-facing message),
   * or NULL if no future registration exists.
   */
  protected function getExistingFutureSwimTestRegistration($profile_id) {
    $order_item_storage = \Drupal::entityTypeManager()->getStorage('commerce_order_item');

    $order_item_ids = $order_item_storage->getQuery()
      ->condition('type', 'swim_test_registration')
      ->condition('field_participant', $profile_id)
      ->accessCheck(TRUE)
      ->execute();

    if (empty($order_item_ids)) {
      return NULL;
    }

    $now = new \DateTime('now');

    foreach ($order_item_ids as $order_item_id) {
      $order_item = $order_item_storage->load($order_item_id);
      $order = $order_item->getOrder();

      if (!$order || !in_array($order->getState()->getId(), ['completed', 'fulfillment'])) {
        continue;
      }

      // Check if this registration's slot is in the future.
      $variation = $order_item->getPurchasedEntity();
      if (!$variation || !$variation->hasField('field_date_range')) {
        continue;
      }

      $date_range = $variation->get('field_date_range')->first();
      if (!$date_range) {
        continue;
      }

      // Only block if the slot is strictly in the future (after today).
      // Today's registrations don't block — allows re-booking after a same-day fail.
      $slot_date = new \DateTime($date_range->value);
      $today = new \DateTime('today');
      if ($slot_date > $today) {
        // Build a human-readable description of the existing registration.
        $date_str = (new \DateTime($date_range->value))->format('F j, Y');
        $time_str = '';
        if ($variation->hasField('field_time_range') && !$variation->get('field_time_range')->isEmpty()) {
          $time_value = $variation->get('field_time_range')->value;
          $time_str = (new \DateTime($time_value))->format('g:i A');
        }

        return [
          'date' => $date_str,
          'time' => $time_str,
          'variation_id' => $variation->id(),
        ];
      }
    }

    return NULL;
  }

  /**
   * Counts swim test order items in draft carts for a variation.
   */
  protected function countDraftCartItemsForVariation($variation_id) {
    $database = \Drupal::database();
    $query = $database->select('commerce_order_item', 'oi');
    $query->join('commerce_order__order_items', 'ooi', 'ooi.order_items_target_id = oi.order_item_id');
    $query->join('commerce_order', 'o', 'o.order_id = ooi.entity_id');
    $query->condition('oi.type', 'swim_test_registration')
      ->condition('oi.purchased_entity', $variation_id)
      ->condition('o.state', 'draft');
    $query->addExpression('COUNT(*)', 'item_count');
    return (int) $query->execute()->fetchField();
  }

  /**
   * Get the default store.
   *
   * @throws \Exception
   */
  protected function getStore() {
    $store_storage = \Drupal::entityTypeManager()->getStorage('commerce_store');
    $stores = $store_storage->loadMultiple();
    $store = reset($stores);

    if (!$store) {
      throw new \Exception($this->t('No store configured. Please contact administrator.'));
    }

    return $store;
  }

}

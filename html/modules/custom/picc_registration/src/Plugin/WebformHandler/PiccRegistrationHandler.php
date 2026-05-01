<?php

namespace Drupal\picc_registration\Plugin\WebformHandler;

use Drupal\Core\Form\FormStateInterface;
use Drupal\webform\Plugin\WebformHandlerBase;
use Drupal\webform\WebformSubmissionInterface;
use Drupal\profile\Entity\Profile;
use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_order\Entity\OrderItem;
use Drupal\commerce_product\Entity\ProductVariation;
use Drupal\Core\Url;

/**
 * Creates Commerce orders from selected participant profiles.
 *
 * @WebformHandler(
 *   id = "picc_registration_handler",
 *   label = @Translation("PICC Registration Handler"),
 *   category = @Translation("PICC"),
 *   description = @Translation("Creates Commerce orders for selected participants."),
 *   cardinality = \Drupal\webform\Plugin\WebformHandlerInterface::CARDINALITY_SINGLE,
 *   results = \Drupal\webform\Plugin\WebformHandlerInterface::RESULTS_PROCESSED,
 * )
 */
class PiccRegistrationHandler extends WebformHandlerBase {

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state, WebformSubmissionInterface $webform_submission) {

    // Get submitted data
    $data = $webform_submission->getData();

    // Get current user
    $current_user = \Drupal::currentUser();
    $user_id = $current_user->id();

    // Get selected participants (array of profile IDs from entity_checkboxes)
    $selected_participants = $data['participants'] ?? [];

    // Filter out empty values (webform checkboxes return 0 for unchecked)
    $selected_participants = array_filter($selected_participants);

    if (empty($selected_participants)) {
      \Drupal::messenger()->addError($this->t('Please select at least one participant.'));
      return;
    }

    // Log start
    \Drupal::logger('picc_registration')->notice('Processing registration for user @uid, @count participants, variation @variation', [
      '@uid' => $user_id,
      '@count' => count($selected_participants),
      '@variation' => $data['variation_id'] ?? 'unknown',
    ]);

    try {
      // Step 1: Validate product/variation exists
      $variation = $this->validateVariation($data);

      // Step 2: Validate session has not ended
      $this->validateSessionDate($variation);

      // Step 3: Validate age for all selected participants
      $this->validateParticipantsAge($selected_participants, $variation);

      // Step 4 & 5: Atomically check stock + create order (locked)
      $order = $this->createCommerceOrderWithStockLock($selected_participants, $user_id, $variation);

      // Step 6: Redirect to cart
      $cart_url = Url::fromRoute('commerce_cart.page');
      $form_state->setRedirectUrl($cart_url);

      // Step 7: Log success and show message
      \Drupal::logger('picc_registration')->notice('Successfully created order @oid with @count participants', [
        '@oid' => $order->id(),
        '@count' => count($selected_participants),
      ]);

      // Show success message
      \Drupal::messenger()->addStatus($this->t('Registration successful! Proceeding to checkout...'));

    } catch (\Exception $e) {
      // Log error with full context
      \Drupal::logger('picc_registration')->error('Registration failed for user @uid: @message', [
        '@uid' => $user_id,
        '@message' => $e->getMessage(),
      ]);

      // Show the specific error message (our validation messages are already user-friendly)
      \Drupal::messenger()->addError($e->getMessage());
    }
  }

  /**
   * Validates and loads the product variation.
   */
  protected function validateVariation($data) {
    $variation_id = $data['variation_id'] ?? NULL;

    if (empty($variation_id)) {
      throw new \Exception('No product variation specified.');
    }

    $variation = ProductVariation::load($variation_id);

    if (!$variation) {
      throw new \Exception("Product variation {$variation_id} not found.");
    }

    return $variation;
  }

  /**
   * Validates age for all selected participants.
   */
  protected function validateParticipantsAge($participant_ids, $variation) {
    // Get age requirements from variation
    $age_min = $variation->get('field_age_min')->value ?? 0;
    $age_max = $variation->get('field_maximum_age')->value ?? 99;
    $age_calc_date = $variation->get('field_age_calc_date')->value ?? NULL;

    // Skip validation if no age requirements
    if (empty($age_min) && empty($age_max)) {
      return;
    }

    $calc_date = $age_calc_date ? new \DateTime($age_calc_date) : new \DateTime();

    $invalid_participants = [];

    foreach ($participant_ids as $profile_id) {
      $profile = Profile::load($profile_id);
      if (!$profile) {
        continue;
      }

      $birth_date = $profile->get('field_birth_date')->value;
      if (empty($birth_date)) {
        $name_field = $profile->get('field_name')->first();
        $name = $name_field ? trim(($name_field->given ?? '') . ' ' . ($name_field->family ?? '')) : 'Unknown';
        $invalid_participants[] = "{$name} (no birth date)";
        continue;
      }

      $birth = new \DateTime($birth_date);
      $age = $birth->diff($calc_date)->y;

      if ($age < $age_min || $age > $age_max) {
        $name_field = $profile->get('field_name')->first();
        $name = $name_field ? trim(($name_field->given ?? '') . ' ' . ($name_field->family ?? '')) : 'Unknown';
        $invalid_participants[] = "{$name} (age {$age})";
      }
    }

    if (!empty($invalid_participants)) {
      $calc_info = $age_calc_date ? " as of " . $calc_date->format('F j, Y') : "";
      throw new \Exception("The following participants do not meet age requirements ({$age_min}-{$age_max}{$calc_info}): " . implode(', ', $invalid_participants));
    }
  }

  /**
   * Validates that the session has not already ended.
   */
  protected function validateSessionDate($variation) {
    // Check if variation has date range field
    if (!$variation->hasField('field_date_range')) {
      return;
    }

    $date_range = $variation->get('field_date_range')->first();
    if (!$date_range) {
      return;
    }

    // Get end date from the date range
    $end_date = new \DateTime($date_range->end_value);
    $now = new \DateTime('now');

    // Check if session has ended
    if ($end_date < $now) {
      throw new \Exception($this->t('This session has already ended on @date. Please select a different session.', [
        '@date' => $end_date->format('F j, Y'),
      ]));
    }
  }

  /**
   * Check stock availability for the variation.
   */
  protected function checkStockAvailability($variation, $participant_ids, $user_id) {
    $variation_id = $variation->id();

    // Get the stock service manager
    $stock_service_manager = \Drupal::service('commerce_stock.service_manager');
    $stock_service = $stock_service_manager->getService($variation);

    // If no stock service or it's "always in stock", skip validation
    if (!$stock_service || $stock_service->getId() === 'always_in_stock') {
      return;
    }

    // Count how many NEW participants will actually be added
    // (same logic as createCommerceOrder to avoid false positives)
    $existing_order = $this->findDraftOrder($user_id);
    $existing_participants = [];

    if ($existing_order) {
      foreach ($existing_order->getItems() as $item) {
        $item_variation_id = $item->getPurchasedEntityId();
        $item_participant_id = $item->get('field_participant')->target_id;

        if (!isset($existing_participants[$item_variation_id])) {
          $existing_participants[$item_variation_id] = [];
        }
        $existing_participants[$item_variation_id][] = $item_participant_id;
      }
    }

    // Count participants that will actually be added
    $participants_to_add = 0;
    foreach ($participant_ids as $profile_id) {
      // Skip if already in cart for this variation
      if (isset($existing_participants[$variation_id]) && in_array($profile_id, $existing_participants[$variation_id])) {
        continue;
      }

      // Skip if in a completed order
      if ($this->isInCompletedOrder($profile_id, $variation_id)) {
        continue;
      }

      $participants_to_add++;
    }

    // If no new participants to add, skip stock check
    if ($participants_to_add === 0) {
      return;
    }

    // Get the stock checker
    $stock_checker = $stock_service->getStockChecker();
    if (!$stock_checker) {
      return;
    }

    // Load active stock locations
    $location_storage = \Drupal::entityTypeManager()->getStorage('commerce_stock_location');
    $locations = $location_storage->loadByProperties(['status' => TRUE]);

    if (empty($locations)) {
      \Drupal::logger('picc_registration')->warning('No active stock locations found for variation @vid', [
        '@vid' => $variation_id,
      ]);
      return;
    }

    // Get available stock
    try {
      $available = $stock_checker->getTotalStockLevel($variation, $locations);
    }
    catch (\Exception $e) {
      \Drupal::logger('picc_registration')->error('Stock check failed for variation @vid: @message', [
        '@vid' => $variation_id,
        '@message' => $e->getMessage(),
      ]);
      return;
    }

    // Account for items in ALL draft carts for this variation.
    // getTotalStockLevel() only reflects committed stock transactions;
    // items sitting in draft/cart orders have not decremented stock yet.
    $draft_cart_count = $this->countDraftCartItemsForVariation($variation_id);

    // The current user's own cart items are already excluded from
    // $participants_to_add (filtered above), so add them back to avoid
    // double-counting against the user.
    $current_user_cart_count = 0;
    if (isset($existing_participants[$variation_id])) {
      $current_user_cart_count = count($existing_participants[$variation_id]);
    }

    $effective_available = $available - $draft_cart_count + $current_user_cart_count;

    \Drupal::logger('picc_registration')->notice('Stock check: variation @vid has @raw raw, @draft in draft carts, @effective effective available, requesting @requested', [
      '@vid' => $variation_id,
      '@raw' => $available,
      '@draft' => $draft_cart_count,
      '@effective' => $effective_available,
      '@requested' => $participants_to_add,
    ]);

    // Block if insufficient stock
    if ($effective_available < $participants_to_add) {
      $spots = max(0, $effective_available);
      if ($spots > 0) {
        throw new \Exception($this->t('Sorry, only @available spot(s) remain for this session. You selected @requested participants.', [
          '@available' => $spots,
          '@requested' => $participants_to_add,
        ]));
      }
      else {
        throw new \Exception($this->t('Sorry, this session is at capacity. Please try a different session.'));
      }
    }
  }

  /**
   * Creates or updates a Commerce order with order items for selected participants.
   */
  protected function createCommerceOrder($participant_ids, $user_id, $variation) {

    $variation_id = $variation->id();
    $skipped_already_in_cart = [];
    $skipped_completed = [];
    $new_order_items = [];

    // Find existing draft order for this user
    $existing_order = $this->findDraftOrder($user_id);

    // Get participants already in the cart (if cart exists)
    $existing_participants = [];
    if ($existing_order) {
      foreach ($existing_order->getItems() as $item) {
        $item_variation_id = $item->getPurchasedEntityId();
        $item_participant_id = $item->get('field_participant')->target_id;

        // Track participants by variation
        if (!isset($existing_participants[$item_variation_id])) {
          $existing_participants[$item_variation_id] = [];
        }
        $existing_participants[$item_variation_id][] = $item_participant_id;
      }
    }

    // Process each selected participant
    foreach ($participant_ids as $profile_id) {
      $profile = Profile::load($profile_id);
      $name_field = $profile ? $profile->get('field_name')->first() : NULL;
      $name = $name_field ? trim(($name_field->given ?? '') . ' ' . ($name_field->family ?? '')) : 'Participant';

      // Check if already in THIS cart for THIS variation
      if (isset($existing_participants[$variation_id]) && in_array($profile_id, $existing_participants[$variation_id])) {
        $skipped_already_in_cart[] = $name;
        continue;
      }

      // Check if in a COMPLETED order
      if ($this->isInCompletedOrder($profile_id, $variation_id)) {
        $skipped_completed[] = $name;
        continue;
      }

      // Create new order item
      $order_item = OrderItem::create([
        'type' => 'activity_registration',
        'purchased_entity' => $variation,
        'quantity' => 1,
        'unit_price' => $variation->getPrice(),
        'field_participant' => $profile_id,
      ]);

      try {
        $order_item->save();

        // Verify the item was actually saved with a purchased entity
        // Commerce Stock Enforcement might block the save
        if (!$order_item->id() || !$order_item->getPurchasedEntity()) {
          throw new \Exception('Unable to create registration - session may be at capacity.');
        }

        $new_order_items[] = $order_item;
      }
      catch (\Exception $e) {
        // Stock enforcement or other issue prevented order item creation
        $error_message = $e->getMessage();

        // Check if it's a stock-related error
        if (strpos($error_message, 'stock') !== FALSE || strpos($error_message, 'capacity') !== FALSE) {
          throw new \Exception($this->t('Sorry, this session is at capacity. Please try a different session.'));
        }

        // Re-throw other errors
        throw $e;
      }
    }

    // If no new items to add, check what happened
    if (empty($new_order_items)) {
      // Check if everything was already in cart (not an error - just redirect to cart)
      if (!empty($skipped_already_in_cart) && empty($skipped_completed)) {
        \Drupal::messenger()->addStatus($this->t('All selected participants are already in your cart.'));
        // Don't throw error, just return existing order for redirect
        if ($existing_order) {
          return $existing_order;
        }
      }

      // Otherwise, throw error (all were completed or nothing to do)
      $all_skipped = array_merge($skipped_already_in_cart, $skipped_completed);
      throw new \Exception('All selected participants are already registered for this session. No new registrations were created.');
    }

    // Show messages only when we're actually proceeding
    if (!empty($skipped_already_in_cart)) {
      \Drupal::messenger()->addStatus($this->t('Already in cart: @names', [
        '@names' => implode(', ', $skipped_already_in_cart),
      ]));
    }

    if (!empty($skipped_completed)) {
      \Drupal::messenger()->addWarning($this->t('The following participants were already registered for this session (skipped): @names', [
        '@names' => implode(', ', $skipped_completed),
      ]));
    }

    // Add new items to existing order OR create new order
    if ($existing_order) {
      // Add to existing cart
      foreach ($new_order_items as $order_item) {
        $existing_order->addItem($order_item);
      }
      $existing_order->save();
      $order = $existing_order;

      if (!empty($new_order_items)) {
        \Drupal::messenger()->addStatus($this->t('Added @count participant(s) to your cart.', [
          '@count' => count($new_order_items),
        ]));
      }
    } else {
      // Create new order
      $store = $this->getStore();
      $user = \Drupal\user\Entity\User::load($user_id);

      $order = Order::create([
        'type' => 'default',
        'state' => 'draft',
        'mail' => $user->getEmail(),
        'uid' => $user_id,
        'store_id' => $store->id(),
        'order_items' => $new_order_items,
        'cart' => TRUE,
        'billing_profile' => NULL,
      ]);
      $order->save();
    }

    // NOW set titles on all order items AFTER order is fully saved
    // Load items fresh from storage to ensure saves work
    $order_item_storage = \Drupal::entityTypeManager()->getStorage('commerce_order_item');
    foreach ($order->getItems() as $item) {
      $item_id = $item->id();
      // Reload from storage to get a fresh, saveable entity
      $fresh_item = $order_item_storage->load($item_id);

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
          $variation_title = $purchased_entity->getTitle();
          $new_title = $variation_title . ' - ' . $name;

          \Drupal::logger('picc_registration')->notice('Post-order title set: @title for item @iid', [
            '@title' => $new_title,
            '@iid' => $fresh_item->id(),
          ]);

          $fresh_item->setTitle($new_title);
          $fresh_item->save();
        }
      }

      // Apply swim test exemption if the program is flagged.
      \Drupal::service('picc_registration.swim_exemption_applier')
        ->applyToOrderItem($fresh_item);
    }

    return $order;
  }

  /**
   * Find existing draft order for user.
   */
  protected function findDraftOrder($user_id) {
    $order_storage = \Drupal::entityTypeManager()->getStorage('commerce_order');

    $query = $order_storage->getQuery()
      ->condition('uid', $user_id)
      ->condition('state', 'draft')
      ->condition('cart', TRUE)
      ->sort('order_id', 'DESC')
      ->range(0, 1)
      ->accessCheck(TRUE);

    $order_ids = $query->execute();

    if (!empty($order_ids)) {
      return $order_storage->load(reset($order_ids));
    }

    return NULL;
  }

  /**
   * Check if participant is in a completed order.
   */
  protected function isInCompletedOrder($profile_id, $variation_id) {
    $order_item_storage = \Drupal::entityTypeManager()->getStorage('commerce_order_item');

    $order_item_ids = $order_item_storage->getQuery()
      ->condition('type', 'activity_registration')
      ->condition('field_participant', $profile_id)
      ->condition('purchased_entity', $variation_id)
      ->accessCheck(TRUE)
      ->execute();

    if (empty($order_item_ids)) {
      return FALSE;
    }

    // Check if any order items are in completed orders
    $order_storage = \Drupal::entityTypeManager()->getStorage('commerce_order');
    foreach ($order_item_ids as $order_item_id) {
      $order_item = $order_item_storage->load($order_item_id);
      $order = $order_item->getOrder();

      if ($order && in_array($order->getState()->getId(), ['completed', 'fulfillment'])) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Atomically checks stock and creates the commerce order under a lock.
   *
   * Prevents race conditions where concurrent requests both pass the stock
   * check before either creates order items.
   *
   * @param array $participant_ids
   *   Array of participant profile IDs.
   * @param int $user_id
   *   The user ID.
   * @param \Drupal\commerce_product\Entity\ProductVariation $variation
   *   The product variation.
   *
   * @return \Drupal\commerce_order\Entity\OrderInterface
   *   The created or updated order.
   *
   * @throws \Exception
   *   If stock is insufficient or lock cannot be acquired.
   */
  protected function createCommerceOrderWithStockLock($participant_ids, $user_id, $variation) {
    $variation_id = $variation->id();
    $lock_name = 'picc_registration_stock_' . $variation_id;
    /** @var \Drupal\Core\Lock\LockBackendInterface $lock */
    $lock = \Drupal::lock();

    // Attempt to acquire the lock. If another request holds it, wait and retry.
    $lock_acquired = $lock->acquire($lock_name, 15.0);
    if (!$lock_acquired) {
      $lock->wait($lock_name, 10);
      $lock_acquired = $lock->acquire($lock_name, 15.0);
    }

    if (!$lock_acquired) {
      throw new \Exception($this->t('The registration system is busy. Please try again in a moment.'));
    }

    try {
      // Inside the lock: re-check stock availability with current data.
      $this->checkStockAvailability($variation, $participant_ids, $user_id);

      // Stock is sufficient — create the order items while holding the lock.
      return $this->createCommerceOrder($participant_ids, $user_id, $variation);
    }
    finally {
      $lock->release($lock_name);
    }
  }

  /**
   * Counts order items in all draft carts for a given variation.
   *
   * Commerce Stock's getTotalStockLevel() only reflects committed stock
   * transactions, not items sitting in draft/cart orders. This method
   * bridges that gap.
   *
   * @param int $variation_id
   *   The product variation ID.
   *
   * @return int
   *   The number of order items across all draft carts for this variation.
   */
  protected function countDraftCartItemsForVariation($variation_id) {
    $database = \Drupal::database();
    $query = $database->select('commerce_order_item', 'oi');
    $query->join('commerce_order__order_items', 'ooi', 'ooi.order_items_target_id = oi.order_item_id');
    $query->join('commerce_order', 'o', 'o.order_id = ooi.entity_id');
    $query->condition('oi.type', 'activity_registration')
      ->condition('oi.purchased_entity', $variation_id)
      ->condition('o.state', 'draft');
    $query->addExpression('COUNT(*)', 'item_count');
    $result = $query->execute()->fetchField();
    return (int) $result;
  }

  /**
   * Get the store.
   */
  protected function getStore() {
    $store_storage = \Drupal::entityTypeManager()->getStorage('commerce_store');
    $stores = $store_storage->loadMultiple();
    $store = reset($stores);

    if (!$store) {
      throw new \Exception('No store configured. Please contact administrator.');
    }

    return $store;
  }

}

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
      
      // Step 2: Validate age for all selected participants
      $this->validateParticipantsAge($selected_participants, $variation);
      
      // Step 3: Create order with order items for each selected participant
      $order = $this->createCommerceOrder($selected_participants, $user_id, $variation);
      
      // Step 4: Redirect to checkout
      $checkout_url = Url::fromRoute('commerce_checkout.form', [
        'commerce_order' => $order->id(),
        'step' => 'order_information',
      ]);
      $form_state->setRedirectUrl($checkout_url);
      
      // Log success
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
      
      // Show user-friendly error
      \Drupal::messenger()->addError($this->t('Sorry, registration failed: @message. Please try again or contact us for help.', [
        '@message' => $e->getMessage(),
      ]));
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
      
      $order_item->save();
      $new_order_items[] = $order_item;
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
        if ($profile) {
          $name_field = $profile->get('field_name')->first();
          $name = $name_field ? trim(($name_field->given ?? '') . ' ' . ($name_field->family ?? '')) : 'Participant';
          $variation_title = $fresh_item->getPurchasedEntity()->getTitle();
          $new_title = $variation_title . ' - ' . $name;
          
          \Drupal::logger('picc_registration')->notice('Post-order title set: @title for item @iid', [
            '@title' => $new_title,
            '@iid' => $fresh_item->id(),
          ]);
          
          $fresh_item->setTitle($new_title);
          $fresh_item->save();
        }
      }
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

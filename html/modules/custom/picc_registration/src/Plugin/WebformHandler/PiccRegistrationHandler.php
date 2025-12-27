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
   * Creates a Commerce order with order items for selected participants.
   */
  protected function createCommerceOrder($participant_ids, $user_id, $variation) {
    
    $order_items = [];
    
    // Create an order item for each selected participant
    foreach ($participant_ids as $profile_id) {
      $order_item = OrderItem::create([
        'type' => 'activity_registration',
        'purchased_entity' => $variation,
        'quantity' => 1,
        'unit_price' => $variation->getPrice(),
        'field_participant' => $profile_id,
      ]);
      $order_item->save();
      $order_items[] = $order_item;
    }
    
    // Load the store
    $store_storage = \Drupal::entityTypeManager()->getStorage('commerce_store');
    $stores = $store_storage->loadMultiple();
    $store = reset($stores);
    
    if (!$store) {
      throw new \Exception('No store configured. Please contact administrator.');
    }
    
    // Get user email
    $user = \Drupal\user\Entity\User::load($user_id);
    $email = $user->getEmail();
    
    // Create the order
    $order = Order::create([
      'type' => 'default',
      'state' => 'draft',
      'mail' => $email,
      'uid' => $user_id,
      'store_id' => $store->id(),
      'order_items' => $order_items,
      'billing_profile' => NULL, // Will be set during checkout
    ]);
    $order->save();
    
    return $order;
  }

}

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
 * Creates participant profiles and Commerce orders from webform submissions.
 *
 * @WebformHandler(
 *   id = "picc_registration_handler",
 *   label = @Translation("PICC Registration Handler"),
 *   category = @Translation("PICC"),
 *   description = @Translation("Creates participant profiles and Commerce orders for PICC program registration."),
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
    
    // Log start
    \Drupal::logger('picc_registration')->notice('Processing registration for user @uid, product @product, variation @variation', [
      '@uid' => $user_id,
      '@product' => $data['product_id'] ?? 'unknown',
      '@variation' => $data['variation_id'] ?? 'unknown',
    ]);
    
    try {
      // Step 1: Validate product/variation exists
      $variation = $this->validateVariation($data);
      
      // Step 2: Create Participant Profile FIRST (so data isn't lost if age fails)
      $profile = $this->createParticipantProfile($data, $user_id);
      
      // Step 3: Validate age requirements (after profile saved)
      $this->validateAge($data, $variation, $profile);
      
      // Step 4: Create Commerce Order
      $order = $this->createCommerceOrder($data, $profile, $user_id, $variation);
      
      // Step 5: Redirect to checkout
      $checkout_url = Url::fromRoute('commerce_checkout.form', [
        'commerce_order' => $order->id(),
        'step' => 'order_information',
      ]);
      $form_state->setRedirectUrl($checkout_url);
      
      // Log success
      \Drupal::logger('picc_registration')->notice('Successfully created profile @pid and order @oid', [
        '@pid' => $profile->id(),
        '@oid' => $order->id(),
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
    
    // Optional: Validate variation belongs to product
    $product_id = $data['product_id'] ?? NULL;
    if ($product_id && $variation->getProductId() != $product_id) {
      throw new \Exception('Invalid product/variation combination.');
    }
    
    return $variation;
  }
  
  /**
   * Validates participant age against program requirements.
   */
  protected function validateAge($data, $variation, $profile) {
    $birth_date = $data['participant_birth_date'] ?? NULL;
    
    if (empty($birth_date)) {
      throw new \Exception('Birth date is required.');
    }
    
    // Get age requirements from variation
    $age_min = $variation->get('field_age_min')->value ?? 0;
    $age_max = $variation->get('field_maximum_age')->value ?? 99;
    $age_calc_date = $variation->get('field_age_calc_date')->value ?? NULL;
    
    // Calculate age
    $birth = new \DateTime($birth_date);
    $calc_date = $age_calc_date ? new \DateTime($age_calc_date) : new \DateTime();
    $age = $birth->diff($calc_date)->y;
    
    // Validate
    if ($age < $age_min || $age > $age_max) {
      $calc_info = $age_calc_date ? " as of " . $calc_date->format('F j, Y') : "";
      
      // Profile was already saved, so tell user they can edit it
      $profile_url = '/user/' . \Drupal::currentUser()->id() . '/participant/' . $profile->id() . '/edit';
      
      throw new \Exception("Participant must be between {$age_min} and {$age_max} years old{$calc_info}. Participant will be {$age}{$calc_info}. Your participant profile has been saved and you can <a href='{$profile_url}'>edit it here</a> if needed.");
    }
  }
  
  /**
   * Creates a Participant profile from webform data.
   */
  protected function createParticipantProfile($data, $user_id) {
    
    // Determine if this is the account holder
    $is_account_holder = ($data['who_participating'] === 'myself');
    
    // Build name field value (using Name module format)
    $name_value = [
      'given' => $data['participant_first_name'] ?? '',
      'family' => $data['participant_last_name'] ?? '',
    ];
    
    // Create the profile
    $profile = Profile::create([
      'type' => 'participant',
      'uid' => $user_id,
      'field_owner' => $user_id,
      'field_name' => $name_value,
      'field_birth_date' => $data['participant_birth_date'],
      'field_account_holder' => $is_account_holder,
    ]);
    
    // Add emergency contact 1 (required)
    if (!empty($data['emergency_1_name'])) {
      $profile->set('field_emercency_contact_1_name', $data['emergency_1_name']);
      $profile->set('field_emercency_contact_1_phone', $data['emergency_1_phone']);
      $profile->set('field_emercency_contact_1_rel', $data['emergency_1_relationship']);
    }
    
    // Add emergency contact 2 (optional)
    if (!empty($data['emergency_2_name'])) {
      $profile->set('field_emercency_contact_2_name', $data['emergency_2_name']);
      $profile->set('field_emercency_contact_2_phone', $data['emergency_2_phone']);
      $profile->set('field_emercency_contact_2_rel', $data['emergency_2_relationship']);
    }
    
    // Add emergency contact 3 (optional)
    if (!empty($data['emergency_3_name'])) {
      // Note: field_emercency_contact_3_name doesn't exist in config, using available fields
      if ($profile->hasField('field_emercency_contact_name')) {
        $profile->set('field_emercency_contact_name', $data['emergency_3_name']);
      }
      $profile->set('field_emercency_contact_3_phone', $data['emergency_3_phone']);
      $profile->set('field_emercency_contact_3_rel', $data['emergency_3_relationship']);
    }
    
    // Add medical information (optional)
    if (!empty($data['allergies'])) {
      $profile->set('field_allergies', $data['allergies']);
    }
    
    if (!empty($data['medical_notes'])) {
      $profile->set('field_medical_notes', $data['medical_notes']);
    }
    
    // Add consents
    $profile->set('field_photo_consent', !empty($data['photo_consent']));
    $profile->set('field_swim_test_req', !empty($data['swim_test_acknowledgment']));
    
    // Save the profile
    $profile->save();
    
    return $profile;
  }
  
  /**
   * Creates a Commerce order from webform data.
   */
  protected function createCommerceOrder($data, $profile, $user_id, $variation) {
    
    // Create order item
    $order_item = OrderItem::create([
      'type' => 'activity_registration',
      'purchased_entity' => $variation,
      'quantity' => 1,
      'unit_price' => $variation->getPrice(),
      'field_participant' => $profile->id(),
    ]);
    $order_item->save();
    
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
      'order_items' => [$order_item],
      'billing_profile' => NULL, // Will be set during checkout
    ]);
    $order->save();
    
    return $order;
  }

}

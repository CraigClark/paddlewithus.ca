<?php

namespace Drupal\picc_registration\Plugin\WebformElement;

use Drupal\Core\Form\FormStateInterface;
use Drupal\webform\Plugin\WebformElementBase;
use Drupal\webform\WebformSubmissionInterface;
use Drupal\profile\Entity\Profile;
use Drupal\commerce_order\Entity\OrderItem;

/**
 * Provides a 'participant_selector' element.
 *
 * @WebformElement(
 *   id = "participant_selector",
 *   label = @Translation("Participant Selector"),
 *   description = @Translation("Shows user's participants with registration status."),
 *   category = @Translation("PICC"),
 * )
 */
class ParticipantSelector extends WebformElementBase {

  /**
   * {@inheritdoc}
   */
  public function getDefaultProperties() {
    return [
      'multiple' => TRUE,
      'required' => TRUE,
    ] + parent::getDefaultProperties();
  }

  /**
   * {@inheritdoc}
   */
  public function prepare(array &$element, WebformSubmissionInterface $webform_submission = NULL) {
    parent::prepare($element, $webform_submission);
    
    // Get current user
    $current_user = \Drupal::currentUser();
    $user_id = $current_user->id();
    
    // Get variation ID from webform data
    $data = $webform_submission ? $webform_submission->getData() : [];
    $variation_id = $data['variation_id'] ?? \Drupal::request()->query->get('variation');
    
    // Get user's participant profiles
    $profiles = $this->getUserProfiles($user_id);
    
    // Debug: Log what we found
    \Drupal::logger('picc_registration')->notice('Found @count profiles for user @uid, variation @var', [
      '@count' => count($profiles),
      '@uid' => $user_id,
      '@var' => $variation_id ?? 'NULL',
    ]);
    
    // Check which are already registered
    $registered = [];
    $available = [];
    
    foreach ($profiles as $profile) {
      $profile_id = $profile->id();
      $is_reg = $this->isRegistered($profile_id, $variation_id);
      
      // Debug: Log each profile
      $name_field = $profile->get('field_name')->first();
      $name = $name_field ? trim(($name_field->given ?? '') . ' ' . ($name_field->family ?? '')) : 'Unknown';
      \Drupal::logger('picc_registration')->notice('Profile @pid (@name): registered=@reg', [
        '@pid' => $profile_id,
        '@name' => $name,
        '@reg' => $is_reg ? 'YES' : 'NO',
      ]);
      
      if ($is_reg) {
        $registered[] = $profile;
      } else {
        $available[] = $profile;
      }
    }
    
    // Build the element
    $element['#type'] = 'container';
    $element['#attributes']['class'][] = 'participant-selector';
    
    // Already registered section
    if (!empty($registered)) {
      $element['registered'] = [
        '#type' => 'markup',
        '#markup' => $this->buildRegisteredSection($registered, $variation_id),
      ];
    }
    
    // Available participants section
    if (!empty($available)) {
      $element['available'] = [
        '#type' => 'checkboxes',
        '#title' => $this->t('Select Participants to Register'),
        '#options' => $this->buildParticipantOptions($available),
        '#required' => $element['#required'] ?? FALSE,
      ];
    } else {
      $element['no_available'] = [
        '#type' => 'markup',
        '#markup' => '<p><em>' . $this->t('No participants available to register.') . '</em></p>',
      ];
    }
    
    // Add new participant link
    $user_id = \Drupal::currentUser()->id();
    $current_path = \Drupal::service('path.current')->getPath();
    $query_params = \Drupal::request()->query->all();
    $destination = $current_path . '?' . http_build_query($query_params);
    
    $element['add_new'] = [
      '#type' => 'markup',
      '#markup' => '<p><a href="/user/' . $user_id . '/participant/add?destination=' . urlencode($destination) . '" class="btn btn-secondary">' . $this->t('+ Add New Participant') . '</a></p>',
    ];
    
    return $element;
  }
  
  /**
   * Get user's participant profiles.
   */
  protected function getUserProfiles($user_id) {
    $profile_storage = \Drupal::entityTypeManager()->getStorage('profile');
    
    $profiles = $profile_storage->loadByProperties([
      'type' => 'participant',
      'field_owner' => $user_id,
    ]);
    
    return $profiles;
  }
  
  /**
   * Check if participant is already registered for this variation.
   */
  protected function isRegistered($profile_id, $variation_id) {
    if (empty($variation_id)) {
      return FALSE;
    }
    
    $order_item_storage = \Drupal::entityTypeManager()->getStorage('commerce_order_item');
    
    $query = $order_item_storage->getQuery()
      ->condition('type', 'activity_registration')
      ->condition('field_participant', $profile_id)
      ->condition('purchased_entity', $variation_id)
      ->accessCheck(TRUE)
      ->count();
    
    $count = $query->execute();
    
    return $count > 0;
  }
  
  /**
   * Build registered section markup.
   */
  protected function buildRegisteredSection($profiles, $variation_id) {
    $output = '<div class="already-registered mb-4">';
    $output .= '<h4>' . $this->t('Already Registered for This Session') . '</h4>';
    $output .= '<ul class="list-unstyled">';
    
    foreach ($profiles as $profile) {
      $name = $this->getProfileName($profile);
      $age = $this->getProfileAge($profile);
      $photo_consent = $this->getPhotoConsent($profile);
      
      $output .= '<li class="mb-2">';
      $output .= '<strong>' . $name . '</strong> (' . $age . ')';
      $output .= ' ' . $photo_consent;
      $output .= ' <span class="text-success">✓ Registered</span>';
      
      // Edit link
      $profile_id = $profile->id();
      $user_id = \Drupal::currentUser()->id();
      $current_path = \Drupal::service('path.current')->getPath();
      $query_params = \Drupal::request()->query->all();
      $destination = $current_path . '?' . http_build_query($query_params);
      
      $output .= ' <a href="/user/' . $user_id . '/participant/' . $profile_id . '/edit?destination=' . urlencode($destination) . '" class="btn btn-sm btn-link">Edit</a>';
      $output .= '</li>';
    }
    
    $output .= '</ul></div>';
    
    return $output;
  }
  
  /**
   * Build participant options for checkboxes.
   */
  protected function buildParticipantOptions($profiles) {
    $options = [];
    
    foreach ($profiles as $profile) {
      $profile_id = $profile->id();
      $name = $this->getProfileName($profile);
      $age = $this->getProfileAge($profile);
      $photo_consent_value = $profile->get('field_photo_consent')->value;
      
      // Simple text-only label for now (no HTML)
      $consent_text = ($photo_consent_value === 'yes') ? '📷' : '🚫';
      
      $options[$profile_id] = $name . ' (' . $age . ') ' . $consent_text;
    }
    
    \Drupal::logger('picc_registration')->notice('Built @count checkbox options', [
      '@count' => count($options),
    ]);
    
    return $options;
  }
  
  /**
   * Get profile name.
   */
  protected function getProfileName($profile) {
    $name_field = $profile->get('field_name')->first();
    if ($name_field) {
      $given = $name_field->given ?? '';
      $family = $name_field->family ?? '';
      return trim($given . ' ' . $family);
    }
    return 'Unknown';
  }
  
  /**
   * Get profile age.
   */
  protected function getProfileAge($profile) {
    $birth_date = $profile->get('field_birth_date')->value;
    if ($birth_date) {
      $birth = new \DateTime($birth_date);
      $now = new \DateTime();
      $age = $birth->diff($now)->y;
      return 'Age ' . $age;
    }
    return '';
  }
  
  /**
   * Get photo consent indicator.
   */
  protected function getPhotoConsent($profile) {
    $consent = $profile->get('field_photo_consent')->value;
    
    if ($consent === 'yes') {
      return '<span class="photo-consent-icon" title="Photo consent given">📷 <span class="sr-only">Consents to photos being taken and used for promotional purposes</span></span>';
    } else {
      return '<span class="photo-consent-icon" title="No photo consent">🚫 <span class="sr-only">Does not consent to photos being taken</span></span>';
    }
  }

}

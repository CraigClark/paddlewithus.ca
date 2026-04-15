<?php

namespace Drupal\picc_registration\Plugin\WebformHandler;

use Drupal\Core\Form\FormStateInterface;
use Drupal\webform\Plugin\WebformHandlerBase;
use Drupal\webform\WebformSubmissionInterface;
use Drupal\profile\Entity\Profile;

/**
 * Records swim attestation on participant profiles.
 *
 * @WebformHandler(
 *   id = "picc_swim_attestation_handler",
 *   label = @Translation("PICC Swim Attestation Handler"),
 *   category = @Translation("PICC"),
 *   description = @Translation("Sets swim status to attested on selected participant profiles."),
 *   cardinality = \Drupal\webform\Plugin\WebformHandlerInterface::CARDINALITY_SINGLE,
 *   results = \Drupal\webform\Plugin\WebformHandlerInterface::RESULTS_PROCESSED,
 * )
 */
class PiccSwimAttestationHandler extends WebformHandlerBase {

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state, WebformSubmissionInterface $webform_submission) {
    $data = $webform_submission->getData();
    $current_user = \Drupal::currentUser();

    $selected_participants = array_filter($data['participants'] ?? []);

    if (empty($selected_participants)) {
      \Drupal::messenger()->addError($this->t('Please select at least one participant.'));
      return;
    }

    \Drupal::logger('picc_registration')->notice('Processing swim attestation for user @uid, @count participants', [
      '@uid' => $current_user->id(),
      '@count' => count($selected_participants),
    ]);

    $current_year = (int) date('Y');
    $cutoff_date = new \DateTime(($current_year - 15) . '-12-31');
    $attested_names = [];
    $skipped_already = [];
    $skipped_ineligible = [];

    foreach ($selected_participants as $profile_id) {
      $profile = Profile::load($profile_id);
      if (!$profile) {
        continue;
      }

      $name_field = $profile->get('field_name')->first();
      $name = $name_field ? trim(($name_field->given ?? '') . ' ' . ($name_field->family ?? '')) : $this->t('Unknown');

      // Verify 15+ (born on or before cutoff).
      $birth_date_value = $profile->get('field_birth_date')->value;
      if (empty($birth_date_value)) {
        $skipped_ineligible[] = $this->t('@name (no birth date on file)', ['@name' => $name]);
        continue;
      }

      $birth_date = new \DateTime($birth_date_value);
      if ($birth_date > $cutoff_date) {
        $skipped_ineligible[] = $this->t('@name (under 15 — register for a swim test instead)', ['@name' => $name]);
        continue;
      }

      // Check if already attested, passed, or exempt.
      $current_status = $profile->get('field_swim_status')->value ?? 'none';
      if (in_array($current_status, ['passed', 'attested', 'exempt'])) {
        $skipped_already[] = $name;
        continue;
      }

      // Set attestation fields.
      $profile->set('field_swim_status', 'attested');
      $profile->set('field_swim_attestation_date', date('Y-m-d'));
      $profile->set('field_swim_attestation_by', $current_user->id());
      $profile->save();

      $attested_names[] = $name;
    }

    // Messages.
    if (!empty($attested_names)) {
      \Drupal::messenger()->addStatus($this->t('Swim attestation confirmed for: @names.', [
        '@names' => implode(', ', $attested_names),
      ]));
    }

    if (!empty($skipped_already)) {
      \Drupal::messenger()->addStatus($this->t('Already meet swim requirements (skipped): @names.', [
        '@names' => implode(', ', $skipped_already),
      ]));
    }

    if (!empty($skipped_ineligible)) {
      \Drupal::messenger()->addWarning($this->t('Not eligible for attestation: @list', [
        '@list' => implode(', ', $skipped_ineligible),
      ]));
    }

    if (empty($attested_names) && empty($skipped_already)) {
      \Drupal::messenger()->addError($this->t('No attestations were recorded.'));
    }

    \Drupal::logger('picc_registration')->notice('Swim attestation complete: @count attested, @skipped skipped', [
      '@count' => count($attested_names),
      '@skipped' => count($skipped_already) + count($skipped_ineligible),
    ]);
  }

}

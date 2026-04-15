<?php

namespace Drupal\picc_ext\Plugin\views\field;

use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;

/**
 * Displays status icons for a participant profile.
 *
 * Shows Material Icon indicators for swim test, medical concerns, and photo
 * consent so coaches can see critical info at a glance.
 *
 * @ViewsField("participant_status_icons")
 */
class ParticipantStatusIcons extends FieldPluginBase {

  /**
   * {@inheritdoc}
   */
  public function query() {
    // No query needed — we read field values from the profile entity.
  }

  /**
   * {@inheritdoc}
   */
  public function render(ResultRow $values) {
    /** @var \Drupal\profile\Entity\ProfileInterface|null $profile */
    $profile = $this->getEntity($values);

    if (!$profile) {
      return '';
    }

    $icons = [];

    // Swim status: icon if participant meets swim requirements (passed, attested, or exempt).
    if ($profile->hasField('field_swim_status')) {
      $swim_status = $profile->get('field_swim_status')->value ?? 'none';
      if (in_array($swim_status, ['passed', 'attested', 'exempt'])) {
        $icons[] = '<span class="participant-icon participant-icon--swim" title="' . t('Meets swim requirements') . '"><span class="material-icons" aria-hidden="true">pool</span><span class="sr-only">' . t('Meets swim requirements') . '</span></span>';
      }
    }

    // Medical: icon only when there is something to note.
    $has_medical = FALSE;
    if ($profile->hasField('field_allergies') && !$profile->get('field_allergies')->isEmpty()) {
      $has_medical = TRUE;
    }
    if ($profile->hasField('field_additional_info') && !$profile->get('field_additional_info')->isEmpty()) {
      $has_medical = TRUE;
    }
    if ($has_medical) {
      $icons[] = '<span class="participant-icon participant-icon--medical" title="Medical concerns — view profile for details"><span class="material-icons" aria-hidden="true">health_and_safety</span><span class="sr-only">Medical concerns</span></span>';
    }

    // Photo consent: icon only if consent given.
    if ($profile->hasField('field_photo_consent') && $profile->get('field_photo_consent')->value) {
      $icons[] = '<span class="participant-icon participant-icon--photo" title="Photo consent given"><span class="material-icons" aria-hidden="true">photo_camera</span><span class="sr-only">Photo consent given</span></span>';
    }

    if (empty($icons)) {
      return '';
    }

    return [
      '#markup' => '<span class="participant-icons">' . implode(' ', $icons) . '</span>',
    ];
  }

}

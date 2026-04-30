<?php

namespace Drupal\picc_registration\Plugin\views\field;

use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;

/**
 * Renders a DaisyUI badge representing the participant's current swim_status.
 *
 * @ViewsField("participant_swim_status_badge")
 */
class ParticipantSwimStatusBadge extends FieldPluginBase {

  /**
   * {@inheritdoc}
   */
  public function query() {
    // Reads from the profile entity already loaded for the row.
  }

  /**
   * {@inheritdoc}
   */
  public function render(ResultRow $values) {
    $profile = $this->getEntity($values);
    if (!$profile || $profile->bundle() !== 'participant') {
      return '';
    }
    $status = $profile->get('field_swim_status')->value ?? 'none';

    [$class, $label] = match ($status) {
      'passed'   => ['badge-success', $this->t('Passed')],
      'failed'   => ['badge-warning', $this->t('Failed')],
      'attested' => ['badge-info',    $this->t('Attested')],
      'exempt'   => ['badge-neutral', $this->t('Exempt')],
      default    => ['badge-ghost',   $this->t('Pending')],
    };

    return [
      '#markup' => '<span class="badge ' . $class . '">' . $label . '</span>',
    ];
  }

}

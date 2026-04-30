<?php

namespace Drupal\picc_registration\Plugin\views\field;

use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;
use Drupal\Core\Url;

/**
 * Renders an "Evaluate" button on the swim test roster for participants whose
 * field_swim_status is none or failed. Cleared statuses (passed/attested/
 * exempt) get nothing — the status badge in the adjacent column conveys the
 * state, no action is needed.
 *
 * @ViewsField("participant_swim_action")
 */
class ParticipantSwimAction extends FieldPluginBase {

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
    if (!in_array($status, ['none', 'failed'], TRUE)) {
      return '';
    }

    $url = Url::fromRoute('picc_registration.swim_test_evaluate', [
      'profile' => $profile->id(),
    ]);
    $dialog_opts = htmlspecialchars(json_encode(['width' => 400]), ENT_QUOTES);

    return [
      '#markup' => '<div class="swim-test-actions flex justify-end">'
        . '<a href="' . $url->toString() . '" class="btn btn-primary btn-sm use-ajax" data-dialog-type="dialog" data-dialog-options=\'' . $dialog_opts . '\'>'
        . t('Evaluate')
        . '</a></div>',
      '#attached' => [
        'library' => ['core/drupal.dialog.ajax'],
      ],
    ];
  }

}

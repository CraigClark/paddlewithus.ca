<?php

/**
 * @file
 * Views integration for picc_registration.
 */

/**
 * Implements hook_views_data_alter().
 */
function picc_registration_views_data_alter(array &$data) {
  // Pass/Fail/Resolved/Undo button on the no-show review (commerce_order_item).
  if (isset($data['commerce_order_item'])) {
    $data['commerce_order_item']['swim_test_action'] = [
      'title' => t('Swim test action'),
      'help' => t('Resolved/Undo buttons for swim test no-show review.'),
      'field' => [
        'id' => 'swim_test_action',
      ],
    ];
  }

  // Status badge + Evaluate button on the profile-based swim test roster.
  if (isset($data['profile'])) {
    $data['profile']['participant_swim_status_badge'] = [
      'title' => t('Swim status badge (participant)'),
      'help' => t('Renders a colored badge from field_swim_status.'),
      'field' => [
        'id' => 'participant_swim_status_badge',
      ],
    ];
    $data['profile']['participant_swim_action'] = [
      'title' => t('Swim test action (participant)'),
      'help' => t('Evaluate button for participants whose status is none or failed; nothing for cleared statuses.'),
      'field' => [
        'id' => 'participant_swim_action',
      ],
    ];
  }
}

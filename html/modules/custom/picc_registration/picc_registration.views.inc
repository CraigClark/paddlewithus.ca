<?php

/**
 * @file
 * Views integration for the PICC Registration module.
 */

/**
 * Implements hook_views_data_alter().
 */
function picc_registration_views_data_alter(array &$data) {
  // Add participant status icons field to the profile table.
  if (isset($data['profile'])) {
    $data['profile']['participant_status_icons'] = [
      'title' => t('Participant status icons'),
      'help' => t('Shows swim test, medical, and photo consent icons for a participant profile.'),
      'field' => [
        'id' => 'participant_status_icons',
      ],
    ];
  }
}

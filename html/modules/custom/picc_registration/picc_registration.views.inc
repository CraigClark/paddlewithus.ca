<?php

/**
 * @file
 * Views integration for picc_registration.
 */

/**
 * Implements hook_views_data_alter().
 */
function picc_registration_views_data_alter(array &$data) {
  // Add swim test action field to commerce_order_item views.
  if (isset($data['commerce_order_item'])) {
    $data['commerce_order_item']['swim_test_action'] = [
      'title' => t('Swim test action'),
      'help' => t('Pass/Fail/Undo buttons for swim test evaluation.'),
      'field' => [
        'id' => 'swim_test_action',
      ],
    ];
  }
}

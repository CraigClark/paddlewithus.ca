<?php

/**
 * @file
 * Views integration for picc_attendance.
 */

/**
 * Implements hook_views_data_alter().
 *
 * Registers the 'attendance_action' Views field plugin on
 * commerce_order_item so the coach attendance view can render
 * per-row Check in / Check out / Reset buttons.
 */
function picc_attendance_views_data_alter(array &$data) {
  if (isset($data['commerce_order_item'])) {
    $data['commerce_order_item']['attendance_action'] = [
      'title' => t('Attendance action'),
      'help' => t('Renders check-in / check-out / reset buttons for this order item.'),
      'field' => [
        'id' => 'attendance_action',
      ],
    ];
  }
}

<?php

/**
 * @file
 * Views integration for picc_attendance.
 */

/**
 * Implements hook_views_data_alter().
 *
 * - Registers the 'attendance_action' Views field plugin on
 *   commerce_order_item so the coach attendance view can render
 *   per-row Check in / Check out / Reset buttons.
 * - Overrides the auto-derived string filter/sort on the attendance
 *   date base field with proper datetime handlers so the admin
 *   history view can expose From/To date filters. Drupal's default
 *   EntityViewsData emits string handlers for varchar-backed base
 *   fields, which silently drops any `plugin_id: datetime` filter.
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

  if (isset($data['picc_attendance']['date'])) {
    $data['picc_attendance']['date']['field']['id'] = 'field';
    $data['picc_attendance']['date']['filter']['id'] = 'datetime';
    $data['picc_attendance']['date']['filter']['field_name'] = 'date';
    $data['picc_attendance']['date']['filter']['entity_type'] = 'picc_attendance';
    $data['picc_attendance']['date']['sort']['id'] = 'datetime';
    $data['picc_attendance']['date']['sort']['field_name'] = 'date';
    $data['picc_attendance']['date']['sort']['entity_type'] = 'picc_attendance';
    $data['picc_attendance']['date']['argument']['id'] = 'datetime';
    $data['picc_attendance']['date']['argument']['field_name'] = 'date';
    $data['picc_attendance']['date']['argument']['entity_type'] = 'picc_attendance';
  }
}

<?php

/**
 * @file
 * Views integration for the PICC Extended module.
 */

/**
 * Implements hook_views_data_alter().
 */
function picc_ext_views_data_alter(array &$data) {
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

  // Add a sale badge field to the commerce product table. It rolls up the
  // product's variations to show whether any session is currently on sale.
  if (isset($data['commerce_product_field_data'])) {
    $data['commerce_product_field_data']['picc_sale_badge'] = [
      'title' => t('Sale badge'),
      'help' => t('Shows a sale badge and end date when any session of this program is on sale.'),
      'field' => [
        'id' => 'picc_sale_badge',
      ],
    ];
  }

  // Add a struck-through sale price field to the product variation table.
  if (isset($data['commerce_product_variation_field_data'])) {
    $data['commerce_product_variation_field_data']['picc_sale_price'] = [
      'title' => t('Sale price (struck-through)'),
      'help' => t('Shows the original price struck through with the discounted price when the session is on sale.'),
      'field' => [
        'id' => 'picc_sale_price',
      ],
    ];
  }
}

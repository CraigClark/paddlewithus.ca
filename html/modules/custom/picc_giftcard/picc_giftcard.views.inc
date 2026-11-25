<?php

/**
 * @file
 * Views integration for PICC Gift cards.
 */

/**
 * Implements hook_views_data_alter().
 *
 * Gift card transactions store what they belong to as a reference type and
 * ID, not a real entity reference, so Views can't join them to orders on its
 * own (issue 3518916). These relationships fill that gap for the report.
 */
function picc_giftcard_views_data_alter(array &$data): void {
  if (!isset($data['commerce_giftcard_transaction'])) {
    return;
  }
  $data['commerce_giftcard_transaction']['picc_order'] = [
    'title' => t('Order the card was used on'),
    'help' => t('The order a redemption paid for. Empty for purchases and manual changes.'),
    'relationship' => [
      'base' => 'commerce_order',
      'base field' => 'order_id',
      'relationship field' => 'reference_id',
      'id' => 'standard',
      'label' => t('Order (redemption)'),
      'extra' => [
        ['left_field' => 'reference_type', 'value' => 'commerce_order'],
      ],
    ],
  ];
  $data['commerce_giftcard_transaction']['picc_order_item'] = [
    'title' => t('Order item the card was bought with'),
    'help' => t('The order line that bought the card. Empty for redemptions and manual changes.'),
    'relationship' => [
      'base' => 'commerce_order_item',
      'base field' => 'order_item_id',
      'relationship field' => 'reference_id',
      'id' => 'standard',
      'label' => t('Order item (purchase)'),
      'extra' => [
        ['left_field' => 'reference_type', 'value' => 'commerce_order_item'],
      ],
    ],
  ];
}

/**
 * Implements hook_views_pre_build().
 *
 * The admin search finds a code however it's typed: with dashes or spaces,
 * in lower case, or just part of it (the filter is set to "contains").
 */
function picc_giftcard_views_pre_build(\Drupal\views\ViewExecutable $view): void {
  if (!in_array($view->id(), ['commerce_giftcard_admin', 'commerce_giftcard_transactions_admin'], TRUE)) {
    return;
  }
  $input = $view->getExposedInput();
  if (!empty($input['code']) && is_string($input['code'])) {
    $input['code'] = \Drupal\picc_giftcard\GiftcardCode::normalize($input['code']);
    $view->setExposedInput($input);
  }
}

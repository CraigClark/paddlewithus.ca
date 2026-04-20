<?php

namespace Drupal\picc_registration\Plugin\views\field;

use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;
use Drupal\Core\Url;

/**
 * Renders Pass/Fail/Undo action buttons for swim test evaluation.
 *
 * @ViewsField("swim_test_action")
 */
class SwimTestAction extends FieldPluginBase {

  /**
   * {@inheritdoc}
   */
  public function query() {
    // No query needed — we read from the entity.
  }

  /**
   * {@inheritdoc}
   */
  public function render(ResultRow $values) {
    $order_item = $this->getEntity($values);

    if (!$order_item || $order_item->bundle() !== 'swim_test_registration') {
      return '';
    }

    $status = $order_item->get('field_swim_test_status')->value ?? 'pending';
    $item_id = $order_item->id();

    $url = Url::fromRoute('picc_registration.swim_test_mark', [
      'commerce_order_item' => $item_id,
    ]);
    $link = $url->toString();

    $dialog_opts = htmlspecialchars(json_encode(['width' => 400]), ENT_QUOTES);
    $output = '<div class="swim-test-actions flex gap-2 items-center">';

    switch ($status) {
      case 'pending':
        $output .= '<a href="' . $link . '" class="btn btn-primary btn-sm use-ajax" data-dialog-type="dialog" data-dialog-options=\'' . $dialog_opts . '\'>' . t('Evaluate') . '</a>';
        break;

      case 'passed':
        $output .= '<span class="badge badge-success">' . t('Passed') . '</span>';
        $output .= '<a href="' . $link . '" class="btn btn-ghost btn-sm use-ajax" data-dialog-type="dialog" data-dialog-options=\'' . $dialog_opts . '\'>' . t('Undo') . '</a>';
        break;

      case 'failed':
        $output .= '<span class="badge badge-warning">' . t('Failed') . '</span>';
        $output .= '<a href="' . $link . '" class="btn btn-ghost btn-sm use-ajax" data-dialog-type="dialog" data-dialog-options=\'' . $dialog_opts . '\'>' . t('Undo') . '</a>';
        break;

      case 'no_show':
        $output .= '<span class="badge badge-error">' . t('No show') . '</span>';
        $output .= '<a href="' . $link . '?action=resolve" class="btn btn-primary btn-sm use-ajax" data-dialog-type="dialog" data-dialog-options=\'' . $dialog_opts . '\'>' . t('Resolved') . '</a>';
        break;

      case 'excused':
        $output .= '<span class="badge badge-neutral">' . t('Resolved') . '</span>';
        $output .= '<a href="' . $link . '" class="btn btn-ghost btn-sm use-ajax" data-dialog-type="dialog" data-dialog-options=\'' . $dialog_opts . '\'>' . t('Undo') . '</a>';
        break;
    }

    $output .= '</div>';

    return [
      '#markup' => $output,
      '#attached' => [
        'library' => ['core/drupal.dialog.ajax'],
      ],
    ];
  }

}

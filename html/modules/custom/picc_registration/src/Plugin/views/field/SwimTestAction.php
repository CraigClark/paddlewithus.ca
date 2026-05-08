<?php

namespace Drupal\picc_registration\Plugin\views\field;

use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;
use Drupal\Core\Url;

/**
 * Renders a Resolve / Undo action button for the swim test no-show review
 * (admin/people/swim-test-no-shows). The view's status column already shows
 * the state, and the page title already says "no-show", so this column is
 * action-only — no badges duplicated here.
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
    $url = Url::fromRoute('picc_registration.swim_test_mark', [
      'commerce_order_item' => $order_item->id(),
    ])->toString();
    $dialog_opts = htmlspecialchars(json_encode(['width' => 400]), ENT_QUOTES);

    $label = NULL;
    $classes = 'button button--small use-ajax';
    $href = $url;
    switch ($status) {
      case 'no_show':
        $label = t('Resolve');
        $classes = 'button button--primary button--small use-ajax';
        $href = $url . '?action=resolve';
        break;

      case 'excused':
        $label = t('Undo');
        break;
    }

    if (!$label) {
      return '';
    }

    return [
      '#markup' => '<a href="' . $href . '" class="' . $classes . '" data-dialog-type="dialog" data-dialog-options=\'' . $dialog_opts . '\'>' . $label . '</a>',
      '#attached' => [
        'library' => ['core/drupal.dialog.ajax'],
      ],
    ];
  }

}

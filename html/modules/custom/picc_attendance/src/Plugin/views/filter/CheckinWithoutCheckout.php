<?php

declare(strict_types=1);

namespace Drupal\picc_attendance\Plugin\views\filter;

use Drupal\views\Attribute\ViewsFilter;
use Drupal\views\Plugin\views\filter\BooleanOperator;

/**
 * Filters attendance rows where check-in exists but check-out does not.
 *
 * Exposed as a single-checkbox boolean filter (parity with the stored
 * `checkout_without_checkin` filter). When the value is truthy the query
 * is constrained to rows where `check_in_at IS NOT NULL AND check_out_at
 * IS NULL`; when falsy the filter is a no-op.
 */
#[ViewsFilter("picc_checkin_without_checkout")]
class CheckinWithoutCheckout extends BooleanOperator {

  /**
   * {@inheritdoc}
   */
  public function query() {
    if (empty($this->value)) {
      return;
    }
    $this->ensureMyTable();
    $alias = $this->tableAlias;
    $condition = $this->query->getConnection()->condition('AND')
      ->isNotNull("$alias.check_in_at")
      ->isNull("$alias.check_out_at");
    $this->query->addWhere($this->options['group'], $condition);
  }

}

<?php

declare(strict_types=1);

namespace Drupal\picc_attendance;

use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\Sql\SqlContentEntityStorageSchema;

/**
 * Adds indexes required for attendance queries.
 *
 * - Composite unique (order_item, date): enforces one row per registration
 *   per day and speeds the coach view's LEFT JOIN.
 * - Single-column index on date: drives retention purge and admin history
 *   date-range filtering.
 *
 * The `date` column is a `datetime` field (varchar(20)). MySQL requires a
 * prefix length on varchar columns in composite keys, so we pass
 * `['date_value', 20]` in the spec.
 */
class AttendanceStorageSchema extends SqlContentEntityStorageSchema {

  /**
   * {@inheritdoc}
   */
  protected function getEntitySchema(ContentEntityTypeInterface $entity_type, $reset = FALSE) {
    $schema = parent::getEntitySchema($entity_type, $reset);
    $base_table = $this->storage->getBaseTable();

    if ($base_table && isset($schema[$base_table])) {
      // Single-property fields use the field name as the column name, not
      // `{field}_value`. `date` is a single-property datetime field; its
      // column in the base table is literally `date`. `order_item` is an
      // entity_reference field; its column is `order_item` (single-property
      // storage).
      $schema[$base_table]['unique keys']['picc_attendance__order_item_date'] = [
        'order_item',
        ['date', 20],
      ];
      $schema[$base_table]['indexes']['picc_attendance__date'] = [
        ['date', 20],
      ];
    }

    return $schema;
  }

}

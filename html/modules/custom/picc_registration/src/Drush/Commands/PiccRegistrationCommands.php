<?php

namespace Drupal\picc_registration\Drush\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Drush commands for PICC Registration maintenance.
 */
final class PiccRegistrationCommands extends DrushCommands {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct();
  }

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('entity_type.manager'),
    );
  }

  /**
   * Find and delete orphaned commerce order items (no parent order).
   */
  #[CLI\Command(name: 'picc:cleanup-orphans', aliases: ['picc-co'])]
  #[CLI\Option(name: 'dry-run', description: 'List orphans without deleting them.')]
  #[CLI\Usage(name: 'picc:cleanup-orphans --dry-run', description: 'Preview orphaned order items.')]
  #[CLI\Usage(name: 'picc:cleanup-orphans', description: 'Delete orphaned order items.')]
  public function cleanupOrphans(array $options = ['dry-run' => FALSE]): void {
    $dryRun = $options['dry-run'];

    // Find order item IDs whose order_id references a non-existent order.
    $connection = \Drupal::database();
    $query = $connection->select('commerce_order_item', 'oi');
    $query->leftJoin('commerce_order', 'o', 'oi.order_id = o.order_id');
    $query->fields('oi', ['order_item_id', 'order_id', 'title']);
    $query->isNull('o.order_id');
    $orphans = $query->execute()->fetchAll();

    if (empty($orphans)) {
      $this->io()->success('No orphaned order items found.');
      return;
    }

    $this->io()->title(sprintf('Found %d orphaned order item(s)', count($orphans)));

    $rows = [];
    foreach ($orphans as $orphan) {
      $rows[] = [$orphan->order_item_id, $orphan->order_id, $orphan->title];
    }
    $this->io()->table(['Order Item ID', 'Missing Order ID', 'Title'], $rows);

    if ($dryRun) {
      $this->io()->note('Dry run — no items were deleted.');
      return;
    }

    if (!$this->io()->confirm('Delete these orphaned order items?', FALSE)) {
      $this->io()->note('Aborted.');
      return;
    }

    $storage = $this->entityTypeManager->getStorage('commerce_order_item');
    $ids = array_column($orphans, 'order_item_id');
    $entities = $storage->loadMultiple($ids);
    $storage->delete($entities);

    $this->io()->success(sprintf('Deleted %d orphaned order item(s).', count($entities)));
  }

}

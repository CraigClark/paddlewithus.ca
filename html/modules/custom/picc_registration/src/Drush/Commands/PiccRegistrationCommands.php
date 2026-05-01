<?php

namespace Drupal\picc_registration\Drush\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\picc_registration\Service\SwimExemptionApplier;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Drush commands for PICC Registration maintenance.
 */
final class PiccRegistrationCommands extends DrushCommands {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly SwimExemptionApplier $swimExemptionApplier,
  ) {
    parent::__construct();
  }

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('entity_type.manager'),
      $container->get('picc_registration.swim_exemption_applier'),
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

  /**
   * Apply swim test exemptions to participants in flagged programs.
   *
   * Sweeps every activity_registration order item; for any whose program
   * has field_no_swim_test = TRUE, sets the participant's swim status to
   * 'exempt' (only if currently 'none' — does not overwrite real
   * evaluation outcomes).
   */
  #[CLI\Command(name: 'picc:apply-swim-exemptions', aliases: ['picc-ase'])]
  #[CLI\Usage(name: 'picc:apply-swim-exemptions', description: 'Apply exemptions to all current registrations.')]
  public function applySwimExemptions(): void {
    $stats = $this->swimExemptionApplier->applyToAll();

    if ($stats['programs'] === 0) {
      $this->io()->note('No programs are flagged with field_no_swim_test.');
      return;
    }

    $this->io()->success(sprintf(
      'Scanned %d registration(s) across %d flagged program(s); marked %d participant(s) exempt.',
      $stats['scanned'],
      $stats['programs'],
      $stats['updated'],
    ));
  }

}

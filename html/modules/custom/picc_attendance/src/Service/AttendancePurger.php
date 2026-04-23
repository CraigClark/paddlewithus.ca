<?php

declare(strict_types=1);

namespace Drupal\picc_attendance\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\State\StateInterface;
use Psr\Log\LoggerInterface;

/**
 * Purges attendance records older than the configured retention window.
 *
 * - Called once every 24h from hook_cron() (via runIfDue()).
 * - Retention window is picc_attendance.settings:retention_days (default 1095).
 * - State key `picc_attendance.last_purge_ts` gates frequency.
 * - Batch size kept small (500) so a single cron run completes quickly.
 * - Watchdog log on every run for governance ("row count trend" check).
 */
class AttendancePurger {

  private const STATE_KEY = 'picc_attendance.last_purge_ts';
  private const BATCH_SIZE = 500;
  private const MIN_INTERVAL_SECONDS = 60 * 60 * 23; // ~23 hours — slight slop.

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected StateInterface $state,
    protected ConfigFactoryInterface $configFactory,
    protected TimeInterface $time,
    protected LoggerInterface $logger,
  ) {}

  /**
   * Runs a purge if the last one was at least ~24h ago.
   *
   * Safe to call from every cron tick.
   */
  public function runIfDue(): void {
    $last = (int) $this->state->get(self::STATE_KEY, 0);
    $now = $this->time->getRequestTime();
    if ($last > 0 && ($now - $last) < self::MIN_INTERVAL_SECONDS) {
      return;
    }
    $this->run();
  }

  /**
   * Runs a purge unconditionally. Returns the number of rows deleted.
   */
  public function run(): int {
    $retention_days = $this->retentionDays();
    $cutoff = $this->cutoffDateString($retention_days);

    $storage = $this->entityTypeManager->getStorage('picc_attendance');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('date', $cutoff, '<')
      ->range(0, self::BATCH_SIZE)
      ->execute();

    $deleted = 0;
    if ($ids) {
      $entities = $storage->loadMultiple($ids);
      $storage->delete($entities);
      $deleted = count($entities);
    }

    $this->state->set(self::STATE_KEY, $this->time->getRequestTime());

    $this->logger->notice(
      'picc_attendance purge: deleted @count rows older than @cutoff (retention @days days).',
      [
        '@count' => $deleted,
        '@cutoff' => $cutoff,
        '@days' => $retention_days,
      ]
    );

    return $deleted;
  }

  /**
   * Timestamp (seconds since epoch) of the last completed purge, or 0.
   */
  public function getLastPurgeTimestamp(): int {
    return (int) $this->state->get(self::STATE_KEY, 0);
  }

  /**
   * Configured retention window in days (default 1095).
   */
  public function retentionDays(): int {
    $cfg = (int) $this->configFactory->get('picc_attendance.settings')->get('retention_days');
    return $cfg > 0 ? $cfg : 1095;
  }

  /**
   * Returns the cutoff date as Y-m-d. Rows with `date < cutoff` are purged.
   */
  protected function cutoffDateString(int $retention_days): string {
    $tz_name = $this->configFactory->get('system.date')->get('timezone.default') ?: date_default_timezone_get();
    return (new \DateTimeImmutable('@' . $this->time->getRequestTime()))
      ->setTimezone(new \DateTimeZone($tz_name))
      ->modify("-{$retention_days} days")
      ->format('Y-m-d');
  }

}

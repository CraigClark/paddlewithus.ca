<?php

declare(strict_types=1);

namespace Drupal\picc_attendance\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\picc_attendance\Entity\Attendance;
use Drupal\picc_attendance\Entity\AttendanceInterface;
use Psr\Log\LoggerInterface;

/**
 * Writes attendance records.
 *
 * Single place where new revisions are created for check-in, check-out,
 * and reset. Serialises concurrent writes with a per-(order_item, date)
 * lock so two coaches hitting the same participant at the same moment
 * don't double-write.
 *
 * Time-window enforcement lives in AttendanceAccessChecker — it runs at
 * route level before this service is reached. Callers must not bypass
 * that check.
 */
class AttendanceRecorder {

  private const LOCK_TIMEOUT = 15.0;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected LockBackendInterface $lock,
    protected TimeInterface $time,
    protected AccountInterface $currentUser,
    protected LoggerInterface $logger,
  ) {}

  /**
   * Records a check-in for the given order item, keyed to today.
   *
   * @param \Drupal\commerce_order\Entity\OrderItemInterface $order_item
   *   The order item (registration).
   * @param array $values
   *   - note: (optional) free-text note.
   *
   * @return \Drupal\picc_attendance\Entity\AttendanceInterface
   *   The saved attendance record.
   */
  public function recordCheckIn(OrderItemInterface $order_item, array $values): AttendanceInterface {
    return $this->withLock($order_item, function () use ($order_item, $values) {
      $attendance = $this->loadOrCreate($order_item);
      if (!$attendance->get('check_in_at')->isEmpty()) {
        // Already checked in — no-op write. Return current record.
        $this->logger->info('Skipped duplicate check-in for order_item @oi on @date (already checked in).', [
          '@oi' => $order_item->id(),
          '@date' => $attendance->getDateString(),
        ]);
        return $attendance;
      }
      $attendance->set('check_in_at', $this->time->getRequestTime());
      $attendance->set('check_in_by', $this->currentUser->id());
      $attendance->set('check_in_note', $values['note'] ?? NULL);
      $this->saveRevision(
        $attendance,
        $this->t('Check-in by @user', ['@user' => $this->currentUserLabel()])
      );
      $this->logger->notice('Check-in recorded for attendance @id (order_item @oi, participant @p).', [
        '@id' => $attendance->id(),
        '@oi' => $order_item->id(),
        '@p' => $attendance->get('participant')->target_id,
      ]);
      return $attendance;
    });
  }

  /**
   * Records a check-out for the given order item, keyed to today.
   *
   * @param array $values
   *   - pickup_name: string. Required.
   *   - pickup_source: one of AttendanceInterface::PICKUP_SOURCE_*.
   *   - note: (optional) free-text note.
   */
  public function recordCheckOut(OrderItemInterface $order_item, array $values): AttendanceInterface {
    return $this->withLock($order_item, function () use ($order_item, $values) {
      $attendance = $this->loadOrCreate($order_item);
      if (!$attendance->get('check_out_at')->isEmpty()) {
        $this->logger->info('Skipped duplicate check-out for order_item @oi on @date.', [
          '@oi' => $order_item->id(),
          '@date' => $attendance->getDateString(),
        ]);
        return $attendance;
      }
      $without_checkin = $attendance->get('check_in_at')->isEmpty();

      $attendance->set('check_out_at', $this->time->getRequestTime());
      $attendance->set('check_out_by', $this->currentUser->id());
      $attendance->set('check_out_pickup_name', $values['pickup_name'] ?? NULL);
      $attendance->set('check_out_pickup_source', $values['pickup_source'] ?? AttendanceInterface::PICKUP_SOURCE_FREETEXT);
      $attendance->set('check_out_note', $values['note'] ?? NULL);
      $attendance->set('checkout_without_checkin', $without_checkin ? 1 : 0);

      $this->saveRevision(
        $attendance,
        $this->t('Check-out by @user; pickup: @pickup', [
          '@user' => $this->currentUserLabel(),
          '@pickup' => $values['pickup_name'] ?? '(none)',
        ])
      );
      $this->logger->notice('Check-out recorded for attendance @id (order_item @oi, pickup "@pickup", without_checkin=@flag).', [
        '@id' => $attendance->id(),
        '@oi' => $order_item->id(),
        '@pickup' => $values['pickup_name'] ?? '(none)',
        '@flag' => $without_checkin ? '1' : '0',
      ]);
      return $attendance;
    });
  }

  /**
   * Resets a check-in or check-out slot.
   *
   * Clears that slot's fields and creates a new revision logging who
   * reset what and why. Prior revisions preserve the original values
   * for audit.
   */
  public function reset(AttendanceInterface $attendance, string $slot, string $reason): AttendanceInterface {
    $order_item = $attendance->getOrderItem();
    if (!$order_item) {
      throw new \RuntimeException('Attendance record missing order item — cannot reset.');
    }
    return $this->withLock($order_item, function () use ($attendance, $slot, $reason) {
      if ($slot === AttendanceInterface::SLOT_CHECK_IN) {
        $attendance->set('check_in_at', NULL);
        $attendance->set('check_in_by', NULL);
        $attendance->set('check_in_note', NULL);
        // If a check-out is already active, recompute the flag: without a
        // current check-in, that check-out is now "without check-in".
        if (!$attendance->get('check_out_at')->isEmpty()) {
          $attendance->set('checkout_without_checkin', 1);
        }
      }
      elseif ($slot === AttendanceInterface::SLOT_CHECK_OUT) {
        $attendance->set('check_out_at', NULL);
        $attendance->set('check_out_by', NULL);
        $attendance->set('check_out_pickup_name', NULL);
        $attendance->set('check_out_pickup_source', NULL);
        $attendance->set('check_out_note', NULL);
        $attendance->set('checkout_without_checkin', 0);
      }
      else {
        throw new \InvalidArgumentException('Invalid slot: ' . $slot);
      }

      $this->saveRevision(
        $attendance,
        $this->t('Reset @slot by @user: @reason', [
          '@slot' => $slot,
          '@user' => $this->currentUserLabel(),
          '@reason' => $reason,
        ])
      );
      $this->logger->notice('Reset @slot on attendance @id by user @uid.', [
        '@slot' => $slot,
        '@id' => $attendance->id(),
        '@uid' => $this->currentUser->id(),
      ]);
      return $attendance;
    });
  }

  /**
   * Returns the attendance record for (order_item, today), or NULL.
   */
  public function getTodaysRecord(OrderItemInterface $order_item): ?AttendanceInterface {
    $today = $this->today();
    $ids = $this->storage()->getQuery()
      ->accessCheck(FALSE)
      ->condition('order_item', $order_item->id())
      ->condition('date', $today)
      ->range(0, 1)
      ->execute();
    if (!$ids) {
      return NULL;
    }
    $id = reset($ids);
    return $this->storage()->load($id);
  }

  // ---------------------------------------------------------------------
  // Internals.
  // ---------------------------------------------------------------------

  /**
   * Loads today's record for the order item or creates a new one in-memory.
   *
   * The returned entity is not saved yet.
   */
  protected function loadOrCreate(OrderItemInterface $order_item): AttendanceInterface {
    $existing = $this->getTodaysRecord($order_item);
    if ($existing) {
      return $existing;
    }
    $today = $this->today();
    $participant_id = $order_item->get('field_participant')->target_id ?? NULL;
    $variation = $order_item->getPurchasedEntity();
    $product_id = ($variation && $variation->getProduct()) ? $variation->getProduct()->id() : NULL;

    /** @var \Drupal\picc_attendance\Entity\AttendanceInterface $attendance */
    $attendance = Attendance::create([
      'order_item' => $order_item->id(),
      'participant' => $participant_id,
      'product' => $product_id,
      'date' => $today,
    ]);
    return $attendance;
  }

  /**
   * Saves with a new revision and a revision log message.
   */
  protected function saveRevision(AttendanceInterface $attendance, string|\Stringable $message): void {
    $attendance->setNewRevision(TRUE);
    $attendance->setRevisionUserId((int) $this->currentUser->id());
    $attendance->setRevisionCreationTime($this->time->getRequestTime());
    $attendance->setRevisionLogMessage((string) $message);
    $attendance->save();
  }

  /**
   * Runs the callback while holding a per-(order_item, date) lock.
   *
   * Waits briefly and retries once if the lock is contended.
   */
  protected function withLock(OrderItemInterface $order_item, callable $fn) {
    $lock_name = 'picc_attendance_' . $order_item->id() . '_' . $this->today();
    if (!$this->lock->acquire($lock_name, self::LOCK_TIMEOUT)) {
      $this->lock->wait($lock_name, 10);
      if (!$this->lock->acquire($lock_name, self::LOCK_TIMEOUT)) {
        throw new \RuntimeException('Could not acquire attendance lock for order item ' . $order_item->id());
      }
    }
    try {
      return $fn();
    }
    finally {
      $this->lock->release($lock_name);
    }
  }

  /**
   * Storage shortcut.
   */
  protected function storage() {
    return $this->entityTypeManager->getStorage('picc_attendance');
  }

  /**
   * Today's date in site timezone as Y-m-d.
   */
  protected function today(): string {
    $tz = \Drupal::config('system.date')->get('timezone.default') ?: date_default_timezone_get();
    return (new \DateTimeImmutable('@' . $this->time->getRequestTime()))
      ->setTimezone(new \DateTimeZone($tz))
      ->format('Y-m-d');
  }

  /**
   * Returns a short label for the current user (for revision log messages).
   */
  protected function currentUserLabel(): string {
    if ($this->currentUser->isAnonymous()) {
      return 'anonymous';
    }
    return $this->currentUser->getDisplayName() . ' (uid ' . $this->currentUser->id() . ')';
  }

  /**
   * Translates a string (matches the $this->t() pattern used elsewhere).
   */
  protected function t(string $string, array $args = []): \Drupal\Core\StringTranslation\TranslatableMarkup {
    return new \Drupal\Core\StringTranslation\TranslatableMarkup($string, $args);
  }

}

<?php

declare(strict_types=1);

namespace Drupal\picc_attendance\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\picc_attendance\Entity\AttendanceInterface;

/**
 * Evaluates whether the current user may record attendance right now.
 *
 * Encapsulates the three rules from attendance-plan.md §3 and §4.4:
 * - the order item must be an activity_registration in a completed order
 * - the product must have field_track_attendance = 1
 * - today (site timezone) must fall within field_date_range [start, end]
 *
 * Admins and commerce managers with `administer picc attendance` bypass the
 * time-window rule. Coaches must have `record picc attendance` and satisfy
 * all three.
 *
 * Used from two surfaces:
 * - route-level _custom_access for /attendance/* forms
 * - the AttendanceAction Views field plugin to decide which buttons to render
 */
class AttendanceAccessChecker {

  /**
   * The entity type manager.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The time service.
   */
  protected TimeInterface $time;

  /**
   * The current user.
   */
  protected AccountInterface $currentUser;

  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    TimeInterface $time,
    AccountInterface $current_user,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->time = $time;
    $this->currentUser = $current_user;
  }

  /**
   * Returns today's date in site timezone as a Y-m-d string.
   */
  public function today(): string {
    return (new \DateTimeImmutable('@' . $this->time->getRequestTime()))
      ->setTimezone($this->siteTimezone())
      ->format('Y-m-d');
  }

  /**
   * Site timezone as configured in system.date:timezone.default.
   */
  protected function siteTimezone(): \DateTimeZone {
    $tz = \Drupal::config('system.date')->get('timezone.default') ?: date_default_timezone_get();
    return new \DateTimeZone($tz);
  }

  /**
   * Whether an order item is eligible for attendance tracking.
   *
   * Checks bundle, product flag, and order state. Does NOT evaluate the
   * date window — callers add that check where needed.
   */
  public function isOrderItemEligible(OrderItemInterface $order_item): bool {
    if ($order_item->bundle() !== 'activity_registration') {
      return FALSE;
    }

    $order = $order_item->getOrder();
    if (!$order || !in_array($order->getState()->getId(), ['completed', 'fulfillment'], TRUE)) {
      return FALSE;
    }

    $variation = $order_item->getPurchasedEntity();
    if (!$variation) {
      return FALSE;
    }
    $product = $variation->getProduct();
    if (!$product || !$product->hasField('field_track_attendance')) {
      return FALSE;
    }
    return (bool) $product->get('field_track_attendance')->value;
  }

  /**
   * Whether today is within the variation's date range.
   */
  public function isTodayInSessionWindow(OrderItemInterface $order_item): bool {
    $variation = $order_item->getPurchasedEntity();
    if (!$variation || !$variation->hasField('field_date_range') || $variation->get('field_date_range')->isEmpty()) {
      return FALSE;
    }
    $start = $variation->get('field_date_range')->value;
    $end = $variation->get('field_date_range')->end_value;
    if (!$start || !$end) {
      return FALSE;
    }
    $today = $this->today();
    // field_date_range is datetime_type: date, so values are Y-m-d strings.
    return ($today >= substr($start, 0, 10)) && ($today <= substr($end, 0, 10));
  }

  /**
   * Can the given user record check-in for the given order item right now?
   */
  public function canCheckIn(OrderItemInterface $order_item, AccountInterface $account): AccessResultInterface {
    // Full bypass: admins and managers (anyone with history access) can
    // act outside the date window, e.g. to correct records post-hoc.
    if ($account->hasPermission('administer picc attendance')
      || $account->hasPermission('view picc attendance history')) {
      if (!$this->isOrderItemEligible($order_item)) {
        return AccessResult::forbidden('Order item not eligible for attendance.')
          ->addCacheableDependency($order_item);
      }
      return AccessResult::allowed()
        ->cachePerPermissions()
        ->addCacheableDependency($order_item);
    }
    if (!$account->hasPermission('record picc attendance')) {
      return AccessResult::forbidden('Missing record picc attendance permission.')
        ->cachePerPermissions();
    }
    if (!$this->isOrderItemEligible($order_item)) {
      return AccessResult::forbidden('Order item not eligible for attendance.')
        ->addCacheableDependency($order_item);
    }
    if (!$this->isTodayInSessionWindow($order_item)) {
      return AccessResult::forbidden('Session not active today.')
        ->addCacheableDependency($order_item)
        ->setCacheMaxAge(0);
    }
    return AccessResult::allowed()
      ->cachePerPermissions()
      ->addCacheableDependency($order_item)
      ->setCacheMaxAge(0);
  }

  /**
   * Can the given user record check-out for the given order item right now?
   *
   * v1 uses the same rule as check-in (strict today, no grace day) per the
   * decision locked in plan §3.
   */
  public function canCheckOut(OrderItemInterface $order_item, AccountInterface $account): AccessResultInterface {
    return $this->canCheckIn($order_item, $account);
  }

  /**
   * Can the given user reset the given attendance slot right now?
   */
  public function canReset(AttendanceInterface $attendance, string $slot, AccountInterface $account): AccessResultInterface {
    if (!in_array($slot, [AttendanceInterface::SLOT_CHECK_IN, AttendanceInterface::SLOT_CHECK_OUT], TRUE)) {
      return AccessResult::forbidden('Invalid slot.');
    }
    if ($account->hasPermission('administer picc attendance')
      || $account->hasPermission('view picc attendance history')) {
      // Only allow resetting a slot that actually has a value.
      $field = $slot === AttendanceInterface::SLOT_CHECK_IN ? 'check_in_at' : 'check_out_at';
      if ($attendance->get($field)->isEmpty()) {
        return AccessResult::forbidden('Slot has nothing to reset.')
          ->addCacheableDependency($attendance);
      }
      return AccessResult::allowed()
        ->cachePerPermissions()
        ->addCacheableDependency($attendance);
    }
    if (!$account->hasPermission('record picc attendance')) {
      return AccessResult::forbidden('Missing record picc attendance permission.')
        ->cachePerPermissions();
    }
    if ($attendance->getDateString() !== $this->today()) {
      return AccessResult::forbidden('Reset only allowed on the day of attendance.')
        ->addCacheableDependency($attendance)
        ->setCacheMaxAge(0);
    }
    // Only allow resetting a slot that actually has a value.
    $field = $slot === AttendanceInterface::SLOT_CHECK_IN ? 'check_in_at' : 'check_out_at';
    if ($attendance->get($field)->isEmpty()) {
      return AccessResult::forbidden('Slot has nothing to reset.')
        ->addCacheableDependency($attendance);
    }
    return AccessResult::allowed()
      ->cachePerPermissions()
      ->addCacheableDependency($attendance)
      ->setCacheMaxAge(0);
  }

  // ---------------------------------------------------------------------
  // Route access callbacks — referenced from picc_attendance.routing.yml.
  // Drupal injects $account and upcasts route parameters automatically.
  // ---------------------------------------------------------------------

  /**
   * Route access for /attendance/check-in/{commerce_order_item}.
   */
  public function accessCheckInRoute(AccountInterface $account, OrderItemInterface $commerce_order_item): AccessResultInterface {
    return $this->canCheckIn($commerce_order_item, $account);
  }

  /**
   * Route access for /attendance/check-out/{commerce_order_item}.
   */
  public function accessCheckOutRoute(AccountInterface $account, OrderItemInterface $commerce_order_item): AccessResultInterface {
    return $this->canCheckOut($commerce_order_item, $account);
  }

  /**
   * Route access for /attendance/reset/{picc_attendance}/{slot}.
   */
  public function accessResetRoute(AccountInterface $account, AttendanceInterface $picc_attendance, string $slot): AccessResultInterface {
    return $this->canReset($picc_attendance, $slot, $account);
  }

}

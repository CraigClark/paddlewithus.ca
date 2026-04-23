<?php

declare(strict_types=1);

namespace Drupal\picc_attendance;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Entity-level access control for PICC Attendance records.
 *
 * This handler only gates baseline permissions. Time-window enforcement
 * for coach-facing writes lives in AttendanceAccessChecker and runs at
 * the route level before the forms are reached.
 *
 * @see \Drupal\picc_attendance\Service\AttendanceAccessChecker
 */
class AttendanceAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account) {
    switch ($operation) {
      case 'view':
        return AccessResult::allowedIfHasPermissions(
          $account,
          ['administer picc attendance', 'view picc attendance history', 'record picc attendance'],
          'OR'
        );

      case 'update':
        // Admins can edit any record via the generic admin form.
        // Coaches update records only through the dedicated forms
        // (check-in / check-out / reset), which enforce time-window
        // and ownership rules before invoking the recorder service.
        return AccessResult::allowedIfHasPermissions(
          $account,
          ['administer picc attendance', 'record picc attendance'],
          'OR'
        );

      case 'delete':
        return AccessResult::allowedIfHasPermission($account, 'administer picc attendance');
    }

    return AccessResult::neutral();
  }

  /**
   * {@inheritdoc}
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL) {
    return AccessResult::allowedIfHasPermissions(
      $account,
      ['administer picc attendance', 'record picc attendance'],
      'OR'
    );
  }

}

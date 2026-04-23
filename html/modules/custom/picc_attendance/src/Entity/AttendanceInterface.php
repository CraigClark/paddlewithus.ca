<?php

declare(strict_types=1);

namespace Drupal\picc_attendance\Entity;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\Core\Entity\RevisionLogInterface;

/**
 * Provides an interface for the PICC Attendance content entity.
 *
 * Represents one attendance record for a (participant, program-day) pair.
 * Keyed by (order_item, date). Revisions capture every write (check-in,
 * check-out, reset) for governance auditing.
 */
interface AttendanceInterface extends ContentEntityInterface, EntityChangedInterface, RevisionLogInterface {

  /**
   * Slot names used by reset operations.
   */
  public const SLOT_CHECK_IN = 'check_in';
  public const SLOT_CHECK_OUT = 'check_out';

  /**
   * Pickup source enum values for check_out_pickup_source.
   */
  public const PICKUP_SOURCE_CONTACT_1 = 'contact_1';
  public const PICKUP_SOURCE_CONTACT_2 = 'contact_2';
  public const PICKUP_SOURCE_CONTACT_3 = 'contact_3';
  public const PICKUP_SOURCE_FREETEXT = 'freetext';

}

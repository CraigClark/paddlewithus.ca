<?php

declare(strict_types=1);

namespace Drupal\picc_attendance\Entity;

use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\RevisionableContentEntityBase;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Defines the PICC Attendance content entity.
 *
 * One record per (order_item, date). Revisionable — every write
 * (check-in, check-out, reset) creates a new revision with a
 * revision_log_message for audit.
 *
 * @ContentEntityType(
 *   id = "picc_attendance",
 *   label = @Translation("Attendance record"),
 *   label_singular = @Translation("attendance record"),
 *   label_plural = @Translation("attendance records"),
 *   label_collection = @Translation("Attendance records"),
 *   label_count = @PluralTranslation(
 *     singular = "@count attendance record",
 *     plural = "@count attendance records"
 *   ),
 *   base_table = "picc_attendance",
 *   revision_table = "picc_attendance_revision",
 *   revisionable = TRUE,
 *   translatable = FALSE,
 *   admin_permission = "administer picc attendance",
 *   handlers = {
 *     "view_builder" = "Drupal\Core\Entity\EntityViewBuilder",
 *     "list_builder" = "Drupal\picc_attendance\AttendanceListBuilder",
 *     "views_data" = "Drupal\views\EntityViewsData",
 *     "access" = "Drupal\picc_attendance\AttendanceAccessControlHandler",
 *     "storage_schema" = "Drupal\picc_attendance\AttendanceStorageSchema",
 *     "form" = {
 *       "default" = "Drupal\Core\Entity\ContentEntityForm",
 *       "edit" = "Drupal\Core\Entity\ContentEntityForm",
 *       "delete" = "Drupal\Core\Entity\ContentEntityDeleteForm"
 *     },
 *     "route_provider" = {
 *       "html" = "Drupal\Core\Entity\Routing\AdminHtmlRouteProvider"
 *     }
 *   },
 *   entity_keys = {
 *     "id" = "id",
 *     "revision" = "revision_id",
 *     "uuid" = "uuid"
 *   },
 *   revision_metadata_keys = {
 *     "revision_user" = "revision_user",
 *     "revision_created" = "revision_created",
 *     "revision_log_message" = "revision_log_message"
 *   },
 *   links = {
 *     "canonical" = "/admin/people/attendance/{picc_attendance}",
 *     "edit-form" = "/admin/people/attendance/{picc_attendance}/edit",
 *     "delete-form" = "/admin/people/attendance/{picc_attendance}/delete",
 *     "collection" = "/admin/people/attendance"
 *   }
 * )
 */
class Attendance extends RevisionableContentEntityBase implements AttendanceInterface {

  use EntityChangedTrait;
  use StringTranslationTrait;

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type) {
    $fields = parent::baseFieldDefinitions($entity_type);

    // Order item (required). Links the attendance record to the registration
    // (and through it to the participant, variation, product, and order).
    $fields['order_item'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Order item'))
      ->setDescription(t('The order item (registration) this attendance record belongs to.'))
      ->setSetting('target_type', 'commerce_order_item')
      ->setSetting('handler', 'default')
      ->setSetting('handler_settings', [
        'target_bundles' => ['activity_registration' => 'activity_registration'],
      ])
      ->setRequired(TRUE)
      ->setRevisionable(TRUE)
      ->setDisplayOptions('view', ['label' => 'inline', 'weight' => 0])
      ->setDisplayOptions('form', ['type' => 'entity_reference_autocomplete', 'weight' => 0])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Participant (denormalized from the order item's field_participant).
    // Stored here so the record survives cleanly in history views even if
    // the underlying order item is later edited.
    $fields['participant'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Participant'))
      ->setDescription(t('Participant profile at the time of record creation.'))
      ->setSetting('target_type', 'profile')
      ->setSetting('handler', 'default:profile')
      ->setSetting('handler_settings', [
        'target_bundles' => ['participant' => 'participant'],
      ])
      ->setRequired(TRUE)
      ->setRevisionable(TRUE)
      ->setDisplayOptions('view', ['label' => 'inline', 'weight' => 1])
      ->setDisplayOptions('form', ['type' => 'entity_reference_autocomplete', 'weight' => 1])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Product (denormalized from the variation). Survives order item delete.
    $fields['product'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Program'))
      ->setDescription(t('Program (Commerce product) at the time of record creation.'))
      ->setSetting('target_type', 'commerce_product')
      ->setSetting('handler', 'default')
      ->setSetting('handler_settings', [
        'target_bundles' => ['activity' => 'activity'],
      ])
      ->setRequired(TRUE)
      ->setRevisionable(TRUE)
      ->setDisplayOptions('view', ['label' => 'inline', 'weight' => 2])
      ->setDisplayOptions('form', ['type' => 'entity_reference_autocomplete', 'weight' => 2])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Date (date-only, site timezone). Partition key — drives uniqueness
    // and the 3-year retention purge query.
    $fields['date'] = BaseFieldDefinition::create('datetime')
      ->setLabel(t('Date'))
      ->setDescription(t('Calendar day of attendance, site timezone.'))
      ->setSetting('datetime_type', 'date')
      ->setRequired(TRUE)
      ->setRevisionable(TRUE)
      ->setDisplayOptions('view', ['label' => 'inline', 'type' => 'datetime_default', 'weight' => 3])
      ->setDisplayOptions('form', ['type' => 'datetime_default', 'weight' => 3])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Check-in fields.
    $fields['check_in_at'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(t('Check-in time'))
      ->setDescription(t('When the coach tapped Check in. Null until checked in.'))
      ->setRevisionable(TRUE)
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'timestamp',
        'weight' => 10,
        'settings' => ['date_format' => 'custom', 'custom_date_format' => 'Y-m-d H:i'],
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['check_in_by'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Checked in by'))
      ->setDescription(t('Coach who recorded the check-in.'))
      ->setSetting('target_type', 'user')
      ->setSetting('handler', 'default')
      ->setRevisionable(TRUE)
      ->setDisplayOptions('view', ['label' => 'inline', 'weight' => 11])
      ->setDisplayConfigurable('view', TRUE);

    $fields['check_in_note'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Check-in note'))
      ->setDescription(t('Optional free-text note captured at check-in.'))
      ->setRevisionable(TRUE)
      ->setDisplayOptions('view', ['label' => 'inline', 'weight' => 12])
      ->setDisplayConfigurable('view', TRUE);

    // Check-out fields.
    $fields['check_out_at'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(t('Check-out time'))
      ->setDescription(t('When the coach tapped Check out. Null until checked out.'))
      ->setRevisionable(TRUE)
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'timestamp',
        'weight' => 20,
        'settings' => ['date_format' => 'custom', 'custom_date_format' => 'Y-m-d H:i'],
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['check_out_by'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Checked out by'))
      ->setDescription(t('Coach who recorded the check-out.'))
      ->setSetting('target_type', 'user')
      ->setSetting('handler', 'default')
      ->setRevisionable(TRUE)
      ->setDisplayOptions('view', ['label' => 'inline', 'weight' => 21])
      ->setDisplayConfigurable('view', TRUE);

    $fields['check_out_pickup_name'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Pickup name'))
      ->setDescription(t('Name of the person who picked up the participant.'))
      ->setRevisionable(TRUE)
      ->setSetting('max_length', 255)
      ->setDisplayOptions('view', ['label' => 'inline', 'weight' => 22])
      ->setDisplayConfigurable('view', TRUE);

    $fields['check_out_pickup_source'] = BaseFieldDefinition::create('list_string')
      ->setLabel(t('Pickup source'))
      ->setDescription(t('Which emergency contact or free-text option was chosen.'))
      ->setRevisionable(TRUE)
      ->setSetting('allowed_values', [
        AttendanceInterface::PICKUP_SOURCE_CONTACT_1 => 'Emergency contact 1',
        AttendanceInterface::PICKUP_SOURCE_CONTACT_2 => 'Emergency contact 2',
        AttendanceInterface::PICKUP_SOURCE_CONTACT_3 => 'Emergency contact 3',
        AttendanceInterface::PICKUP_SOURCE_SELF => 'Self checkout',
        AttendanceInterface::PICKUP_SOURCE_FREETEXT => 'Other',
      ])
      ->setDisplayOptions('view', ['label' => 'inline', 'weight' => 23])
      ->setDisplayConfigurable('view', TRUE);

    $fields['check_out_note'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Check-out note'))
      ->setDescription(t('Optional free-text note captured at check-out.'))
      ->setRevisionable(TRUE)
      ->setDisplayOptions('view', ['label' => 'inline', 'weight' => 24])
      ->setDisplayConfigurable('view', TRUE);

    $fields['checkout_without_checkin'] = BaseFieldDefinition::create('boolean')
      ->setLabel(t('Checked out without prior check-in'))
      ->setDescription(t('True if at the moment of check-out no check-in was active. Reflects final non-reset state.'))
      ->setRevisionable(TRUE)
      ->setDefaultValue(FALSE)
      ->setDisplayOptions('view', ['label' => 'inline', 'weight' => 25])
      ->setDisplayConfigurable('view', TRUE);

    // Created / changed timestamps (standard).
    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Created'))
      ->setDescription(t('When the record was created.'))
      ->setRevisionable(TRUE);

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(t('Changed'))
      ->setDescription(t('When the record was last changed.'))
      ->setRevisionable(TRUE);

    return $fields;
  }

  /**
   * {@inheritdoc}
   */
  public function label() {
    $date = $this->getDateString() ?? '—';
    $participant = $this->get('participant')->entity;
    $name = $participant ? $participant->label() : (string) $this->t('Participant');
    return sprintf('%s — %s', $date, $name);
  }

  /**
   * Returns the participant profile or NULL.
   */
  public function getParticipant() {
    return $this->get('participant')->entity;
  }

  /**
   * Returns the order item or NULL.
   */
  public function getOrderItem() {
    return $this->get('order_item')->entity;
  }

  /**
   * Returns the product or NULL.
   */
  public function getProduct() {
    return $this->get('product')->entity;
  }

  /**
   * Returns the date as a Y-m-d string.
   */
  public function getDateString(): ?string {
    $val = $this->get('date')->value;
    return $val ? substr($val, 0, 10) : NULL;
  }

  /**
   * Whether a non-null check-in is currently active.
   */
  public function isCheckedIn(): bool {
    return !$this->get('check_in_at')->isEmpty();
  }

  /**
   * Whether a non-null check-out is currently active.
   */
  public function isCheckedOut(): bool {
    return !$this->get('check_out_at')->isEmpty();
  }

}

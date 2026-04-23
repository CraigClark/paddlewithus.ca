<?php

declare(strict_types=1);

namespace Drupal\picc_attendance;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Admin list builder for picc_attendance.
 *
 * Intended for admin/content managers. Coaches do not have access
 * to this page — they interact with attendance only through the
 * coach attendance view and modal forms.
 */
class AttendanceListBuilder extends EntityListBuilder {

  use StringTranslationTrait;

  /**
   * The date formatter service.
   */
  protected DateFormatterInterface $dateFormatter;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    EntityTypeInterface $entity_type,
    EntityStorageInterface $storage,
    DateFormatterInterface $date_formatter,
  ) {
    parent::__construct($entity_type, $storage);
    $this->dateFormatter = $date_formatter;
  }

  /**
   * {@inheritdoc}
   */
  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type) {
    return new static(
      $entity_type,
      $container->get('entity_type.manager')->getStorage($entity_type->id()),
      $container->get('date.formatter'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function buildHeader() {
    $header = [
      'date' => $this->t('Date'),
      'program' => $this->t('Program'),
      'participant' => $this->t('Participant'),
      'check_in' => $this->t('Check-in'),
      'check_out' => $this->t('Check-out'),
      'pickup' => $this->t('Pickup'),
      'flag' => $this->t('Flag'),
    ];
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity) {
    /** @var \Drupal\picc_attendance\Entity\AttendanceInterface $entity */
    $row['date'] = $entity->getDateString() ?? '—';

    $product = $entity->get('product')->entity;
    $row['program'] = $product ? $product->label() : '—';

    $participant = $entity->get('participant')->entity;
    $row['participant'] = $participant ? $participant->label() : '—';

    $check_in = $entity->get('check_in_at')->value;
    $row['check_in'] = $check_in ? $this->dateFormatter->format((int) $check_in, 'custom', 'Y-m-d H:i') : '—';

    $check_out = $entity->get('check_out_at')->value;
    $row['check_out'] = $check_out ? $this->dateFormatter->format((int) $check_out, 'custom', 'Y-m-d H:i') : '—';

    $row['pickup'] = $entity->get('check_out_pickup_name')->value ?: '—';

    $row['flag'] = $entity->get('checkout_without_checkin')->value
      ? $this->t('Checkout without check-in')
      : '';

    return $row + parent::buildRow($entity);
  }

}

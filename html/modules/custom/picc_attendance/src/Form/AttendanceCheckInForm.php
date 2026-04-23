<?php

declare(strict_types=1);

namespace Drupal\picc_attendance\Form;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\picc_attendance\Service\AttendanceRecorder;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Coach-facing check-in form, served in a modal dialog.
 *
 * Route: /attendance/check-in/{commerce_order_item}
 * Access: picc_attendance.access_checker:accessCheckInRoute (today-only
 * window + record permission, or admin bypass).
 */
class AttendanceCheckInForm extends FormBase implements ContainerInjectionInterface {

  public function __construct(
    protected AttendanceRecorder $recorder,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('picc_attendance.recorder'),
    );
  }

  public function getFormId(): string {
    return 'picc_attendance_check_in_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state, ?OrderItemInterface $commerce_order_item = NULL): array {
    if (!$commerce_order_item) {
      $form['error'] = ['#markup' => $this->t('Registration not found.')];
      return $form;
    }

    $form_state->set('order_item', $commerce_order_item);
    $name = $this->participantName($commerce_order_item);

    $form['#attached']['library'][] = 'core/drupal.dialog.ajax';

    $form['heading'] = [
      '#markup' => '<h3>' . $this->t('Check in @name', ['@name' => $name]) . '</h3>',
    ];

    $form['note'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Note (optional)'),
      '#description' => $this->t('Anything worth recording — e.g. "Arrived late". Do not record critical information like medication changes here; use the participant profile.'),
      '#rows' => 2,
    ];

    $form['actions'] = [
      '#type' => 'actions',
      'submit' => [
        '#type' => 'submit',
        '#value' => $this->t('Check in'),
        '#attributes' => ['class' => ['btn', 'btn-primary', 'btn-lg']],
      ],
    ];

    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    /** @var \Drupal\commerce_order\Entity\OrderItemInterface $order_item */
    $order_item = $form_state->get('order_item');
    $note = trim((string) $form_state->getValue('note'));

    $this->recorder->recordCheckIn($order_item, ['note' => $note !== '' ? $note : NULL]);

    $name = $this->participantName($order_item);
    $this->messenger()->addStatus($this->t('@name checked in.', ['@name' => $name]));

    $form_state->setRedirectUrl(Url::fromRoute('view.picc_attendance.attendance_page'));
  }

  /**
   * Returns the participant's display name from the order item, or a fallback.
   */
  protected function participantName(OrderItemInterface $order_item): string {
    $profile = $order_item->get('field_participant')->entity;
    if ($profile && !$profile->get('field_name')->isEmpty()) {
      $name_field = $profile->get('field_name')->first();
      $name = trim(($name_field->given ?? '') . ' ' . ($name_field->family ?? ''));
      if ($name !== '') {
        return $name;
      }
    }
    return (string) $this->t('Participant');
  }

}

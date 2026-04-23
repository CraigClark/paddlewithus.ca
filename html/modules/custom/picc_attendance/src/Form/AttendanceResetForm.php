<?php

declare(strict_types=1);

namespace Drupal\picc_attendance\Form;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\picc_attendance\Entity\AttendanceInterface;
use Drupal\picc_attendance\Service\AttendanceRecorder;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Reset a check-in or check-out slot on an attendance record.
 *
 * Resetting never deletes: it creates a new revision with the slot's
 * fields cleared and the revision_log_message documenting who reset
 * what and why. Prior revision preserves the original values for audit.
 *
 * Route: /attendance/reset/{picc_attendance}/{slot}
 */
class AttendanceResetForm extends FormBase implements ContainerInjectionInterface {

  public function __construct(
    protected AttendanceRecorder $recorder,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('picc_attendance.recorder'),
    );
  }

  public function getFormId(): string {
    return 'picc_attendance_reset_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state, ?AttendanceInterface $picc_attendance = NULL, ?string $slot = NULL): array {
    if (!$picc_attendance || !in_array($slot, [AttendanceInterface::SLOT_CHECK_IN, AttendanceInterface::SLOT_CHECK_OUT], TRUE)) {
      $form['error'] = ['#markup' => $this->t('Attendance record or slot not found.')];
      return $form;
    }

    $form_state->set('attendance', $picc_attendance);
    $form_state->set('slot', $slot);

    $form['#attached']['library'][] = 'core/drupal.dialog.ajax';

    $name = $picc_attendance->getParticipant() ? $picc_attendance->getParticipant()->label() : $this->t('Participant');
    $slot_label = $slot === AttendanceInterface::SLOT_CHECK_IN ? $this->t('check-in') : $this->t('check-out');

    $form['heading'] = [
      '#markup' => '<h3>' . $this->t('Reset @slot for @name', [
        '@slot' => $slot_label,
        '@name' => $name,
      ]) . '</h3>',
    ];

    $form['caution'] = [
      '#markup' => '<p>' . $this->t('The original @slot value will remain in the audit history. Enter a short reason so the record is traceable.', [
        '@slot' => $slot_label,
      ]) . '</p>',
    ];

    $form['reason'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Reason'),
      '#description' => $this->t('For example: "Clicked the wrong participant", "Entered by mistake".'),
      '#rows' => 2,
      '#required' => TRUE,
    ];

    $form['actions'] = [
      '#type' => 'actions',
      'submit' => [
        '#type' => 'submit',
        '#value' => $this->t('Reset'),
        '#attributes' => ['class' => ['btn', 'btn-warning']],
      ],
    ];

    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    /** @var \Drupal\picc_attendance\Entity\AttendanceInterface $attendance */
    $attendance = $form_state->get('attendance');
    $slot = (string) $form_state->get('slot');
    $reason = trim((string) $form_state->getValue('reason'));

    $this->recorder->reset($attendance, $slot, $reason);

    $slot_label = $slot === AttendanceInterface::SLOT_CHECK_IN ? $this->t('Check-in') : $this->t('Check-out');
    $this->messenger()->addStatus($this->t('@slot reset.', ['@slot' => $slot_label]));

    $form_state->setRedirectUrl(Url::fromRoute('view.picc_attendance.attendance_page'));
  }

}

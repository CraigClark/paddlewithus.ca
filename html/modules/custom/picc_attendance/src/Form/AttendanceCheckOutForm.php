<?php

declare(strict_types=1);

namespace Drupal\picc_attendance\Form;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\picc_attendance\Entity\AttendanceInterface;
use Drupal\picc_attendance\Service\AttendanceRecorder;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Coach-facing check-out form, served in a modal dialog.
 *
 * Radio list of authorised pickup contacts is derived from the participant
 * profile: contact is listed only when its name field is non-empty AND its
 * pickup boolean is TRUE. Defaults in this site mark pickup = 1, so the
 * expected case is that all three named contacts appear.
 *
 * "Other" radio reveals a free-text input (required when selected). If zero
 * contacts qualify, the radio widget is hidden and a caution + free-text
 * required input is shown instead — the coach can still check out, but the
 * choice is visibly flagged.
 *
 * Route: /attendance/check-out/{commerce_order_item}
 */
class AttendanceCheckOutForm extends FormBase implements ContainerInjectionInterface {

  public function __construct(
    protected AttendanceRecorder $recorder,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('picc_attendance.recorder'),
    );
  }

  public function getFormId(): string {
    return 'picc_attendance_check_out_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state, ?OrderItemInterface $commerce_order_item = NULL): array {
    if (!$commerce_order_item) {
      $form['error'] = ['#markup' => $this->t('Registration not found.')];
      return $form;
    }

    $form_state->set('order_item', $commerce_order_item);
    $name = $this->participantName($commerce_order_item);

    $contacts = $this->getEligibleContacts($commerce_order_item);
    $form_state->set('contacts', $contacts);

    $form['#attached']['library'][] = 'core/drupal.dialog.ajax';

    $form['heading'] = [
      '#markup' => '<h3>' . $this->t('Check out @name', ['@name' => $name]) . '</h3>',
    ];

    if (!$commerce_order_item->get('field_participant')->isEmpty()) {
      /** @var \Drupal\picc_attendance\Entity\AttendanceInterface|null $existing */
      $existing = $this->recorder->getTodaysRecord($commerce_order_item);
      if ($existing && !$existing->isCheckedIn()) {
        $form['warning_no_checkin'] = [
          '#markup' => '<div class="alert alert-warning" role="alert">'
            . $this->t('This participant was not checked in today. The check-out will be flagged as "without check-in".')
            . '</div>',
        ];
      }
    }

    if ($contacts) {
      // One or more authorised contacts — radio list with Self + Other.
      $options = [];
      foreach ($contacts as $source => $contact_name) {
        $options[$source] = $contact_name;
      }
      $options[AttendanceInterface::PICKUP_SOURCE_SELF] = $this->t('Self checkout (@name)', ['@name' => $name]);
      $options[AttendanceInterface::PICKUP_SOURCE_FREETEXT] = $this->t('Other (enter name)');

      $form['pickup_source'] = [
        '#type' => 'radios',
        '#title' => $this->t('Picked up by'),
        '#options' => $options,
        '#required' => TRUE,
        '#description' => $this->t('Select the person picking up @name. Only one person can be selected.', ['@name' => $name]),
      ];

      $form['pickup_freetext'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Name of other person'),
        '#maxlength' => 255,
        '#states' => [
          'visible' => [
            ':input[name="pickup_source"]' => ['value' => AttendanceInterface::PICKUP_SOURCE_FREETEXT],
          ],
          'required' => [
            ':input[name="pickup_source"]' => ['value' => AttendanceInterface::PICKUP_SOURCE_FREETEXT],
          ],
        ],
      ];
    }
    else {
      // Zero authorised contacts. Coach can still proceed with self checkout
      // or by entering a name manually.
      $form['no_contact_warning'] = [
        '#markup' => '<div class="alert alert-warning" role="alert">'
          . $this->t('No authorised emergency contacts are on file for @name. Select self checkout or enter the name of the person picking up manually. This will be flagged in the record.', ['@name' => $name])
          . '</div>',
      ];

      $form['pickup_source'] = [
        '#type' => 'radios',
        '#title' => $this->t('Picked up by'),
        '#options' => [
          AttendanceInterface::PICKUP_SOURCE_SELF => $this->t('Self checkout (@name)', ['@name' => $name]),
          AttendanceInterface::PICKUP_SOURCE_FREETEXT => $this->t('Other (enter name)'),
        ],
        '#required' => TRUE,
      ];

      $form['pickup_freetext'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Name of other person'),
        '#maxlength' => 255,
        '#states' => [
          'visible' => [
            ':input[name="pickup_source"]' => ['value' => AttendanceInterface::PICKUP_SOURCE_FREETEXT],
          ],
          'required' => [
            ':input[name="pickup_source"]' => ['value' => AttendanceInterface::PICKUP_SOURCE_FREETEXT],
          ],
        ],
      ];
    }

    $form['note'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Note'),
      '#description' => $this->t('Anything worth recording — e.g. "Biked home with parent consent", "Sick, picked up early". Required when "Other" is selected so the reason for an unlisted pickup is on the record.'),
      '#rows' => 2,
      '#states' => [
        'required' => [
          ':input[name="pickup_source"]' => ['value' => AttendanceInterface::PICKUP_SOURCE_FREETEXT],
        ],
      ],
    ];

    $form['actions'] = [
      '#type' => 'actions',
      'submit' => [
        '#type' => 'submit',
        '#value' => $this->t('Check out'),
        '#attributes' => ['class' => ['btn', 'btn-primary', 'btn-lg']],
      ],
    ];

    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $source = $form_state->getValue('pickup_source');
    $freetext = trim((string) $form_state->getValue('pickup_freetext'));
    $note = trim((string) $form_state->getValue('note'));
    if ($source === AttendanceInterface::PICKUP_SOURCE_FREETEXT) {
      if ($freetext === '') {
        $form_state->setErrorByName('pickup_freetext', $this->t('Please enter the name of the person picking up.'));
      }
      if ($note === '') {
        $form_state->setErrorByName('note', $this->t('Please add a note explaining the pickup since "Other" was selected.'));
      }
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    /** @var \Drupal\commerce_order\Entity\OrderItemInterface $order_item */
    $order_item = $form_state->get('order_item');
    $contacts = $form_state->get('contacts') ?? [];
    $source = $form_state->getValue('pickup_source');
    $freetext = trim((string) $form_state->getValue('pickup_freetext'));
    $note = trim((string) $form_state->getValue('note'));

    if ($source === AttendanceInterface::PICKUP_SOURCE_FREETEXT) {
      $pickup_name = $freetext;
    }
    elseif ($source === AttendanceInterface::PICKUP_SOURCE_SELF) {
      $pickup_name = $this->participantName($order_item);
    }
    else {
      $pickup_name = $contacts[$source] ?? '';
    }

    $this->recorder->recordCheckOut($order_item, [
      'pickup_name' => $pickup_name,
      'pickup_source' => $source,
      'note' => $note !== '' ? $note : NULL,
    ]);

    $name = $this->participantName($order_item);
    $this->messenger()->addStatus($this->t('@name checked out with @pickup.', [
      '@name' => $name,
      '@pickup' => $pickup_name,
    ]));

    $form_state->setRedirectUrl(Url::fromRoute('view.picc_attendance.attendance_page'));
  }

  /**
   * Returns authorised emergency contacts keyed by pickup source id.
   *
   * @return array
   *   Map of PICKUP_SOURCE_CONTACT_* => contact name, excluding any contact
   *   whose name is empty or whose pickup flag is FALSE.
   */
  protected function getEligibleContacts(OrderItemInterface $order_item): array {
    $profile = $order_item->get('field_participant')->entity;
    if (!$profile) {
      return [];
    }
    $out = [];
    $map = [
      AttendanceInterface::PICKUP_SOURCE_CONTACT_1 => ['field_emercency_contact_1_name', 'field_emercency_contact_1_pickup'],
      AttendanceInterface::PICKUP_SOURCE_CONTACT_2 => ['field_emercency_contact_2_name', 'field_emercency_contact_2_pickup'],
      AttendanceInterface::PICKUP_SOURCE_CONTACT_3 => ['field_emercency_contact_3_name', 'field_emercency_contact_3_pickup'],
    ];
    foreach ($map as $source => [$name_field, $pickup_field]) {
      if (!$profile->hasField($name_field) || !$profile->hasField($pickup_field)) {
        continue;
      }
      $name = trim((string) $profile->get($name_field)->value);
      $pickup_ok = (bool) $profile->get($pickup_field)->value;
      if ($name !== '' && $pickup_ok) {
        $out[$source] = $name;
      }
    }
    return $out;
  }

  /**
   * Returns the participant's display name.
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

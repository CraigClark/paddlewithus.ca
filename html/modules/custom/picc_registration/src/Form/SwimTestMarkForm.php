<?php

namespace Drupal\picc_registration\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\profile\Entity\Profile;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\CloseDialogCommand;
use Drupal\Core\Ajax\RedirectCommand;
use Drupal\Core\Url;

/**
 * Form for coaches to mark swim test participants as passed, failed, or undo.
 */
class SwimTestMarkForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'picc_swim_test_mark_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, OrderItemInterface $commerce_order_item = NULL) {
    if (!$commerce_order_item) {
      $form['error'] = ['#markup' => $this->t('Registration not found.')];
      return $form;
    }

    $form_state->set('order_item', $commerce_order_item);

    // Get participant info.
    $profile = $commerce_order_item->get('field_participant')->entity;
    $name = $this->t('Unknown');
    if ($profile) {
      $name_field = $profile->get('field_name')->first();
      if ($name_field) {
        $name = trim(($name_field->given ?? '') . ' ' . ($name_field->family ?? ''));
      }
    }

    $current_status = $commerce_order_item->get('field_swim_test_status')->value ?? 'pending';

    $form['participant_name'] = [
      '#type' => 'markup',
      '#markup' => '<h3>' . $this->t('Participant: @name', ['@name' => $name]) . '</h3>',
    ];

    $form['current_status'] = [
      '#type' => 'markup',
      '#markup' => '<p>' . $this->t('Current status: <strong>@status</strong>', [
        '@status' => ucfirst($current_status),
      ]) . '</p>',
    ];

    if ($current_status === 'pending') {
      // Date field with disclosure for backdating.
      $form['evaluation_date'] = [
        '#type' => 'date',
        '#title' => $this->t('Date'),
        '#default_value' => date('Y-m-d'),
        '#description' => $this->t('Change only if backdating a previous evaluation.'),
      ];

      $form['actions']['pass'] = [
        '#type' => 'submit',
        '#value' => $this->t('Pass'),
        '#name' => 'pass',
        '#attributes' => [
          'class' => ['btn', 'btn-primary', 'btn-lg', 'mr-2'],
        ],
      ];

      $form['actions']['fail'] = [
        '#type' => 'submit',
        '#value' => $this->t('Fail'),
        '#name' => 'fail',
        '#attributes' => [
          'class' => ['btn', 'btn-outline', 'btn-warning', 'btn-lg'],
        ],
      ];
    }
    else {
      // Already evaluated — show undo.
      $form['actions']['undo'] = [
        '#type' => 'submit',
        '#value' => $this->t('Undo'),
        '#name' => 'undo',
        '#attributes' => [
          'class' => ['btn', 'btn-ghost', 'btn-lg'],
        ],
      ];
    }

    $form['actions']['#type'] = 'actions';

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $order_item = $form_state->get('order_item');
    $triggering_element = $form_state->getTriggeringElement();
    $action = $triggering_element['#name'] ?? '';

    $profile = $order_item->get('field_participant')->entity;
    $name = $this->t('Participant');
    if ($profile) {
      $name_field = $profile->get('field_name')->first();
      if ($name_field) {
        $name = trim(($name_field->given ?? '') . ' ' . ($name_field->family ?? ''));
      }
    }

    switch ($action) {
      case 'pass':
        $date = $form_state->getValue('evaluation_date') ?? date('Y-m-d');
        $order_item->set('field_swim_test_status', 'passed');
        $order_item->save();

        if ($profile) {
          $profile->set('field_swim_status', 'passed');
          $profile->set('field_swim_test_passed_date', $date);
          $profile->set('field_swim_test_evaluated_by', $this->currentUser()->id());
          $profile->save();
        }

        $this->messenger()->addStatus($this->t('@name marked as passed.', ['@name' => $name]));
        \Drupal::logger('picc_registration')->notice('Swim test PASSED: @name by @coach', [
          '@name' => $name,
          '@coach' => $this->currentUser()->getDisplayName(),
        ]);
        break;

      case 'fail':
        $order_item->set('field_swim_test_status', 'failed');
        $order_item->save();

        $this->messenger()->addStatus($this->t('@name marked as failed.', ['@name' => $name]));
        \Drupal::logger('picc_registration')->notice('Swim test FAILED: @name by @coach', [
          '@name' => $name,
          '@coach' => $this->currentUser()->getDisplayName(),
        ]);
        break;

      case 'undo':
        $previous_status = $order_item->get('field_swim_test_status')->value;
        $order_item->set('field_swim_test_status', 'pending');
        $order_item->save();

        // If was passed, clear profile swim fields.
        if ($previous_status === 'passed' && $profile) {
          $profile->set('field_swim_status', 'none');
          $profile->set('field_swim_test_passed_date', NULL);
          $profile->set('field_swim_test_evaluated_by', NULL);
          $profile->save();
        }

        $this->messenger()->addStatus($this->t('@name reset to pending.', ['@name' => $name]));
        \Drupal::logger('picc_registration')->notice('Swim test UNDO: @name by @coach (was @status)', [
          '@name' => $name,
          '@coach' => $this->currentUser()->getDisplayName(),
          '@status' => $previous_status,
        ]);
        break;
    }

    // Redirect back to the swim test roster.
    $form_state->setRedirectUrl(Url::fromRoute('view.swim_test_roster.page_1'));
  }

}

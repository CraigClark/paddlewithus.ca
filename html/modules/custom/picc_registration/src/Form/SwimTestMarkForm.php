<?php

namespace Drupal\picc_registration\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\profile\Entity\ProfileInterface;
use Drupal\Core\Url;

/**
 * Coach swim-test evaluation form. Handles two modes via separate routes:
 *
 *  - /swim-test/evaluate/{profile} — Pass / Fail buttons. Updates the
 *    profile (swim status, evaluation date, evaluator) and, if the
 *    profile has a pending swim_test_registration order item, mirrors
 *    the same outcome onto that order item so the no-show cron skips it.
 *  - /swim-test/mark/{commerce_order_item} — used by the no-show review
 *    workflow only. Resolves a no_show order item with a reason, or
 *    undoes a resolution. Does not touch the profile.
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
   *
   * Drupal supplies whichever route parameter matches by name.
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?OrderItemInterface $commerce_order_item = NULL, ?ProfileInterface $profile = NULL) {
    if ($profile) {
      return $this->buildEvaluationForm($form, $form_state, $profile);
    }
    if ($commerce_order_item) {
      return $this->buildOrderItemForm($form, $form_state, $commerce_order_item);
    }
    $form['error'] = ['#markup' => $this->t('Participant or registration not found.')];
    return $form;
  }

  /**
   * Profile-mode form: Pass / Fail buttons. Used by the swim test roster.
   */
  protected function buildEvaluationForm(array $form, FormStateInterface $form_state, ProfileInterface $profile) {
    $form_state->set('profile', $profile);

    $name = $this->participantName($profile);
    $current_status = $profile->get('field_swim_status')->value ?? 'none';

    $form['participant_name'] = [
      '#type' => 'markup',
      '#markup' => '<h3>' . $this->t('Participant: @name', ['@name' => $name]) . '</h3>',
    ];

    $form['current_status'] = [
      '#type' => 'markup',
      '#markup' => '<p>' . $this->t('Current status: <strong>@status</strong>', [
        '@status' => $this->statusLabel($current_status),
      ]) . '</p>',
    ];

    $form['evaluation_date'] = [
      '#type' => 'date',
      '#title' => $this->t('Evaluation date'),
      '#default_value' => date('Y-m-d'),
      '#description' => $this->t('Defaults to today. Change only when backdating.'),
    ];

    $form['actions']['#type'] = 'actions';
    $form['actions']['pass'] = [
      '#type' => 'submit',
      '#value' => $this->t('Pass'),
      '#name' => 'pass',
      '#attributes' => ['class' => ['btn', 'btn-primary', 'btn-lg', 'mr-2']],
    ];
    $form['actions']['fail'] = [
      '#type' => 'submit',
      '#value' => $this->t('Fail'),
      '#name' => 'fail',
      '#attributes' => ['class' => ['btn', 'btn-outline', 'btn-warning', 'btn-lg']],
    ];

    return $form;
  }

  /**
   * Order-item-mode form: no-show resolve or undo. Used by the no-show
   * review only.
   */
  protected function buildOrderItemForm(array $form, FormStateInterface $form_state, OrderItemInterface $commerce_order_item) {
    $form_state->set('order_item', $commerce_order_item);

    $profile = $commerce_order_item->get('field_participant')->entity;
    $name = $profile ? $this->participantName($profile) : $this->t('Unknown');
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

    $form['actions']['#type'] = 'actions';

    if ($current_status === 'no_show') {
      $form['resolve_help'] = [
        '#type' => 'markup',
        '#markup' => '<p>' . $this->t('Mark this no-show as resolved once the fee has been handled or the absence has been excused.') . '</p>',
      ];
      $form['resolve_reason'] = [
        '#type' => 'textarea',
        '#title' => $this->t('Reason / notes'),
        '#description' => $this->t('Example: "Payment received", "Missed due to illness", "Waived".'),
        '#rows' => 2,
        '#required' => TRUE,
      ];
      $form['actions']['resolve'] = [
        '#type' => 'submit',
        '#value' => $this->t('Mark resolved'),
        '#name' => 'resolve',
        '#attributes' => ['class' => ['btn', 'btn-primary', 'btn-lg']],
      ];
    }
    else {
      $form['actions']['undo'] = [
        '#type' => 'submit',
        '#value' => $this->t('Undo'),
        '#name' => 'undo',
        '#attributes' => ['class' => ['btn', 'btn-ghost', 'btn-lg']],
      ];
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $action = $form_state->getTriggeringElement()['#name'] ?? '';
    $profile = $form_state->get('profile');
    $order_item = $form_state->get('order_item');

    if ($profile && in_array($action, ['pass', 'fail'], TRUE)) {
      $this->applyEvaluation($profile, $action, $form_state);
      $form_state->setRedirectUrl(Url::fromRoute('view.swim_test_roster.page_1'));
      return;
    }

    if ($order_item && $action === 'resolve') {
      $this->resolveNoShow($order_item, $form_state);
      $form_state->setRedirectUrl(Url::fromRoute($this->noShowRedirectRoute()));
      return;
    }

    if ($order_item && $action === 'undo') {
      $this->undoOrderItem($order_item);
      $new_status = $order_item->get('field_swim_test_status')->value;
      $target = in_array($new_status, ['no_show', 'excused'], TRUE)
        ? $this->noShowRedirectRoute()
        : 'view.swim_test_roster.page_1';
      $form_state->setRedirectUrl(Url::fromRoute($target));
      return;
    }
  }

  /**
   * Pass/fail handler: writes profile fields, mirrors onto a pending order
   * item if one exists, and flips any other pending registrations for the
   * same participant out of pending too (so the no-show cron skips them).
   */
  protected function applyEvaluation(ProfileInterface $profile, string $action, FormStateInterface $form_state) {
    $name = $this->participantName($profile);
    $date = $form_state->getValue('evaluation_date') ?? date('Y-m-d');
    $new_status = $action === 'pass' ? 'passed' : 'failed';

    $profile->set('field_swim_status', $new_status);
    $profile->set('field_evaluation_date', $date);
    $profile->set('field_swim_test_evaluated_by', $this->currentUser()->id());
    $profile->save();

    $flipped = $this->flipPendingRegistrations($profile, $new_status);

    $msg = $action === 'pass'
      ? $this->t('@name marked as passed.', ['@name' => $name])
      : $this->t('@name marked as failed.', ['@name' => $name]);
    $this->messenger()->addStatus($msg);

    \Drupal::logger('picc_registration')->notice(
      'Swim test @action: @name by @coach (mirrored to @flipped order item(s))',
      [
        '@action' => strtoupper($new_status),
        '@name' => $name,
        '@coach' => $this->currentUser()->getDisplayName(),
        '@flipped' => $flipped,
      ]
    );
  }

  /**
   * Mirrors the profile outcome onto every pending swim_test_registration
   * order item belonging to this participant (typically zero or one row).
   * Returns the number of order items flipped.
   */
  protected function flipPendingRegistrations(ProfileInterface $profile, string $new_status): int {
    $storage = \Drupal::entityTypeManager()->getStorage('commerce_order_item');
    $ids = $storage->getQuery()
      ->condition('type', 'swim_test_registration')
      ->condition('field_swim_test_status', 'pending')
      ->condition('field_participant', $profile->id())
      ->accessCheck(FALSE)
      ->execute();
    if (!$ids) {
      return 0;
    }
    $count = 0;
    foreach ($storage->loadMultiple($ids) as $oi) {
      $order = $oi->getOrder();
      if (!$order || !in_array($order->getState()->getId(), ['completed', 'fulfillment'], TRUE)) {
        continue;
      }
      $oi->set('field_swim_test_status', $new_status);
      $oi->save();
      $count++;
    }
    return $count;
  }

  protected function resolveNoShow(OrderItemInterface $order_item, FormStateInterface $form_state) {
    $reason = trim((string) $form_state->getValue('resolve_reason'));
    $order_item->set('field_swim_test_status', 'excused');
    $order_item->set('field_swim_test_excuse_reason', $reason);
    $order_item->save();

    $name = ($profile = $order_item->get('field_participant')->entity)
      ? $this->participantName($profile)
      : $this->t('Participant');

    $this->messenger()->addStatus($this->t('@name no-show marked as resolved.', ['@name' => $name]));
    \Drupal::logger('picc_registration')->notice(
      'Swim test NO-SHOW RESOLVED: @name by @user (reason: @reason)',
      [
        '@name' => $name,
        '@user' => $this->currentUser()->getDisplayName(),
        '@reason' => $reason,
      ]
    );
  }

  protected function undoOrderItem(OrderItemInterface $order_item) {
    $previous_status = $order_item->get('field_swim_test_status')->value;
    $new_status = $previous_status === 'excused' ? 'no_show' : 'pending';
    $order_item->set('field_swim_test_status', $new_status);
    if ($previous_status === 'excused' && $order_item->hasField('field_swim_test_excuse_reason')) {
      $order_item->set('field_swim_test_excuse_reason', NULL);
    }
    $order_item->save();

    $name = ($profile = $order_item->get('field_participant')->entity)
      ? $this->participantName($profile)
      : $this->t('Participant');

    $this->messenger()->addStatus($this->t('@name reset to @status.', [
      '@name' => $name,
      '@status' => $new_status,
    ]));
    \Drupal::logger('picc_registration')->notice(
      'Swim test UNDO: @name by @user (was @status)',
      [
        '@name' => $name,
        '@user' => $this->currentUser()->getDisplayName(),
        '@status' => $previous_status,
      ]
    );
  }

  protected function participantName(ProfileInterface $profile): string {
    $name_field = $profile->get('field_name')->first();
    if ($name_field) {
      return trim(($name_field->given ?? '') . ' ' . ($name_field->family ?? ''));
    }
    return (string) $this->t('Unknown');
  }

  protected function statusLabel(string $status): string {
    return match ($status) {
      'none' => (string) $this->t('Swim test required'),
      'passed' => (string) $this->t('Passed'),
      'failed' => (string) $this->t('Failed'),
      'attested' => (string) $this->t('Attested'),
      'exempt' => (string) $this->t('Exempt'),
      default => ucfirst($status),
    };
  }

  protected function noShowRedirectRoute(): string {
    $route_provider = \Drupal::service('router.route_provider');
    if (count($route_provider->getRoutesByNames(['view.swim_test_no_show_review.page_1']))) {
      return 'view.swim_test_no_show_review.page_1';
    }
    return 'view.swim_test_roster.page_1';
  }

}

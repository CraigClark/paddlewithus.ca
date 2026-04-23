<?php

declare(strict_types=1);

namespace Drupal\picc_attendance\Plugin\views\field;

use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\picc_attendance\Entity\AttendanceInterface;
use Drupal\picc_attendance\Service\AttendanceAccessChecker;
use Drupal\picc_attendance\Service\AttendanceRecorder;
use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Renders Check in / Check out / Reset buttons for the coach attendance view.
 *
 * Expects the row entity to be a commerce_order_item of bundle
 * activity_registration. Each row's attendance record (if any) is looked
 * up for today. Volumes are bounded by the "active today" filter on the
 * view (~150 rows max), making a per-row load acceptable.
 *
 * @ViewsField("attendance_action")
 */
class AttendanceAction extends FieldPluginBase {

  /**
   * {@inheritdoc}
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected AttendanceRecorder $recorder,
    protected AttendanceAccessChecker $accessChecker,
    protected AccountInterface $currentUser,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('picc_attendance.recorder'),
      $container->get('picc_attendance.access_checker'),
      $container->get('current_user'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function query() {
    // No query — data read from the row entity + a per-row lookup.
  }

  /**
   * {@inheritdoc}
   */
  public function render(ResultRow $values) {
    /** @var \Drupal\commerce_order\Entity\OrderItemInterface|null $order_item */
    $order_item = $this->getEntity($values);
    if (!$order_item || $order_item->bundle() !== 'activity_registration') {
      return '';
    }
    if (!$this->accessChecker->isOrderItemEligible($order_item)) {
      return '';
    }

    $attendance = $this->recorder->getTodaysRecord($order_item);
    $dialog_opts = htmlspecialchars(json_encode(['width' => 440]), ENT_QUOTES);

    $check_in_url = Url::fromRoute('picc_attendance.check_in', [
      'commerce_order_item' => $order_item->id(),
    ])->toString();
    $check_out_url = Url::fromRoute('picc_attendance.check_out', [
      'commerce_order_item' => $order_item->id(),
    ])->toString();

    $output = '<div class="picc-attendance-actions flex gap-2 items-center flex-wrap">';

    if (!$attendance || (!$attendance->isCheckedIn() && !$attendance->isCheckedOut())) {
      // No record, or record exists but empty (e.g. both slots were reset).
      if ($this->accessChecker->canCheckIn($order_item, $this->currentUser)->isAllowed()) {
        $output .= '<a href="' . $check_in_url . '" class="btn btn-primary btn-sm use-ajax" data-dialog-type="dialog" data-dialog-options=\'' . $dialog_opts . '\'>'
          . $this->t('Check in') . '</a>';
      }
      if ($this->accessChecker->canCheckOut($order_item, $this->currentUser)->isAllowed()) {
        $output .= '<a href="' . $check_out_url . '" class="btn btn-ghost btn-sm use-ajax" data-dialog-type="dialog" data-dialog-options=\'' . $dialog_opts . '\'>'
          . $this->t('Check out') . '</a>';
      }
    }
    elseif ($attendance->isCheckedIn() && !$attendance->isCheckedOut()) {
      // Checked in, awaiting check-out.
      $output .= '<span class="badge badge-success">'
        . $this->t('In @time', ['@time' => $this->formatTime((int) $attendance->get('check_in_at')->value)])
        . '</span>';
      if ($this->accessChecker->canCheckOut($order_item, $this->currentUser)->isAllowed()) {
        $output .= '<a href="' . $check_out_url . '" class="btn btn-primary btn-sm use-ajax" data-dialog-type="dialog" data-dialog-options=\'' . $dialog_opts . '\'>'
          . $this->t('Check out') . '</a>';
      }
      $output .= $this->resetLink($attendance, AttendanceInterface::SLOT_CHECK_IN, $dialog_opts, $this->t('Reset check-in'));
    }
    elseif ($attendance->isCheckedOut()) {
      // Checked out. Only Reset check-out is exposed here — once check-out
      // is present, Reset check-in is hidden to avoid leaving a record in
      // the confusing "checked out without check-in" state via a single
      // click. A coach who wants to clear everything resets check-out
      // first; Reset check-in then becomes available on the resulting
      // "checked in only" row.
      if ((bool) $attendance->get('checkout_without_checkin')->value) {
        $output .= '<span class="badge badge-warning" title="' . $this->t('Check-out recorded without prior check-in') . '">'
          . $this->t('Out @time (no check-in)', ['@time' => $this->formatTime((int) $attendance->get('check_out_at')->value)])
          . '</span>';
      }
      else {
        $output .= '<span class="badge badge-neutral">'
          . $this->t('Out @time', ['@time' => $this->formatTime((int) $attendance->get('check_out_at')->value)])
          . '</span>';
      }
      $output .= $this->resetLink($attendance, AttendanceInterface::SLOT_CHECK_OUT, $dialog_opts, $this->t('Reset check-out'));
    }

    $output .= '</div>';

    return [
      '#markup' => $output,
      '#attached' => [
        'library' => ['core/drupal.dialog.ajax'],
      ],
      '#cache' => [
        'max-age' => 0,
      ],
    ];
  }

  /**
   * Renders a reset link for a given slot, if the user has access.
   */
  protected function resetLink(AttendanceInterface $attendance, string $slot, string $dialog_opts, $label): string {
    if (!$this->accessChecker->canReset($attendance, $slot, $this->currentUser)->isAllowed()) {
      return '';
    }
    $url = Url::fromRoute('picc_attendance.reset', [
      'picc_attendance' => $attendance->id(),
      'slot' => $slot,
    ])->toString();
    return '<a href="' . $url . '" class="btn btn-ghost btn-xs use-ajax" data-dialog-type="dialog" data-dialog-options=\'' . $dialog_opts . '\'>' . $label . '</a>';
  }

  /**
   * Formats a Unix timestamp in site timezone as HH:MM.
   */
  protected function formatTime(int $ts): string {
    $tz = \Drupal::config('system.date')->get('timezone.default') ?: date_default_timezone_get();
    return (new \DateTimeImmutable('@' . $ts))
      ->setTimezone(new \DateTimeZone($tz))
      ->format('H:i');
  }

}

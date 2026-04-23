<?php

declare(strict_types=1);

namespace Drupal\picc_attendance\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\picc_attendance\Service\AttendancePurger;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Settings form for picc_attendance.
 *
 * Route: /admin/config/picc/attendance
 * Permission: administer picc attendance
 *
 * Exposes:
 * - Retention window in days
 * - Last purge timestamp + "Run purge now" button for ad-hoc runs
 */
class AttendanceSettingsForm extends ConfigFormBase {

  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typed_config_manager,
    protected AttendancePurger $purger,
    protected DateFormatterInterface $dateFormatter,
  ) {
    parent::__construct($config_factory, $typed_config_manager);
  }

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('picc_attendance.purger'),
      $container->get('date.formatter'),
    );
  }

  protected function getEditableConfigNames(): array {
    return ['picc_attendance.settings'];
  }

  public function getFormId(): string {
    return 'picc_attendance_settings_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('picc_attendance.settings');

    $form['retention_days'] = [
      '#type' => 'number',
      '#title' => $this->t('Retention window (days)'),
      '#description' => $this->t('Attendance records whose date is older than this many days will be purged automatically (3 years = 1095).'),
      '#default_value' => (int) ($config->get('retention_days') ?? 1095),
      '#min' => 30,
      '#max' => 3650,
      '#required' => TRUE,
    ];

    $last_ts = $this->purger->getLastPurgeTimestamp();
    $form['purge_stats'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Purge status'),
      'last' => [
        '#markup' => '<p>' . $this->t('Last purge: @time', [
          '@time' => $last_ts > 0 ? $this->dateFormatter->format($last_ts, 'custom', 'Y-m-d H:i') : $this->t('never'),
        ]) . '</p>',
      ],
      'retention_note' => [
        '#markup' => '<p>' . $this->t('Purges run automatically once every 24 hours via cron. Use the button below only if you need to force an immediate purge (e.g. after changing the retention window).') . '</p>',
      ],
      'run_now' => [
        '#type' => 'submit',
        '#value' => $this->t('Run purge now'),
        '#name' => 'run_purge_now',
        '#submit' => ['::runPurgeNow'],
        '#limit_validation_errors' => [],
        '#attributes' => ['class' => ['btn', 'btn-warning']],
      ],
    ];

    return parent::buildForm($form, $form_state);
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('picc_attendance.settings')
      ->set('retention_days', (int) $form_state->getValue('retention_days'))
      ->save();
    parent::submitForm($form, $form_state);
  }

  /**
   * Handler for "Run purge now" button — bypasses the 24h gate.
   */
  public function runPurgeNow(array &$form, FormStateInterface $form_state): void {
    $deleted = $this->purger->run();
    $this->messenger()->addStatus($this->t('Purge complete. @count record(s) deleted.', [
      '@count' => $deleted,
    ]));
    $form_state->setRebuild(TRUE);
  }

}

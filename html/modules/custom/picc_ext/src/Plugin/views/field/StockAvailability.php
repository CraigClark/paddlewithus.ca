<?php

namespace Drupal\picc_ext\Plugin\views\field;

use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\commerce_stock\StockServiceManagerInterface;

/**
 * Field handler to display available stock for a product variation.
 *
 * @ViewsField("commerce_stock_availability")
 */
class StockAvailability extends FieldPluginBase {

  /**
   * The stock service manager.
   *
   * @var \Drupal\commerce_stock\StockServiceManagerInterface
   */
  protected $stockServiceManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->stockServiceManager = $container->get('commerce_stock.service_manager');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, StockServiceManagerInterface $stock_service_manager) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->stockServiceManager = $stock_service_manager;
  }

  /**
   * {@inheritdoc}
   */
  protected function defineOptions() {
    $options = parent::defineOptions();
    $options['hide_if_unlimited'] = ['default' => FALSE];
    $options['suffix'] = ['default' => 'places available'];
    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function buildOptionsForm(&$form, FormStateInterface $form_state) {
    parent::buildOptionsForm($form, $form_state);
    
    $form['suffix'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Suffix text'),
      '#default_value' => $this->options['suffix'],
      '#description' => $this->t('Text to append after the stock level (e.g., "places available").'),
    ];

    $form['hide_if_unlimited'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Hide if unlimited stock'),
      '#default_value' => $this->options['hide_if_unlimited'],
      '#description' => $this->t('Do not display anything if stock is unlimited (no stock control).'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function query() {
    // No query changes needed - we'll load the variation in render()
  }

  /**
   * {@inheritdoc}
   */
  public function render(ResultRow $values) {
    $variation = $this->getEntity($values);
    
    if (!$variation) {
      return '';
    }

    // Get stock service for this variation
    $stock_service = $this->stockServiceManager->getService($variation);
    
    if (!$stock_service) {
      // No stock service = unlimited
      if ($this->options['hide_if_unlimited']) {
        return '';
      }
      return $this->t('Unlimited');
    }

    $stock_checker = $stock_service->getStockChecker();
    
    if (!$stock_checker) {
      if ($this->options['hide_if_unlimited']) {
        return '';
      }
      return $this->t('Unlimited');
    }

    // Get stock locations - required parameter
    $locations = $stock_service->getStockLocations($variation);
    
    if (empty($locations)) {
      // No locations = unlimited or not configured
      if ($this->options['hide_if_unlimited']) {
        return '';
      }
      return $this->t('Unlimited');
    }

    // Get available stock - requires variation and locations
    $available = $stock_checker->getTotalStockLevel($variation, $locations);
    
    if ($available === NULL || $available === FALSE) {
      if ($this->options['hide_if_unlimited']) {
        return '';
      }
      return $this->t('Unlimited');
    }

    $suffix = !empty($this->options['suffix']) ? ' ' . $this->options['suffix'] : '';
    
    return [
      '#markup' => $available . $suffix,
    ];
  }

}

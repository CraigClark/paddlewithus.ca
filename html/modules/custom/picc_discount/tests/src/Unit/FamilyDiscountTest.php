<?php

namespace Drupal\Tests\picc_discount\Unit;

use Drupal\commerce_order\Adjustment;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\commerce_price\Price;
use Drupal\commerce_promotion\Entity\PromotionInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\picc_discount\Plugin\Commerce\PromotionOffer\FamilyDiscount;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the FamilyDiscount promotion offer plugin.
 *
 * Verifies graduated family discount logic:
 * - Participant 1: field_discount_1% (typically 0%)
 * - Participant 2: field_discount_2% (e.g. 10%)
 * - Participant 3: field_discount_3% (e.g. 20%)
 * - Participant 4: field_discount_4%
 * - Participant 5+: field_discount_5_up%
 *
 * @group picc_discount
 */
class FamilyDiscountTest extends UnitTestCase {

  /**
   * Stores captured adjustments keyed by order item ID.
   *
   * @var array<string, \Drupal\commerce_order\Adjustment[]>
   */
  protected array $capturedAdjustments = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->capturedAdjustments = [];

    // Set up a minimal container so that the Adjustment constructor can
    // validate the adjustment type via the plugin manager.
    $adjustment_type_manager = $this->createMock('\Drupal\Component\Plugin\PluginManagerInterface');
    $adjustment_type_manager->method('getDefinitions')->willReturn([
      'promotion' => ['id' => 'promotion', 'label' => 'Promotion'],
      'custom' => ['id' => 'custom', 'label' => 'Custom'],
      'fee' => ['id' => 'fee', 'label' => 'Fee'],
      'tax' => ['id' => 'tax', 'label' => 'Tax'],
    ]);

    $container = new ContainerBuilder();
    $container->set('plugin.manager.commerce_adjustment_type', $adjustment_type_manager);
    \Drupal::setContainer($container);
  }

  /**
   * Get captured adjustments for an order item by its ID.
   */
  protected function getAdjustments(string $item_id): array {
    return $this->capturedAdjustments[$item_id] ?? [];
  }

  /**
   * Test that 3 kids with 0%/10%/20% discount tiers total correctly.
   *
   * Base price: $100 per kid.
   * Kid 1: $100 (0% off)
   * Kid 2: $90 (10% off)
   * Kid 3: $80 (20% off)
   * Total: $270
   */
  public function testThreeKidsDiscountTotals270() {
    $base_price = new Price('100', 'CAD');

    $variation = $this->createVariationMock([
      'field_discount_1' => 0,
      'field_discount_2' => 10,
      'field_discount_3' => 20,
      'field_discount_4' => 30,
      'field_discount_5_up' => 50,
    ]);

    $item_ids = ['kid1', 'kid2', 'kid3'];
    $order_items = [];
    foreach ($item_ids as $id) {
      $order_items[] = $this->createOrderItemMock($variation, $base_price, $id);
    }

    $order = $this->createOrderMock($order_items);
    $promotion = $this->createMock(PromotionInterface::class);
    $promotion->method('id')->willReturn('1');

    $discount = new TestableFamilyDiscount();
    $discount->apply($order, $promotion);

    // Item 1 (position 1): no adjustment (0% discount).
    $this->assertEmpty($this->getAdjustments('kid1'), 'First participant should have no discount.');

    // Item 2 (position 2): 10% discount = -$10.
    $adj2 = $this->getAdjustments('kid2');
    $this->assertCount(1, $adj2, 'Second participant should have 1 adjustment.');
    $this->assertEquals('-10', $adj2[0]->getAmount()->getNumber());

    // Item 3 (position 3): 20% discount = -$20.
    $adj3 = $this->getAdjustments('kid3');
    $this->assertCount(1, $adj3, 'Third participant should have 1 adjustment.');
    $this->assertEquals('-20', $adj3[0]->getAmount()->getNumber());

    // Calculate effective total.
    $total = $this->calculateTotal($item_ids, $base_price);
    $this->assertEquals(270, $total, 'Total for 3 kids should be $270.');
  }

  /**
   * Test that a single participant gets no discount.
   */
  public function testSingleParticipantNoDiscount() {
    $base_price = new Price('100', 'CAD');
    $variation = $this->createVariationMock([
      'field_discount_1' => 0,
      'field_discount_2' => 10,
      'field_discount_3' => 20,
      'field_discount_4' => 30,
      'field_discount_5_up' => 50,
    ]);

    $order_items = [$this->createOrderItemMock($variation, $base_price, 'solo')];
    $order = $this->createOrderMock($order_items);
    $promotion = $this->createMock(PromotionInterface::class);
    $promotion->method('id')->willReturn('1');

    $discount = new TestableFamilyDiscount();
    $discount->apply($order, $promotion);

    $this->assertEmpty($this->getAdjustments('solo'), 'Single participant should have no discount.');
  }

  /**
   * Test that items for different variations get independent discounts.
   */
  public function testDifferentVariationsIndependentDiscounts() {
    $base_price = new Price('100', 'CAD');

    $variation_a = $this->createVariationMock([
      'field_discount_1' => 0,
      'field_discount_2' => 10,
      'field_discount_3' => 20,
      'field_discount_4' => 0,
      'field_discount_5_up' => 0,
    ], 'var_a');

    $variation_b = $this->createVariationMock([
      'field_discount_1' => 0,
      'field_discount_2' => 15,
      'field_discount_3' => 25,
      'field_discount_4' => 0,
      'field_discount_5_up' => 0,
    ], 'var_b');

    $item_a1 = $this->createOrderItemMock($variation_a, $base_price, 'a1');
    $item_b1 = $this->createOrderItemMock($variation_b, $base_price, 'b1');
    $item_b2 = $this->createOrderItemMock($variation_b, $base_price, 'b2');

    $order = $this->createOrderMock([$item_a1, $item_b1, $item_b2]);
    $promotion = $this->createMock(PromotionInterface::class);
    $promotion->method('id')->willReturn('1');

    $discount = new TestableFamilyDiscount();
    $discount->apply($order, $promotion);

    // Variation A: single participant, no discount.
    $this->assertEmpty($this->getAdjustments('a1'), 'Single participant for variation A should have no discount.');

    // Variation B: 2 participants.
    $this->assertEmpty($this->getAdjustments('b1'), 'First participant for variation B should have no discount.');
    $adj_b2 = $this->getAdjustments('b2');
    $this->assertCount(1, $adj_b2, 'Second participant for variation B should have 1 adjustment.');
    $this->assertEquals('-15', $adj_b2[0]->getAmount()->getNumber());
  }

  /**
   * Test that all-zero discount fields result in no adjustments.
   */
  public function testAllZeroDiscountsSkipped() {
    $base_price = new Price('100', 'CAD');
    $variation = $this->createVariationMock([
      'field_discount_1' => 0,
      'field_discount_2' => 0,
      'field_discount_3' => 0,
      'field_discount_4' => 0,
      'field_discount_5_up' => 0,
    ]);

    $item_ids = ['z1', 'z2', 'z3'];
    $items = [];
    foreach ($item_ids as $id) {
      $items[] = $this->createOrderItemMock($variation, $base_price, $id);
    }

    $order = $this->createOrderMock($items);
    $promotion = $this->createMock(PromotionInterface::class);
    $promotion->method('id')->willReturn('1');

    $discount = new TestableFamilyDiscount();
    $discount->apply($order, $promotion);

    foreach ($item_ids as $id) {
      $this->assertEmpty($this->getAdjustments($id), "Item $id should have no adjustments when all discounts are 0.");
    }
  }

  /**
   * Test position 5+ participants use discount_5_up.
   */
  public function testFifthAndBeyondUsesDiscount5Up() {
    $base_price = new Price('100', 'CAD');
    $variation = $this->createVariationMock([
      'field_discount_1' => 0,
      'field_discount_2' => 10,
      'field_discount_3' => 20,
      'field_discount_4' => 30,
      'field_discount_5_up' => 100,
    ]);

    $item_ids = ['p1', 'p2', 'p3', 'p4', 'p5', 'p6'];
    $items = [];
    foreach ($item_ids as $id) {
      $items[] = $this->createOrderItemMock($variation, $base_price, $id);
    }

    $order = $this->createOrderMock($items);
    $promotion = $this->createMock(PromotionInterface::class);
    $promotion->method('id')->willReturn('1');

    $discount = new TestableFamilyDiscount();
    $discount->apply($order, $promotion);

    // Position 5: 100% discount = -$100.
    $adj5 = $this->getAdjustments('p5');
    $this->assertCount(1, $adj5);
    $this->assertEquals('-100', $adj5[0]->getAmount()->getNumber());

    // Position 6: 100% discount = -$100.
    $adj6 = $this->getAdjustments('p6');
    $this->assertCount(1, $adj6);
    $this->assertEquals('-100', $adj6[0]->getAmount()->getNumber());

    // Total: 100 + 90 + 80 + 70 + 0 + 0 = 340.
    $total = $this->calculateTotal($item_ids, $base_price);
    $this->assertEquals(340, $total, 'Total for 6 kids should be $340.');
  }

  /**
   * Test discount with $480 base price (real-world PICC price).
   *
   * Verifies: 3 kids at $480, 10%/20% = $480 + $432 + $384 = $1296.
   */
  public function testRealWorldPriceThreeKids() {
    $base_price = new Price('480', 'CAD');
    $variation = $this->createVariationMock([
      'field_discount_1' => 0,
      'field_discount_2' => 10,
      'field_discount_3' => 20,
      'field_discount_4' => 0,
      'field_discount_5_up' => 0,
    ]);

    $item_ids = ['r1', 'r2', 'r3'];
    $items = [];
    foreach ($item_ids as $id) {
      $items[] = $this->createOrderItemMock($variation, $base_price, $id);
    }

    $order = $this->createOrderMock($items);
    $promotion = $this->createMock(PromotionInterface::class);
    $promotion->method('id')->willReturn('1');

    $discount = new TestableFamilyDiscount();
    $discount->apply($order, $promotion);

    // Kid 1: $480 (0%).
    $this->assertEmpty($this->getAdjustments('r1'));

    // Kid 2: -$48 (10% of $480).
    $adj2 = $this->getAdjustments('r2');
    $this->assertCount(1, $adj2);
    $this->assertEquals('-48', $adj2[0]->getAmount()->getNumber());

    // Kid 3: -$96 (20% of $480).
    $adj3 = $this->getAdjustments('r3');
    $this->assertCount(1, $adj3);
    $this->assertEquals('-96', $adj3[0]->getAmount()->getNumber());

    // Total: 480 + 432 + 384 = 1296.
    $total = $this->calculateTotal($item_ids, $base_price);
    $this->assertEquals(1296, $total, 'Total for 3 kids at $480 should be $1296.');
  }

  /**
   * Calculate effective total from captured adjustments.
   */
  protected function calculateTotal(array $item_ids, Price $base_price): float {
    $total = 0;
    foreach ($item_ids as $id) {
      $item_total = (float) $base_price->getNumber();
      foreach ($this->getAdjustments($id) as $adj) {
        $item_total += (float) $adj->getAmount()->getNumber();
      }
      $total += $item_total;
    }
    return $total;
  }

  /**
   * Creates a mock product variation with discount fields.
   */
  protected function createVariationMock(array $discount_values, $id = 'variation_1') {
    $variation = $this->createMock(FieldableEntityInterface::class);
    $variation->method('id')->willReturn($id);

    $variation->method('hasField')->willReturnCallback(function ($field_name) use ($discount_values) {
      return isset($discount_values[$field_name]);
    });

    $variation->method('get')->willReturnCallback(function ($field_name) use ($discount_values) {
      $field_list = $this->createMock(FieldItemListInterface::class);

      if (isset($discount_values[$field_name])) {
        $field_list->method('isEmpty')->willReturn(FALSE);
        $field_list->method('__get')->willReturnCallback(function ($prop) use ($discount_values, $field_name) {
          if ($prop === 'value') {
            return $discount_values[$field_name];
          }
          return NULL;
        });
      }
      else {
        $field_list->method('isEmpty')->willReturn(TRUE);
      }

      return $field_list;
    });

    return $variation;
  }

  /**
   * Creates a mock order item that captures adjustments.
   */
  protected function createOrderItemMock($variation, Price $unit_price, string $id) {
    $order_item = $this->createMock(OrderItemInterface::class);

    $order_item->method('getPurchasedEntity')->willReturn($variation);
    $order_item->method('getUnitPrice')->willReturn($unit_price);
    $order_item->method('id')->willReturn($id);

    // Capture adjustments into the test class property using item ID as key.
    $test = $this;
    $order_item->method('addAdjustment')->willReturnCallback(
      function (Adjustment $adjustment) use ($test, $id) {
        $test->capturedAdjustments[$id][] = $adjustment;
      }
    );

    return $order_item;
  }

  /**
   * Creates a mock order.
   */
  protected function createOrderMock(array $items) {
    $order = $this->createMock(OrderInterface::class);
    $order->method('getItems')->willReturn($items);
    return $order;
  }

}

/**
 * Testable subclass of FamilyDiscount that skips parent constructor.
 */
class TestableFamilyDiscount extends FamilyDiscount {

  /**
   * Skip the parent constructor for unit testing.
   */
  public function __construct() {
    // Intentionally empty.
  }

  /**
   * Override assertEntity to accept mock objects.
   */
  protected function assertEntity(EntityInterface $entity) {
    // No-op for testing.
  }

  /**
   * Override t() to avoid container dependency in unit tests.
   */
  protected function t($string, array $args = [], array $options = []) {
    return strtr($string, $args);
  }

}

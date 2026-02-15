<?php

namespace Drupal\Tests\picc_registration\Unit;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\Select;
use Drupal\Core\Database\StatementInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\commerce_product\Entity\ProductVariation;
use Drupal\commerce_stock\StockCheckInterface;
use Drupal\commerce_stock\StockServiceInterface;
use Drupal\commerce_stock\StockServiceManagerInterface;
use Drupal\Tests\UnitTestCase;

/**
 * Tests stock validation in the PICC Registration Handler.
 *
 * @group picc_registration
 */
class PiccRegistrationStockTest extends UnitTestCase {

  /**
   * The mocked stock checker.
   *
   * @var \Drupal\commerce_stock\StockCheckInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $stockChecker;

  /**
   * The mocked lock backend.
   *
   * @var \Drupal\Core\Lock\LockBackendInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $lock;

  /**
   * The mocked database connection.
   *
   * @var \Drupal\Core\Database\Connection|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $database;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Build the stock service chain.
    $this->stockChecker = $this->createMock(StockCheckInterface::class);

    $stock_service = $this->createMock(StockServiceInterface::class);
    $stock_service->method('getId')->willReturn('local_stock');
    $stock_service->method('getStockChecker')->willReturn($this->stockChecker);

    $stock_service_manager = $this->createMock(StockServiceManagerInterface::class);
    $stock_service_manager->method('getService')->willReturn($stock_service);

    // Mock the lock backend.
    $this->lock = $this->createMock(LockBackendInterface::class);

    // Mock entity type manager with a stock location storage.
    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);

    $location = new \stdClass();
    $location_storage = $this->createMock(EntityStorageInterface::class);
    $location_storage->method('loadByProperties')
      ->willReturn([1 => $location]);

    $entity_type_manager->method('getStorage')
      ->willReturnCallback(function ($entity_type) use ($location_storage) {
        if ($entity_type === 'commerce_stock_location') {
          return $location_storage;
        }
        return $this->createMock(EntityStorageInterface::class);
      });

    // Mock the database connection for countDraftCartItemsForVariation().
    $this->database = $this->createMock(Connection::class);

    // Mock the logger factory (used by \Drupal::logger()).
    $logger_channel = $this->createMock(LoggerChannelInterface::class);
    $logger_factory = $this->createMock(LoggerChannelFactoryInterface::class);
    $logger_factory->method('get')->willReturn($logger_channel);

    // Mock string translation to return the untranslated string.
    $string_translation = $this->createMock(TranslationInterface::class);
    $string_translation->method('translateString')
      ->willReturnCallback(function ($wrapper) {
        return $wrapper->getUntranslatedString();
      });

    // Build the container.
    $container = new ContainerBuilder();
    $container->set('commerce_stock.service_manager', $stock_service_manager);
    $container->set('entity_type.manager', $entity_type_manager);
    $container->set('lock', $this->lock);
    $container->set('database', $this->database);
    $container->set('logger.factory', $logger_factory);
    $container->set('string_translation', $string_translation);

    \Drupal::setContainer($container);
  }

  /**
   * Creates a mock product variation.
   */
  protected function createMockVariation($variation_id) {
    $variation = $this->createMock(ProductVariation::class);
    $variation->method('id')->willReturn($variation_id);
    return $variation;
  }

  /**
   * Sets up the database mock to return a specific draft cart count.
   */
  protected function mockDraftCartCount($count) {
    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchField')->willReturn($count);

    $select = $this->createMock(Select::class);
    $select->method('join')->willReturnSelf();
    $select->method('condition')->willReturnSelf();
    $select->method('addExpression')->willReturnSelf();
    $select->method('execute')->willReturn($statement);

    $this->database->method('select')->willReturn($select);
  }

  /**
   * Tests that submission is rejected when participants exceed stock.
   *
   * Scenario: stock=1, requested participants=2, no draft cart items.
   * Expected: Exception thrown with "only 1 spot" message.
   */
  public function testInsufficientStockRejectsSubmission() {
    $variation = $this->createMockVariation(42);
    $this->stockChecker->method('getTotalStockLevel')->willReturn(1);
    $this->mockDraftCartCount(0);

    $handler = new TestableRegistrationHandler();
    $participant_ids = [101, 102];

    $this->expectException(\Exception::class);
    $this->expectExceptionMessageMatches('/only 1 spot/i');

    $handler->callCheckStockAvailability($variation, $participant_ids, 1);
  }

  /**
   * Tests that submission succeeds when stock is sufficient.
   *
   * Scenario: stock=5, requested participants=2, no draft cart items.
   * Expected: No exception.
   */
  public function testSufficientStockAllowsSubmission() {
    $variation = $this->createMockVariation(42);
    $this->stockChecker->method('getTotalStockLevel')->willReturn(5);
    $this->mockDraftCartCount(0);

    $handler = new TestableRegistrationHandler();
    $participant_ids = [101, 102];

    // Should not throw.
    $handler->callCheckStockAvailability($variation, $participant_ids, 1);
    $this->assertTrue(TRUE, 'Stock check passed without exception.');
  }

  /**
   * Tests that draft cart items reduce effective stock.
   *
   * Scenario: raw stock=3, 2 items in other draft carts, requesting 2.
   * Effective stock = 3 - 2 = 1. Since 1 < 2, exception thrown.
   */
  public function testDraftCartItemsReduceEffectiveStock() {
    $variation = $this->createMockVariation(42);
    $this->stockChecker->method('getTotalStockLevel')->willReturn(3);
    $this->mockDraftCartCount(2);

    $handler = new TestableRegistrationHandler();
    $participant_ids = [101, 102];

    $this->expectException(\Exception::class);
    $this->expectExceptionMessageMatches('/only 1 spot/i');

    $handler->callCheckStockAvailability($variation, $participant_ids, 1);
  }

  /**
   * Tests that zero effective stock shows "at capacity" message.
   *
   * Scenario: raw stock=2, 2 items in draft carts, requesting 1.
   * Effective stock = 0. Should show "at capacity" message.
   */
  public function testZeroEffectiveStockShowsAtCapacity() {
    $variation = $this->createMockVariation(42);
    $this->stockChecker->method('getTotalStockLevel')->willReturn(2);
    $this->mockDraftCartCount(2);

    $handler = new TestableRegistrationHandler();
    $participant_ids = [101];

    $this->expectException(\Exception::class);
    $this->expectExceptionMessageMatches('/at capacity/i');

    $handler->callCheckStockAvailability($variation, $participant_ids, 1);
  }

  /**
   * Tests that lock failure throws a friendly error.
   */
  public function testLockFailureThrowsFriendlyError() {
    $variation = $this->createMockVariation(42);

    // Lock always fails.
    $this->lock->method('acquire')->willReturn(FALSE);
    $this->lock->method('wait')->willReturn(FALSE);

    $handler = new TestableRegistrationHandler();
    $participant_ids = [101];

    $this->expectException(\Exception::class);
    $this->expectExceptionMessageMatches('/busy/i');

    $handler->callCreateCommerceOrderWithStockLock($participant_ids, 1, $variation);
  }

}

/**
 * Testable subclass exposing protected methods and stubbing dependencies.
 */
class TestableRegistrationHandler extends \Drupal\picc_registration\Plugin\WebformHandler\PiccRegistrationHandler {

  /**
   * Skip parent constructor — it requires webform plugin configuration.
   */
  public function __construct() {
    // Intentionally empty.
  }

  /**
   * Expose checkStockAvailability() for testing.
   */
  public function callCheckStockAvailability($variation, $participant_ids, $user_id) {
    return $this->checkStockAvailability($variation, $participant_ids, $user_id);
  }

  /**
   * Expose createCommerceOrderWithStockLock() for testing.
   */
  public function callCreateCommerceOrderWithStockLock($participant_ids, $user_id, $variation) {
    return $this->createCommerceOrderWithStockLock($participant_ids, $user_id, $variation);
  }

  /**
   * {@inheritdoc}
   */
  protected function findDraftOrder($user_id) {
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  protected function isInCompletedOrder($profile_id, $variation_id) {
    return FALSE;
  }

}

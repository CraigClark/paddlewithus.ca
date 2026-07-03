<?php

namespace Drupal\picc_discount;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\taxonomy\TermInterface;

/**
 * Central definition of "is this on sale", shared by the offer, views and cron.
 *
 * A product variation is on sale when its field_sale_type references a
 * sale_type term whose discount percentage is positive and whose end date has
 * not passed. The term is the single source of truth for the label, the
 * percentage and the end date, so the discount, the card badge and the
 * struck-through price can never disagree.
 */
class SaleManager {

  public function __construct(
    protected TimeInterface $time,
    protected ConfigFactoryInterface $configFactory,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected LanguageManagerInterface $languageManager,
    protected EntityRepositoryInterface $entityRepository,
  ) {}

  /**
   * Today's date as Y-m-d in the site's configured timezone.
   */
  public function today(): string {
    $tz = $this->configFactory->get('system.date')->get('timezone.default') ?: date_default_timezone_get();
    return (new \DateTime('@' . $this->time->getRequestTime()))
      ->setTimezone(new \DateTimeZone($tz))
      ->format('Y-m-d');
  }

  /**
   * The active sale term for a variation, or NULL when it is not on sale.
   *
   * Returns NULL when no sale is set, the term is missing, the percentage is
   * not positive, or the sale has ended.
   */
  public function getVariationSale(EntityInterface $variation): ?TermInterface {
    if (!$variation instanceof ProductVariationInterface) {
      return NULL;
    }
    if (!$variation->hasField('field_sale_type') || $variation->get('field_sale_type')->isEmpty()) {
      return NULL;
    }
    $term = $variation->get('field_sale_type')->entity;
    if (!$term instanceof TermInterface) {
      return NULL;
    }
    if ($this->getPercent($term) <= 0 || $this->isExpired($term)) {
      return NULL;
    }
    return $term;
  }

  /**
   * The discount percentage stored on a sale_type term (0 when unset).
   */
  public function getPercent(TermInterface $term): int {
    if (!$term->hasField('field_discount_percent') || $term->get('field_discount_percent')->isEmpty()) {
      return 0;
    }
    return (int) $term->get('field_discount_percent')->value;
  }

  /**
   * The sale end date (Y-m-d) stored on a sale_type term, or NULL.
   */
  public function getEndDate(TermInterface $term): ?string {
    if (!$term->hasField('field_sale_ends') || $term->get('field_sale_ends')->isEmpty()) {
      return NULL;
    }
    return $term->get('field_sale_ends')->value;
  }

  /**
   * Whether a sale has ended.
   *
   * The sale is live through the end of its end date. A term with no end date
   * is treated as ended, so a misconfigured campaign never discounts silently.
   */
  public function isExpired(TermInterface $term): bool {
    $ends = $this->getEndDate($term);
    if ($ends === NULL) {
      return TRUE;
    }
    return $this->today() > $ends;
  }

  /**
   * Seconds from now until the sale ends (end of its end date); 0 if past.
   *
   * Used as a render max-age so a cached sale display (badge, price) expires
   * exactly when the sale does, without waiting for a cache clear.
   */
  public function secondsUntilSaleEnd(TermInterface $term): int {
    $ends = $this->getEndDate($term);
    if ($ends === NULL) {
      return 0;
    }
    $tz = $this->configFactory->get('system.date')->get('timezone.default') ?: date_default_timezone_get();
    $end = \DateTime::createFromFormat('Y-m-d H:i:s', $ends . ' 23:59:59', new \DateTimeZone($tz));
    if (!$end) {
      return 0;
    }
    return max(0, $end->getTimestamp() - $this->time->getRequestTime());
  }

  /**
   * Formats a Y-m-d sale end date for the current language.
   *
   * "June 27, 2026" in English; "27 juin 2026" in French.
   */
  public function formatSaleEndDate(string $ends): string {
    $tz = $this->configFactory->get('system.date')->get('timezone.default') ?: date_default_timezone_get();
    $date = \DateTime::createFromFormat('Y-m-d', $ends, new \DateTimeZone($tz));
    if (!$date) {
      return $ends;
    }
    $langcode = $this->languageManager->getCurrentLanguage()->getId();
    $formatter = new \IntlDateFormatter($langcode, \IntlDateFormatter::LONG, \IntlDateFormatter::NONE, $tz);
    return $formatter->format($date) ?: $date->format('F j, Y');
  }

  /**
   * Display details for a variation's active sale, or NULL.
   *
   * One place to assemble everything the front end shows about a sale: the
   * percentage, the raw and formatted end date, and the campaign name in the
   * current language (so it shows in FR wherever staff have translated the
   * term).
   *
   * @return array|null
   *   ['term' => TermInterface, 'percent' => int, 'ends' => string,
   *   'ends_formatted' => string, 'label' => string], or NULL when not on sale.
   */
  public function getVariationSaleDisplay(EntityInterface $variation): ?array {
    $term = $this->getVariationSale($variation);
    if (!$term) {
      return NULL;
    }
    $ends = $this->getEndDate($term);
    return [
      'term' => $term,
      'percent' => $this->getPercent($term),
      'ends' => $ends,
      'ends_formatted' => $ends ? $this->formatSaleEndDate($ends) : '',
      'label' => $this->entityRepository->getTranslationFromContext($term)->label(),
    ];
  }

  /**
   * The best active sale for a product, rolled up across its variations.
   *
   * A program card represents a product that may have several sessions; this
   * reports whether any session is on sale. When more than one is, the highest
   * percentage wins, and ties keep the soonest end date.
   *
   * @return array|null
   *   Same shape as getVariationSaleDisplay(), or NULL when no session is on
   *   sale.
   */
  public function getProductSale(ProductInterface $product): ?array {
    $best = NULL;
    foreach ($product->getVariations() as $variation) {
      $info = $this->getVariationSaleDisplay($variation);
      if (!$info) {
        continue;
      }
      if ($best === NULL
        || $info['percent'] > $best['percent']
        || ($info['percent'] === $best['percent'] && $info['ends'] < $best['ends'])) {
        $best = $info;
      }
    }
    return $best;
  }

  /**
   * IDs of products that currently have at least one session on sale.
   *
   * Used to filter the "on sale" programs block. Reads active sale terms
   * (positive percent, not past their end date) and collects the products of
   * the variations that reference them. Date-only values compare correctly as
   * Y-m-d strings.
   *
   * @return int[]
   *   Product IDs, or an empty array when nothing is on sale.
   */
  public function getProductIdsOnSale(): array {
    $term_storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $tids = $term_storage->getQuery()
      ->condition('vid', 'sale_type')
      ->condition('field_discount_percent', 0, '>')
      ->condition('field_sale_ends', $this->today(), '>=')
      ->accessCheck(FALSE)
      ->execute();
    if (!$tids) {
      return [];
    }

    $variation_storage = $this->entityTypeManager->getStorage('commerce_product_variation');
    $vids = $variation_storage->getQuery()
      ->condition('field_sale_type', $tids, 'IN')
      ->accessCheck(FALSE)
      ->execute();
    if (!$vids) {
      return [];
    }

    $product_ids = [];
    foreach ($variation_storage->loadMultiple($vids) as $variation) {
      if ($pid = $variation->getProductId()) {
        $product_ids[$pid] = $pid;
      }
    }
    return array_values($product_ids);
  }

  /**
   * Whether an order contains at least one session that is currently on sale.
   *
   * One definition shared by the Sale promotion's condition and the checkout
   * non-refundable notice.
   */
  public function orderHasSaleItem(OrderInterface $order): bool {
    foreach ($order->getItems() as $order_item) {
      $variation = $order_item->getPurchasedEntity();
      if ($variation && $this->getVariationSale($variation)) {
        return TRUE;
      }
    }
    return FALSE;
  }

}

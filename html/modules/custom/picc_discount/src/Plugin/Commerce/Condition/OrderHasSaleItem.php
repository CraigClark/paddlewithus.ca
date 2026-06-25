<?php

namespace Drupal\picc_discount\Plugin\Commerce\Condition;

use Drupal\commerce\Plugin\Commerce\Condition\ConditionBase;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\Core\Entity\EntityInterface;

/**
 * Condition: the order contains at least one session that is on sale.
 *
 * Scopes the Sale promotion so it only applies to orders that actually have an
 * on-sale item. Without it the promotion (compatibility: none) would apply to
 * every order and suppress other promotions, such as the family discount, even
 * when nothing is on sale.
 *
 * The sale manager is resolved lazily rather than injected: Commerce's
 * condition manager does not instantiate condition plugins through
 * ContainerFactoryPluginInterface::create(), so constructor injection is not
 * available here.
 *
 * @CommerceCondition(
 *   id = "picc_order_has_sale_item",
 *   label = @Translation("Order contains an on-sale session"),
 *   category = @Translation("PICC"),
 *   entity_type = "commerce_order",
 * )
 */
class OrderHasSaleItem extends ConditionBase {

  /**
   * {@inheritdoc}
   */
  public function evaluate(EntityInterface $entity) {
    if (!$entity instanceof OrderInterface) {
      return FALSE;
    }
    return \Drupal::service('picc_discount.sale_manager')->orderHasSaleItem($entity);
  }

}

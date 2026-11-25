<?php

namespace Drupal\picc_giftcard\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Only gift card types marked "Can be sold on the site" can be on an amount.
 *
 * Stops a Complimentary (free) card from ever being put up for sale.
 */
#[Constraint(
  id: 'PiccSellableGiftcardType',
  label: new TranslatableMarkup('Gift card type can be sold', [], ['context' => 'Validation'])
)]
class SellableGiftcardTypeConstraint extends SymfonyConstraint {

  /**
   * The message shown when the type can't be sold.
   */
  public string $message = '%type gift cards can’t be sold on the site. Choose a type that can be sold, such as Standard.';

}

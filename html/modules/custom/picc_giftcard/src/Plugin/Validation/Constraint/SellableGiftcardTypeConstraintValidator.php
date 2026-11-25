<?php

namespace Drupal\picc_giftcard\Plugin\Validation\Constraint;

use Drupal\commerce_giftcard\Entity\GiftcardTypeInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates the PiccSellableGiftcardType constraint.
 */
class SellableGiftcardTypeConstraintValidator extends ConstraintValidator {

  /**
   * {@inheritdoc}
   */
  public function validate($items, Constraint $constraint): void {
    foreach ($items as $item) {
      $type = $item->entity;
      if ($type instanceof GiftcardTypeInterface && !picc_giftcard_type_is_sellable($type)) {
        $this->context->addViolation($constraint->message, ['%type' => $type->label()]);
      }
    }
  }

}

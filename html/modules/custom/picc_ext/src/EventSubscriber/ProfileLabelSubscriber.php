<?php

namespace Drupal\picc_ext\EventSubscriber;

use Drupal\profile\Event\ProfileLabelEvent;
use Drupal\user\UserInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Sets profile labels to the person's name instead of address/ID fallbacks.
 *
 * - Participant: uses the profile's own field_name.
 * - Customer: uses the owning user's field_name (overrides Commerce, which
 *   sets the label to the first address line).
 */
class ProfileLabelSubscriber implements EventSubscriberInterface {

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      'profile.label' => ['onLabel', -10],
    ];
  }

  /**
   * Sets the profile label to the relevant name field value.
   *
   * @param \Drupal\profile\Event\ProfileLabelEvent $event
   *   The profile label event.
   */
  public function onLabel(ProfileLabelEvent $event): void {
    $profile = $event->getProfile();
    $bundle = $profile->bundle();

    $name_entity = match ($bundle) {
      'participant' => $profile,
      'customer' => $profile->getOwner() instanceof UserInterface ? $profile->getOwner() : NULL,
      default => NULL,
    };

    if (!$name_entity || !$name_entity->hasField('field_name') || $name_entity->get('field_name')->isEmpty()) {
      return;
    }

    $name = $name_entity->get('field_name')->first();
    $given = $name->get('given')->getValue() ?? '';
    $family = $name->get('family')->getValue() ?? '';
    $label = trim("$given $family");

    if (!empty($label)) {
      $event->setLabel($label);
    }
  }

}

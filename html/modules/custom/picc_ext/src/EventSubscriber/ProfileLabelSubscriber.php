<?php

namespace Drupal\picc_ext\EventSubscriber;

use Drupal\profile\Event\ProfileLabelEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Sets the participant profile label to the person's name.
 */
class ProfileLabelSubscriber implements EventSubscriberInterface {

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      'profile.label' => 'onLabel',
    ];
  }

  /**
   * Sets the participant profile label to the name field value.
   *
   * @param \Drupal\profile\Event\ProfileLabelEvent $event
   *   The profile label event.
   */
  public function onLabel(ProfileLabelEvent $event): void {
    $profile = $event->getProfile();

    if ($profile->bundle() !== 'participant') {
      return;
    }

    if (!$profile->hasField('field_name') || $profile->get('field_name')->isEmpty()) {
      return;
    }

    $name = $profile->get('field_name')->first();
    $given = $name->get('given')->getValue() ?? '';
    $family = $name->get('family')->getValue() ?? '';
    $label = trim("$given $family");

    if (!empty($label)) {
      $event->setLabel($label);
    }
  }

}

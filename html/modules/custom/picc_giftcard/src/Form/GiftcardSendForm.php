<?php

namespace Drupal\picc_giftcard\Form;

use Drupal\commerce_giftcard\Entity\GiftcardInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\picc_giftcard\GiftcardCode;
use Drupal\picc_giftcard\GiftcardMailer;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Sends a gift card email to any address.
 *
 * For "I lost the email" calls, and for cards the club issues by hand.
 */
class GiftcardSendForm extends FormBase {

  public function __construct(
    protected readonly GiftcardMailer $mailer,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('picc_giftcard.mailer'), $container->get('entity_type.manager'));
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'picc_giftcard_send_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?GiftcardInterface $commerce_giftcard = NULL): array {
    $form_state->set('giftcard_id', $commerce_giftcard->id());
    $owner = $commerce_giftcard->get('uid')->entity;
    $default_email = $owner && $owner->isAuthenticated() ? $owner->getEmail() : '';

    $form['intro'] = [
      '#markup' => '<p>' . $this->t('Sends gift card %code with its printable PDF. Use this when someone lost the email, or to send a card the club issued.', ['%code' => GiftcardCode::format($commerce_giftcard->getCode())]) . '</p>',
    ];
    $form['email'] = [
      '#type' => 'email',
      '#title' => $this->t('Send to'),
      '#default_value' => $default_email,
      '#required' => TRUE,
      '#description' => $default_email ? $this->t('Filled in with the buyer’s email. You can change it.') : '',
    ];
    $form['langcode'] = [
      '#type' => 'radios',
      '#title' => $this->t('Email language'),
      '#description' => $this->t('The card itself is always in English and French.'),
      '#options' => ['en' => $this->t('English'), 'fr' => $this->t('French')],
      '#default_value' => $owner && $owner->isAuthenticated() && $owner->getPreferredLangcode() === 'fr' ? 'fr' : 'en',
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Send email'),
      '#button_type' => 'primary',
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $giftcard = $this->entityTypeManager->getStorage('commerce_giftcard')->load($form_state->get('giftcard_id'));
    $email = $form_state->getValue('email');
    if ($giftcard && $this->mailer->sendCard($giftcard, $email, $form_state->getValue('langcode'))) {
      $this->messenger()->addStatus($this->t('Gift card email sent to %email.', ['%email' => $email]));
      $form_state->setRedirect('entity.commerce_giftcard.collection');
    }
    else {
      $this->messenger()->addError($this->t('The email could not be sent. Check the logs and try again.'));
    }
  }

}

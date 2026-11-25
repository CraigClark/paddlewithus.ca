<?php

namespace Drupal\picc_giftcard\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\State\StateInterface;
use Drupal\file\FileUsage\FileUsageInterface;
use Drupal\picc_giftcard\GiftcardCardBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Lets admins swap the decorative graphic and contact email on the card.
 *
 * Stored in State, not config, so a deploy (config import) never overwrites
 * what an admin chose on the live site.
 */
class GiftcardSettingsForm extends FormBase {

  public function __construct(
    protected readonly StateInterface $state,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly FileUsageInterface $fileUsage,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('state'),
      $container->get('entity_type.manager'),
      $container->get('file.usage'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'picc_giftcard_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $fid = $this->state->get(GiftcardCardBuilder::STATE_GRAPHIC);
    $form['graphic'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('Card graphic'),
      '#description' => $this->t('Shown across the top of every printed gift card. PNG or JPG, at least 1200 pixels wide, wider than it is tall. Remove it to print the card with the logo only. A change applies to every card emailed or printed after you save.'),
      '#upload_location' => 'public://picc_giftcard',
      '#upload_validators' => [
        'FileExtension' => ['extensions' => 'png jpg jpeg'],
        'FileSizeLimit' => ['fileLimit' => 5 * 1024 * 1024],
      ],
      '#default_value' => $fid ? [$fid] : [],
    ];
    $form['contact_email'] = [
      '#type' => 'email',
      '#title' => $this->t('Contact email on the card'),
      '#default_value' => $this->state->get(GiftcardCardBuilder::STATE_CONTACT) ?: GiftcardCardBuilder::DEFAULT_CONTACT,
      '#required' => TRUE,
    ];
    $form['preview'] = [
      '#markup' => '<p>' . $this->t('To check the result, open any gift card at Commerce > Gift cards and select Print.') . '</p>',
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save'),
      '#button_type' => 'primary',
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $old_fid = $this->state->get(GiftcardCardBuilder::STATE_GRAPHIC);
    $new_fid = $form_state->getValue(['graphic', 0]) ?: NULL;
    $file_storage = $this->entityTypeManager->getStorage('file');

    if ($old_fid && $old_fid != $new_fid && ($old = $file_storage->load($old_fid))) {
      $this->fileUsage->delete($old, 'picc_giftcard', 'state', 1);
    }
    if ($new_fid && $new_fid != $old_fid && ($new = $file_storage->load($new_fid))) {
      $new->setPermanent();
      $new->save();
      $this->fileUsage->add($new, 'picc_giftcard', 'state', 1);
    }

    $this->state->set(GiftcardCardBuilder::STATE_GRAPHIC, $new_fid ? (int) $new_fid : NULL);
    $this->state->set(GiftcardCardBuilder::STATE_CONTACT, $form_state->getValue('contact_email'));
    $this->messenger()->addStatus($this->t('Gift card design saved.'));
  }

}

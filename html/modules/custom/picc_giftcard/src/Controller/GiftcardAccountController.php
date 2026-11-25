<?php

namespace Drupal\picc_giftcard\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\picc_giftcard\GiftcardCardBuilder;
use Drupal\picc_giftcard\GiftcardCode;
use Drupal\picc_giftcard\GiftcardLookup;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * "Gift cards" tab on the user's account page.
 */
class GiftcardAccountController extends ControllerBase {

  public function __construct(
    protected readonly GiftcardLookup $lookup,
    protected readonly GiftcardCardBuilder $cardBuilder,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('picc_giftcard.lookup'),
      $container->get('picc_giftcard.card_builder'),
    );
  }

  /**
   * The account owner and gift card admins can see the tab.
   */
  public function access(UserInterface $user, AccountInterface $account): AccessResultInterface {
    if ($account->hasPermission('administer commerce_giftcard')) {
      return AccessResult::allowed()->cachePerPermissions();
    }
    return AccessResult::allowedIf($account->isAuthenticated() && (int) $account->id() === (int) $user->id())
      ->cachePerUser();
  }

  /**
   * Lists the cards the user has used and the cards they bought.
   */
  public function page(UserInterface $user): array {
    $langcode = $this->languageManager()->getCurrentLanguage()->getId();
    $uid = (int) $user->id();
    $build = [
      '#cache' => ['tags' => ['commerce_giftcard_list', 'commerce_giftcard_transaction_list'], 'contexts' => ['user', 'languages:language_interface']],
      '#attached' => ['library' => ['picc_giftcard/giftcard']],
    ];

    $used = $this->lookup->usedBy($uid);
    $build['used'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['picc-giftcard-account-section']],
      'title' => ['#markup' => '<h2>' . $this->t('Gift cards you’ve used') . '</h2>'],
    ];
    if ($used) {
      $rows = [];
      foreach ($used as $card) {
        $rows[] = [
          GiftcardCode::format($card->getCode()),
          $this->cardBuilder->formatAmount($card->getBalance(), $langcode),
          $card->isEnabled() ? $this->t('Active') : $this->t('Disabled'),
        ];
      }
      $build['used']['table'] = [
        '#type' => 'table',
        '#header' => [$this->t('Code'), $this->t('Balance left'), $this->t('Status')],
        '#rows' => $rows,
      ];
      $build['used']['help'] = ['#markup' => '<p>' . $this->t('To use what’s left, enter the code in the gift card box at checkout.') . '</p>'];
    }
    else {
      $build['used']['empty'] = ['#markup' => '<p>' . $this->t('You haven’t used a gift card yet. When you do, its balance shows here.') . '</p>'];
    }

    $bought = $this->lookup->boughtBy($uid);
    if ($bought) {
      $rows = [];
      foreach ($bought as $card) {
        $to = $card->hasField('field_recipient_name') ? (string) $card->get('field_recipient_name')->value : '';
        $rows[] = [
          GiftcardCode::format($card->getCode()),
          $to,
          $this->cardBuilder->formatAmount($card->getBalance(), $langcode),
          [
            'data' => [
              '#type' => 'link',
              '#title' => $this->t('Print or download'),
              '#url' => Url::fromRoute('picc_giftcard.print', ['commerce_giftcard' => $card->id()]),
            ],
          ],
        ];
      }
      $build['bought'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['picc-giftcard-account-section']],
        'title' => ['#markup' => '<h2>' . $this->t('Gift cards you bought') . '</h2>'],
        'table' => [
          '#type' => 'table',
          '#header' => [$this->t('Code'), $this->t('To', [], ['context' => 'picc_giftcard']), $this->t('Balance left'), ''],
          '#rows' => $rows,
        ],
      ];
    }

    return $build;
  }

}

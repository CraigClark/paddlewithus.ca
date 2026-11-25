<?php

namespace Drupal\picc_giftcard\Controller;

use Drupal\commerce_giftcard\Entity\GiftcardInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\picc_giftcard\GiftcardCardBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * The backup print page and PDF download for a gift card.
 */
class GiftcardPrintController extends ControllerBase {

  public function __construct(
    protected readonly GiftcardCardBuilder $cardBuilder,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('picc_giftcard.card_builder'));
  }

  /**
   * Only the card's owner and gift card admins can see a card.
   */
  public function access(GiftcardInterface $commerce_giftcard, AccountInterface $account): AccessResultInterface {
    if ($account->hasPermission('administer commerce_giftcard')) {
      return AccessResult::allowed()->cachePerPermissions();
    }
    $is_owner = $account->isAuthenticated() && (int) $commerce_giftcard->get('uid')->target_id === (int) $account->id();
    return AccessResult::allowedIf($is_owner)
      ->cachePerPermissions()
      ->cachePerUser()
      ->addCacheableDependency($commerce_giftcard);
  }

  /**
   * Anyone with a valid signed link can see the card (link from the email).
   */
  public function accessSigned(GiftcardInterface $commerce_giftcard, string $token): AccessResultInterface {
    return AccessResult::allowedIf($this->cardBuilder->checkToken($commerce_giftcard, $token))->setCacheMaxAge(0);
  }

  /**
   * Shows the card as a print-ready page.
   */
  public function print(GiftcardInterface $commerce_giftcard, ?string $token = NULL): Response {
    $response = new Response($this->cardBuilder->buildHtml($commerce_giftcard, TRUE, $this->languageManager()->getCurrentLanguage()->getId(), $token));
    $response->headers->set('Content-Type', 'text/html; charset=utf-8');
    $response->headers->set('Cache-Control', 'private, no-store');
    $response->headers->set('X-Robots-Tag', 'noindex');
    return $response;
  }

  /**
   * Downloads the card as a PDF.
   */
  public function pdf(GiftcardInterface $commerce_giftcard, ?string $token = NULL): Response {
    $response = new Response($this->cardBuilder->buildPdf($commerce_giftcard, $this->languageManager()->getCurrentLanguage()->getId()));
    $response->headers->set('Content-Type', 'application/pdf');
    $response->headers->set('Content-Disposition', 'inline; filename="' . $this->cardBuilder->pdfFilename($commerce_giftcard) . '"');
    $response->headers->set('Cache-Control', 'private, no-store');
    $response->headers->set('X-Robots-Tag', 'noindex');
    return $response;
  }

}

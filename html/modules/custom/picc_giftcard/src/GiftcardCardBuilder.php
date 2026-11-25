<?php

namespace Drupal\picc_giftcard;

use Dompdf\Dompdf;
use Dompdf\Options;
use Drupal\commerce_giftcard\Entity\GiftcardInterface;
use Drupal\commerce_price\Price;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Component\Utility\Crypt;
use Drupal\Core\PrivateKey;
use Drupal\Core\Site\Settings;
use Drupal\Core\Url;
use Drupal\file\FileInterface;

/**
 * Builds the printable gift card as HTML and as a PDF.
 *
 * The same template is used for the PDF attached to the email and for the
 * backup print page. dompdf only understands simple CSS, so the template
 * sticks to tables, blocks and fixed sizes.
 */
class GiftcardCardBuilder {

  /**
   * State key for the decorative graphic's file ID.
   */
  const STATE_GRAPHIC = 'picc_giftcard.graphic_fid';

  /**
   * State key for the contact email printed on the card.
   */
  const STATE_CONTACT = 'picc_giftcard.contact_email';

  /**
   * Contact email used when none has been set.
   */
  const DEFAULT_CONTACT = 'petriecanoe@gmail.com';

  public function __construct(
    protected readonly RendererInterface $renderer,
    protected readonly StateInterface $state,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly ModuleExtensionList $moduleList,
    protected readonly FileSystemInterface $fileSystem,
    protected readonly LanguageManagerInterface $languageManager,
    protected readonly PrivateKey $privateKey,
  ) {}

  /**
   * Returns the contact email printed on cards.
   */
  public function getContactEmail(): string {
    return $this->state->get(static::STATE_CONTACT) ?: static::DEFAULT_CONTACT;
  }

  /**
   * Returns the decorative graphic file, if one is set.
   */
  public function getGraphic(): ?FileInterface {
    $fid = $this->state->get(static::STATE_GRAPHIC);
    if (!$fid) {
      return NULL;
    }
    $file = $this->entityTypeManager->getStorage('file')->load($fid);
    return $file instanceof FileInterface ? $file : NULL;
  }

  /**
   * Formats an amount for a language: "$250" in English, "250 $" in French.
   *
   * Cents are shown when there are any, or always with $always_cents.
   */
  public function formatAmount(?Price $price, string $langcode, bool $always_cents = FALSE): string {
    if (!$price) {
      return '';
    }
    $number = (float) $price->getNumber();
    $has_cents = $always_cents || abs($number - round($number)) > 0.001;
    if ($langcode === 'fr') {
      $formatted = number_format($number, $has_cents ? 2 : 0, ',', "\u{00A0}");
      return $formatted . "\u{00A0}$";
    }
    return '$' . number_format($number, $has_cents ? 2 : 0, '.', ',');
  }

  /**
   * Returns the values shown on a card, used by the template and the email.
   */
  public function getCardValues(GiftcardInterface $giftcard, bool $for_print = FALSE): array {
    $balance = $giftcard->getBalance();
    $values = [
      'amount_en' => $this->formatAmount($balance, 'en'),
      'amount_fr' => $this->formatAmount($balance, 'fr'),
      'code' => GiftcardCode::format($giftcard->getCode()),
      'to' => '',
      'from' => '',
      'message' => '',
      'contact_email' => $this->getContactEmail(),
    ];
    foreach (['to' => 'field_recipient_name', 'from' => 'field_from_name', 'message' => 'field_giftcard_message'] as $key => $field) {
      if ($giftcard->hasField($field) && !$giftcard->get($field)->isEmpty()) {
        $values[$key] = trim((string) $giftcard->get($field)->value);
        if ($for_print) {
          // The PDF font has no emoji, so they print as empty boxes.
          $values[$key] = trim(preg_replace('/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{FE0F}\x{200D}]/u', '', $values[$key]));
        }
      }
    }
    return $values;
  }

  /**
   * Returns the full HTML document for a card.
   *
   * @param \Drupal\commerce_giftcard\Entity\GiftcardInterface $giftcard
   *   The gift card.
   * @param bool $for_screen
   *   TRUE for the print page (adds print and download buttons).
   * @param string $langcode
   *   The language shown first and larger: 'en' or 'fr'.
   */
  public function buildHtml(GiftcardInterface $giftcard, bool $for_screen = FALSE, string $langcode = 'en', ?string $token = NULL): string {
    $graphic = $this->getGraphic();
    $build = [
      '#theme' => 'picc_giftcard_card',
      '#card' => $this->getCardValues($giftcard, TRUE),
      '#logo' => $this->dataUri(DRUPAL_ROOT . '/' . $this->moduleList->getPath('picc_giftcard') . '/images/logo.png'),
      '#graphic' => $graphic ? $this->dataUri($graphic->getFileUri()) : NULL,
      '#primary' => $langcode === 'fr' ? 'fr' : 'en',
      '#for_screen' => $for_screen,
      '#pdf_url' => $for_screen ? ($token ? Url::fromRoute('picc_giftcard.pdf_signed', ['commerce_giftcard' => $giftcard->id(), 'token' => $token], ['language' => $this->languageManager->getLanguage($langcode)]) : Url::fromRoute('picc_giftcard.pdf', ['commerce_giftcard' => $giftcard->id()], ['language' => $this->languageManager->getLanguage($langcode)]))->toString() : NULL,
    ];
    return (string) $this->renderer->renderInIsolation($build);
  }

  /**
   * Returns the token that lets anyone with the email open a card's print page.
   *
   * Tied to the card ID and code, and signed with the site's private key and
   * hash salt, so it can't be guessed or reused for another card.
   */
  public function signedToken(GiftcardInterface $giftcard): string {
    return Crypt::hmacBase64('picc_giftcard:' . $giftcard->id() . ':' . GiftcardCode::normalize($giftcard->getCode()), $this->privateKey->get() . Settings::getHashSalt());
  }

  /**
   * Checks a print page token.
   */
  public function checkToken(GiftcardInterface $giftcard, string $token): bool {
    return hash_equals($this->signedToken($giftcard), $token);
  }

  /**
   * Returns the print page URL that works without logging in.
   */
  public function signedPrintUrl(GiftcardInterface $giftcard, string $langcode = 'en', bool $absolute = TRUE): Url {
    return Url::fromRoute('picc_giftcard.print_signed', ['commerce_giftcard' => $giftcard->id(), 'token' => $this->signedToken($giftcard)], ['absolute' => $absolute, 'language' => $this->languageManager->getLanguage($langcode)]);
  }

  /**
   * Returns the PDF for a card, as a string.
   */
  public function buildPdf(GiftcardInterface $giftcard, string $langcode = 'en'): string {
    // dompdf caches font metrics and writes temporary files. By default it
    // writes the font cache into vendor/, which is read-only on some servers.
    // Point both at a folder in Drupal's temporary directory instead.
    $directory = 'temporary://picc_giftcard_pdf';
    $this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
    $work_dir = $this->fileSystem->realpath($directory);
    if (!$work_dir) {
      throw new \RuntimeException('The gift card PDF folder in the temporary directory is not available.');
    }

    $options = new Options();
    // Images are passed as data URIs, so nothing has to be fetched.
    $options->setIsRemoteEnabled(FALSE);
    $options->setDefaultFont('DejaVu Sans');
    $options->setChroot([$work_dir]);
    $options->setTempDir($work_dir);
    $options->setFontCache($work_dir);
    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($this->buildHtml($giftcard, FALSE, $langcode));
    $dompdf->setPaper('letter', 'portrait');
    $dompdf->render();
    return (string) $dompdf->output();
  }

  /**
   * Returns the file name for a card's PDF.
   */
  public function pdfFilename(GiftcardInterface $giftcard): string {
    return 'PICC-gift-card-' . substr(GiftcardCode::normalize($giftcard->getCode()), 0, 4) . '.pdf';
  }

  /**
   * Returns a base64 data URI for an image file, or NULL if it can't be read.
   */
  protected function dataUri(string $uri_or_path): ?string {
    $data = @file_get_contents($uri_or_path);
    if ($data === FALSE || $data === '') {
      return NULL;
    }
    $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($data) ?: 'image/png';
    return 'data:' . $mime . ';base64,' . base64_encode($data);
  }

}

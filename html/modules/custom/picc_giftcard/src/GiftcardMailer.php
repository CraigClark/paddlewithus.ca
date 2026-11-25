<?php

namespace Drupal\picc_giftcard;

use Drupal\commerce_giftcard\Entity\GiftcardInterface;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\symfony_mailer\Attachment;
use Drupal\symfony_mailer\EmailInterface;
use Drupal\symfony_mailer\MailerPlusInterface;
use Psr\Log\LoggerInterface;

/**
 * Sends the gift card email, with one PDF card attached per gift card.
 *
 * The layout matches the order receipt (blue header, white body, grey boxes)
 * and uses inline styles only, since mail apps ignore style sheets. No
 * images, so it reads the same when a mail app blocks them.
 *
 * The email is in the language the order was placed in. Each card's details
 * repeat the key lines in the other language, smaller.
 */
class GiftcardMailer {

  use StringTranslationTrait;

  /**
   * Colours, the same as the order receipt.
   */
  const BLUE = '#0066cc';
  const TEXT = '#333333';
  const MUTED = '#666666';
  const BOX = '#f5f5f5';
  const BORDER = '#dddddd';

  public function __construct(
    protected readonly MailerPlusInterface $mailer,
    protected readonly GiftcardCardBuilder $cardBuilder,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly LoggerInterface $logger,
    protected readonly DateFormatterInterface $dateFormatter,
    protected readonly LanguageManagerInterface $languageManager,
  ) {}

  /**
   * Sends the gift card email for a placed order to its customer.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   * @param \Drupal\commerce_giftcard\Entity\GiftcardInterface[] $giftcards
   *   The gift cards bought on the order.
   * @param string|null $langcode
   *   The language the order was placed in. Defaults to the customer's
   *   preferred language.
   */
  public function sendForOrder(OrderInterface $order, array $giftcards, ?string $langcode = NULL): bool {
    if (!$giftcards) {
      return FALSE;
    }
    $customer = $order->getCustomer();
    $to = $customer && $customer->isAuthenticated() ? $customer : $order->getEmail();
    if (!$to) {
      $this->logger->error('Gift card email for order @id not sent: no email address.', ['@id' => $order->id()]);
      return FALSE;
    }
    $langcode = $this->cleanLangcode($langcode ?? ($customer && $customer->isAuthenticated() ? $customer->getPreferredLangcode() : 'en'));
    $o = ['langcode' => $langcode];
    $count = count($giftcards);
    $number = $order->getOrderNumber() ?: $order->id();

    $subject = $this->formatPlural($count, 'Your Petrie Island Canoe Club gift card (order @number)', 'Your Petrie Island Canoe Club gift cards (order @number)', ['@number' => $number], $o);
    $title = $count > 1 ? $this->t('Your gift cards', [], $o) : $this->t('Your gift card', [], $o);

    $name = $customer && $customer->isAuthenticated() ? $customer->getDisplayName() : '';
    $intro = ($name ? '<p style="font-size:16px;margin:0 0 15px;">' . $this->t('Hi @name,', ['@name' => $name], $o) . '</p>' : '')
      . '<p style="font-size:14px;margin:0 0 15px;">' . $this->formatPlural($count, 'Thank you for buying a gift card. The card is below and attached as a PDF you can print.', 'Thank you for buying gift cards. The cards are below and attached as PDFs you can print, one per card.', [], $o) . '</p>';

    $rows = [
      [$this->t('Order number', [], $o), $this->esc($number)],
      [$this->t('Order date', [], $o), $this->esc($this->dateFormatter->format($order->getPlacedTime() ?: time(), 'custom', 'F j, Y', NULL, $langcode))],
      [$this->t('Total', [], $o), '<strong style="color:' . self::BLUE . ';font-size:16px;">' . $this->esc($this->cardBuilder->formatAmount($order->getTotalPrice(), $langcode, TRUE)) . '</strong>'],
    ];
    $receipt = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:' . self::BOX . ';border:1px solid ' . self::BORDER . ';"><tr><td style="padding:15px;">'
      . '<table role="presentation" width="100%" cellpadding="5" cellspacing="0">';
    foreach ($rows as $i => [$label, $value]) {
      $top = $i === count($rows) - 1 ? 'border-top:2px solid ' . self::BLUE . ';padding-top:10px;' : '';
      $receipt .= '<tr><td style="font-size:14px;' . $top . '"><strong>' . $label . '</strong></td><td style="font-size:14px;text-align:right;' . $top . '">' . $value . '</td></tr>';
    }
    $receipt .= '</table></td></tr></table>';

    $cards = '';
    foreach (array_values($giftcards) as $index => $giftcard) {
      $cards .= $this->cardBlock($giftcard, $index + 1, $count, $langcode);
    }

    $body = $this->layout($title, $intro . $receipt . $cards, $langcode);
    return $this->send($to, $subject, $body, $giftcards, $langcode, ['order' => $order->id()]);
  }

  /**
   * Sends one gift card to any address (admin "Send email" button).
   */
  public function sendCard(GiftcardInterface $giftcard, string $email, string $langcode = 'en'): bool {
    $langcode = $this->cleanLangcode($langcode);
    $o = ['langcode' => $langcode];
    $subject = $this->t('Your Petrie Island Canoe Club gift card', [], $o);
    $intro = '<p style="font-size:14px;margin:0 0 15px;">' . $this->t('Here is your gift card. It is below and attached as a PDF you can print.', [], $o) . '</p>';
    $body = $this->layout($this->t('Your gift card', [], $o), $intro . $this->cardBlock($giftcard, 1, 1, $langcode), $langcode);
    return $this->send($email, $subject, $body, [$giftcard], $langcode, ['giftcard' => $giftcard->id()]);
  }

  /**
   * Builds one card: value, To/From, message, code, how to use it, print link.
   */
  protected function cardBlock(GiftcardInterface $giftcard, int $number, int $count, string $langcode): string {
    $values = $this->cardBuilder->getCardValues($giftcard);
    $other = $langcode === 'fr' ? 'en' : 'fr';
    $p = ['langcode' => $langcode];
    $s = ['langcode' => $other];
    $pc = $p + ['context' => 'picc_giftcard'];
    $sc = $s + ['context' => 'picc_giftcard'];

    $heading = $count > 1
      ? $this->t('Gift card @number of @count', ['@number' => $number, '@count' => $count], $p)
      : $this->t('Gift card', [], $pc);

    $label = fn($word) => '<span style="color:' . self::MUTED . ';font-size:12px;">' . $this->t($word, [], $pc) . ' / ' . $this->t($word, [], $sc) . '</span>';

    $people = '';
    foreach (['to' => 'To', 'from' => 'From'] as $key => $word) {
      if ($values[$key]) {
        $people .= '<tr><td style="padding:2px 10px 2px 0;white-space:nowrap;">' . $label($word) . '</td><td style="padding:2px 0;font-size:15px;"><strong>' . $this->esc($values[$key]) . '</strong></td></tr>';
      }
    }
    $people = $people ? '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 12px;">' . $people . '</table>' : '';

    $message = $values['message']
      ? '<p style="margin:0 0 14px;font-size:15px;font-style:italic;text-align:center;color:#444444;">' . nl2br($this->esc($values['message'])) . '</p>'
      : '';

    $how = $this->t('Register for any program at petriecanoe.ca and enter this code at checkout.', [], $p) . ' ' . $this->t("This card doesn't expire. It can't be redeemed for cash unless required by law.", [], $p);
    $how_other = $this->t('Register for any program at petriecanoe.ca and enter this code at checkout.', [], $s) . ' ' . $this->t("This card doesn't expire. It can't be redeemed for cash unless required by law.", [], $s);

    $print_url = Url::fromRoute('picc_giftcard.print', ['commerce_giftcard' => $giftcard->id()], ['absolute' => TRUE, 'language' => $this->languageManager->getLanguage($langcode)])->toString();

    return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:20px;border:2px solid ' . self::BLUE . ';border-radius:8px;">'
      . '<tr><td style="background-color:' . self::BLUE . ';padding:10px 16px;color:#ffffff;font-size:16px;font-weight:bold;border-radius:6px 6px 0 0;">' . $this->esc($heading) . '</td></tr>'
      . '<tr><td style="padding:16px;">'
      // Value.
      . '<p style="margin:0;">' . $label('Value') . '</p>'
      . '<p style="margin:0 0 12px;font-size:30px;font-weight:bold;color:' . self::BLUE . ';line-height:1.1;">' . $this->esc($values['amount_' . $langcode]) . ' <span style="font-size:13px;font-weight:normal;color:' . self::MUTED . ';">' . $this->esc($values['amount_' . $other]) . '</span></p>'
      . $people
      . $message
      // Code.
      . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#eef3f8;border:1px solid #c7d4e2;margin:0 0 14px;"><tr><td style="padding:12px;text-align:center;">'
      . '<div style="margin-bottom:4px;">' . $label('Gift card code') . '</div>'
      . '<div style="font-family:Consolas,Menlo,Courier New,monospace;font-size:24px;font-weight:bold;letter-spacing:3px;color:#13324f;">' . $this->esc($values['code']) . '</div>'
      . '</td></tr></table>'
      // How to use it.
      . '<p style="margin:0 0 6px;font-size:14px;">' . $this->esc($how) . '</p>'
      . '<p lang="' . $other . '" style="margin:0 0 14px;font-size:12px;color:' . self::MUTED . ';">' . $this->esc($how_other) . '</p>'
      // Print link.
      . '<table role="presentation" cellpadding="0" cellspacing="0"><tr><td style="background-color:' . self::BLUE . ';border-radius:6px;">'
      . '<a href="' . $this->esc($print_url) . '" style="display:inline-block;padding:10px 18px;color:#ffffff;font-size:14px;font-weight:bold;text-decoration:none;">' . $this->t('Print your gift card', [], $p) . '</a>'
      . '</td></tr></table>'
      . '<p style="margin:6px 0 0;font-size:12px;color:' . self::MUTED . ';">' . $this->t('The PDF is also attached. The link needs you to be logged in.', [], $p) . '</p>'
      . '</td></tr></table>';
  }

  /**
   * Wraps content in the receipt-style layout.
   */
  protected function layout($title, string $content, string $langcode): array {
    $o = ['langcode' => $langcode];
    $contact = $this->cardBuilder->getContactEmail();
    $html = '<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;max-width:600px;margin:0 auto;color:' . self::TEXT . ';font-family:Arial, sans-serif;">'
      . '<tr><td style="background-color:' . self::BLUE . ';padding:20px;text-align:center;"><h1 style="color:#ffffff;font-size:24px;margin:0;">' . $this->esc($title) . '</h1></td></tr>'
      . '<tr><td style="background-color:#ffffff;padding:20px;">' . $content . '</td></tr>'
      . '<tr><td style="background-color:#ffffff;border-top:1px solid ' . self::BORDER . ';padding:20px;">'
      . '<p style="font-size:14px;margin:0 0 10px;">' . $this->t('Keep the code private. Anyone who has it can use the card.', [], $o) . '</p>'
      . '<p style="font-size:14px;margin:0 0 10px;">' . $this->t('If you have any questions, please contact us.', [], $o) . '</p>'
      . '<p style="color:' . self::MUTED . ';font-size:14px;margin:0;"><strong>' . $this->t('Petrie Island Canoe Club', [], $o) . '</strong><br>' . $this->t('Email: @email', ['@email' => $contact], $o) . '</p>'
      . '</td></tr>'
      . '<tr><td style="background-color:' . self::BOX . ';color:#999999;font-size:12px;padding:20px;text-align:center;"><p style="margin:0;">' . $this->t('This is an automated message. Please do not reply to this email.', [], $o) . '</p></td></tr>'
      . '</table>';
    // Built from translated strings and escaped values only. Markup keeps
    // the inline styles, which #markup's filter would strip.
    return ['content' => ['#markup' => Markup::create($html)]];
  }

  /**
   * Builds the PDFs and sends the email.
   */
  protected function send($to, $subject, array $body, array $giftcards, string $langcode, array $context): bool {
    // symfony_mailer 2.x: addresses are set in the init phase, everything
    // else in the build phase.
    $email = $this->mailer->newEmail('picc_giftcard.giftcard')->setTo($to);
    $email->addCallback(function (EmailInterface $email) use ($subject, $body, $giftcards, $langcode) {
      $email->setSubject((string) $subject)->setBody($body);
      foreach ($giftcards as $giftcard) {
        try {
          $email->attach(Attachment::fromData($this->cardBuilder->buildPdf($giftcard, $langcode), $this->cardBuilder->pdfFilename($giftcard), 'application/pdf'));
        }
        catch (\Throwable $e) {
          // Send the email anyway: the code is in the body and the print
          // page is linked. Log so an admin can resend later.
          $this->logger->error('Could not build the PDF for gift card @id: @message', ['@id' => $giftcard->id(), '@message' => $e->getMessage()]);
        }
      }
    });
    $sent = $email->send();
    $this->logger->info('Gift card email @result (@context).', [
      '@result' => $sent ? 'sent' : 'FAILED',
      '@context' => http_build_query($context, '', ', '),
    ]);
    return $sent;
  }

  /**
   * Escapes text for HTML.
   */
  protected function esc($text): string {
    return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
  }

  /**
   * Only English and French are supported. Anything else falls back to English.
   */
  protected function cleanLangcode(?string $langcode): string {
    return $langcode === 'fr' ? 'fr' : 'en';
  }

}

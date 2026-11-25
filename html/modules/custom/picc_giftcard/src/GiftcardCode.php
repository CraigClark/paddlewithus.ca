<?php

namespace Drupal\picc_giftcard;

/**
 * Cleans and formats gift card codes.
 *
 * Codes are stored in upper case with letters and digits only. People see
 * them in groups of 4 (ABCD-EFGH-JKMN) and can type them with or without
 * dashes, spaces or lower case letters.
 */
class GiftcardCode {

  /**
   * Returns a code with everything but letters and digits removed, upper case.
   *
   * Generated codes never contain the digits 0 or 1 (Drupal's password
   * generator leaves them out), so a typed 0 or 1 is read as the letter O or
   * I. Codes saved by hand go through the same cleanup on save, so stored
   * codes and typed codes always match.
   */
  public static function normalize(?string $code): string {
    $code = preg_replace('/[^A-Z0-9]/', '', strtoupper(trim((string) $code)));
    return strtr($code, ['0' => 'O', '1' => 'I']);
  }

  /**
   * Returns a code split into groups of 4 with dashes, for display.
   */
  public static function format(?string $code): string {
    $code = static::normalize($code);
    return implode('-', str_split($code, 4));
  }

}

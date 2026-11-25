<?php

namespace Drupal\Tests\picc_giftcard\Unit;

use Drupal\picc_giftcard\GiftcardCode;
use Drupal\Tests\UnitTestCase;

/**
 * Tests gift card code cleanup and formatting.
 *
 * @coversDefaultClass \Drupal\picc_giftcard\GiftcardCode
 * @group picc_giftcard
 */
class GiftcardCodeTest extends UnitTestCase {

  /**
   * @covers ::normalize
   * @dataProvider normalizeProvider
   */
  public function testNormalize(?string $input, string $expected): void {
    $this->assertSame($expected, GiftcardCode::normalize($input));
  }

  /**
   * Typed codes and what they should match.
   */
  public static function normalizeProvider(): array {
    return [
      'already clean' => ['FSTJTXK6ZIFV', 'FSTJTXK6ZIFV'],
      'lower case' => ['fstjtxk6zifv', 'FSTJTXK6ZIFV'],
      'dashes from the email' => ['FSTJ-TXK6-ZIFV', 'FSTJTXK6ZIFV'],
      'pasted with spaces' => ['  fstj txk6 zifv ', 'FSTJTXK6ZIFV'],
      'zero typed for O' => ['S0VA', 'SOVA'],
      'one typed for I' => ['Z1FV', 'ZIFV'],
      'other punctuation' => ['ab.cd/ef_gh', 'ABCDEFGH'],
      'empty' => ['', ''],
      'null' => [NULL, ''],
    ];
  }

  /**
   * @covers ::format
   */
  public function testFormat(): void {
    $this->assertSame('FSTJ-TXK6-ZIFV', GiftcardCode::format('fstjtxk6zifv'));
    $this->assertSame('ABCD-EF', GiftcardCode::format('ABCDEF'));
    $this->assertSame('', GiftcardCode::format(''));
  }

}

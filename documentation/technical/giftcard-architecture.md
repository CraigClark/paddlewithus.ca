# Gift cards: architecture, testing and deployment

Gift cards are built on the contrib module `commerce_giftcard` (2.0.0) plus our own `picc_giftcard` module. We don't patch the contrib module. Everything we change goes through hooks, an order processor and an event subscriber.

## What does what

| Piece | Provided by |
|---|---|
| Gift card entity, balance, transaction log, admin list | `commerce_giftcard` |
| Gift card box at checkout (`commerce_giftcard_redemption` pane, sidebar, multiple cards allowed) | `commerce_giftcard` |
| Creating cards when an order is placed (`commerce_order.place.pre_transition`) | `commerce_giftcard` |
| To, From and message fields, copied to the card after the order is placed | `picc_giftcard` |
| Gift card email with one PDF per card | `picc_giftcard` |
| PDF card and backup print page | `picc_giftcard` + `dompdf/dompdf` |
| Login required to buy | `picc_giftcard` |
| Safety checks (disabled cards, no discounts on gift cards, no paying for gift cards with gift cards) | `picc_giftcard` |
| Stock cap per amount | `commerce_stock` (`field_capacity`, `local_stock`) |

## Data model

- Product type `giftcard`, one product ("Gift cards", alias `/gift-cards`). Title and body are translatable.
- Variation type `giftcard` with the `commerce_giftcard_purchaseable` trait. One variation per amount. Fields: `price`, `commerce_giftcard_amount` (face value, always set it), `commerce_giftcard_type` (`standard`), `field_capacity` (stock).
- Order item type `giftcard_purchase`: `field_recipient_name`, `field_from_name`, `field_giftcard_message`.
- Gift card type `standard` (12-character codes), with the same 3 fields.
- Club notification `notify_picc` now fires for `activity` and `giftcard` variations.

## Flows

**Buying.** The product page uses the standard add to cart form (`add_to_cart` form mode of `giftcard_purchase`). Anonymous users see a "Log in or create account to buy" button instead. Checkout is the normal flow. When the order is placed, `commerce_giftcard` creates one card per unit and logs a "Bought" transaction that points at the order item. `GiftcardOrderSubscriber` (`place.post_transition`, priority -100) finds the cards through that transaction, copies the 3 fields onto them and calls `GiftcardMailer::sendForOrder()`. Email failures are logged and never stop the order.

**Redeeming.** The recipient enters the code in the checkout sidebar. `_picc_giftcard_clean_code_input()` runs first: it trims, removes spaces and dashes, upper-cases, and turns 0 into O and 1 into I (generated codes never contain 0 or 1). If someone types a gift card code into the coupon box (it sits right above the gift card box), `_picc_giftcard_coupon_is_giftcard()` replaces "The provided coupon code is invalid" with a message pointing to the gift card box. Real coupon codes are checked first and left alone.

`GiftcardGuardProcessor` (order processor, priority -990, just before `commerce_giftcard`'s at -1000) then:

- removes disabled cards from the order (contrib issue 3620707),
- removes all cards from an order that buys a gift card,
- strips promotion adjustments from gift card lines, including "display inclusive" ones that also lower the unit price.

**Codes.** `picc_giftcard_commerce_giftcard_presave()` normalizes every code on save, so stored codes and typed codes always match (contrib issue 3620718). Codes are shown in groups of 4 (`ABCD-EFGH-JKMN`).

**Printing.** `GiftcardCardBuilder` renders `picc-giftcard-card.html.twig` (one Letter page, card on the top half) to HTML for the print page and to PDF with dompdf. dompdf only handles simple CSS, so the template uses tables and fixed sizes. Images are inlined as data URIs, so dompdf never fetches anything. dompdf's font cache and temp files go to `temporary://picc_giftcard_pdf`, not `vendor/` (its default), because `vendor/` can be read-only on servers. The card text is bilingual: the template calls `t()` with an explicit `langcode` for each language, and short words use the `picc_giftcard` context so they don't pick up core translations ("From" would otherwise be "A partir de").

**Card graphic and contact email** live in State (`picc_giftcard.graphic_fid`, `picc_giftcard.contact_email`), not config, so a deploy never overwrites what an admin chose on the live site. `hook_install()` sets the holly as the starting graphic.

**Gift card type delete.** `picc_giftcard_commerce_giftcard_type_access()` forbids deleting a type that any variation or card uses (contrib issue 3620714). The type's edit page explains why.

**Reporting.** `picc_giftcard.views.inc` adds two Views relationships on gift card transactions: to the order a redemption paid for, and to the order item that bought the card (contrib issue 3518916). The report itself waits on the bookkeeper.

## Checkout and account changes (November 25, 2026, from Craig's review)

- **Gift card box.** Framed box with a heading and "You can use more than one card". Lists the person's own cards with money left (cards they've redeemed before, from `GiftcardLookup::usableBy()`). Each listed card has a "Use this card" button (`_picc_giftcard_use_card_submit()`, same AJAX refresh as the module's own button, only accepts cards from `usableBy()` for the order's customer). The list hides once the order total is $0. Cards they bought for others are never listed. Applied cards show what this order uses and what's left. The button changes to "Redeem another gift card". Built in `_picc_giftcard_build_redemption_box()`. The list lives inside the inline form so it redraws after AJAX, and the order is reloaded because the checkout form holds an older copy.
- **Coupon box.** Label changed to "Discount code" with a line saying gift card codes go in the gift card box.
- **Review step.** Both boxes are hidden. The order summary shows what was applied, and "Go back" lets people change it.
- **Product page.** Variation price is hidden from the injected fields. "You pay $225.00" shows under the amount only when the price and the card value differ.
- **Account tab.** `/user/{user}/gift-cards`: cards used (with balance) and cards bought (with print link).
- **Language.** The email and PDF lead with the language the order was placed in (current interface language at placement), the other language second and smaller. The print page and PDF download use the current page language.
- **Email layout.** Matches the order receipt: blue header, grey order box, each card in a framed block. Inline styles only, passed as `Markup` because `#markup` filtering strips `style` attributes.

## Complimentary cards

Second gift card type, `complimentary`, for cards the club gives away. Fields: To, From, Message, `field_complimentary_reason` (list, required: raffle_prize, volunteer_thank_you, other) and `field_complimentary_note`. There's deliberately no donor option: the club can't give gifts in return for donations.

Each type has a third-party setting `picc_giftcard.sellable` ("Can be sold on the site", schema in `config/schema/picc_giftcard.schema.yml`). Standard is sellable, Complimentary isn't, new types default to not sellable. Enforced by:

- `PiccSellableGiftcardType` constraint on the variation's `commerce_giftcard_type` field (added in `picc_giftcard_entity_bundle_field_info_alter()`).
- `picc_giftcard_options_list_alter()`, so the Type select only lists sellable types.
- `_picc_giftcard_add_to_cart_require_sellable()`, a backstop on the add to cart form.

The Generate form gets Reason and Note (shown for Complimentary) and its submit is replaced with the same batch plus those 2 values (`_picc_giftcard_generate_submit()`).

## Permissions

Commerce managers have `administer commerce_giftcard` (the module's only permission that covers editing cards and adding transactions). That permission also allows deleting, so `picc_giftcard_commerce_giftcard_access()` forbids `delete` unless the user also has `delete gift cards` (defined in `picc_giftcard.permissions.yml`, not granted to any role, so only administrators have it). This covers the per-card delete form and the bulk delete form.

## Routes

| Path | Who | What |
|---|---|---|
| `/gift-card/{id}/print` | card owner, gift card admins | Print page |
| `/gift-card/{id}/pdf` | card owner, gift card admins | PDF download |
| `/admin/commerce/giftcards/{id}/send` | `administer commerce_giftcard` | Send the gift card email to any address |
| `/admin/commerce/config/giftcard-card` | `administer commerce_giftcard` | Card graphic and contact email |
| `/user/{user}/gift-cards` | the account owner, gift card admins | Gift cards used and bought |

## Key files

- `html/modules/custom/picc_giftcard/picc_giftcard.module`: hooks (code cleanup, login gate, checkout alters, delete guard, operations, cart link)
- `src/OrderProcessor/GiftcardGuardProcessor.php`
- `src/EventSubscriber/GiftcardOrderSubscriber.php`
- `src/GiftcardMailer.php`, `src/GiftcardCardBuilder.php`, `src/GiftcardCode.php`
- `templates/picc-giftcard-card.html.twig`
- `images/logo.png`, `images/holly.png` (converted from SVG with `rsvg-convert`, since dompdf's SVG support is weak)
- French strings: `picc_registration/translations/fr.po` (section "Gift cards"), imported by `picc_registration_update_10017()`

## Gotchas

- **symfony_mailer 2.x phases.** Addresses can only be set in the init phase. Subject, body and attachments must be set in a build callback (`$email->addCallback(...)`). Setting them directly throws a `LogicException`.
- **commerce_email doesn't fire from drush.** It registers its listeners on the kernel request event. Scripts that place orders won't send the club notification. The gift card email (ours) does send from drush.
- **Admins can edit a card's balance directly** on its edit form. That skips the transaction log. Use "Add transaction" instead (the admin guide says so).
- **Order-level fixed discounts** (for example "$100 off the order") are split across lines. The gift card share is removed, so the buyer gets less than the full discount on a mixed order.

## Testing

Unit test: `vendor/bin/phpunit -c html/core html/modules/custom/picc_giftcard/tests/src/Unit`.

Manual scenarios checked on ddev, November 24, 2026:

1. Buy 3 cards on one order: 3 cards, To/From/message copied, one email with 3 PDFs.
2. $400 program with a $250 card: total $150.
3. Same order with a $250 and a $500 card: total $0, $500 card used for $150.
4. Disable a card that's on a cart: it drops off.
5. Order that buys a gift card: gift card box hidden, any applied card removed.
6. 3 promotion types (order %, item % display inclusive, order fixed) on a cart with gift cards: gift card lines stay at full price.
7. Browser: $0 checkout paid fully by a gift card, code typed as ` suva whsg-mz7v `. Order completes, no payment step, balance drops.
8. Print page: owner and admin allowed, other user and anonymous denied.
9. Gift card type delete: denied while in use, warning shown.
10. Sold out amount: add to cart button becomes a disabled "Out of stock".
11. Admin "Send email" in French.

Not tested: a real Stripe payment for a gift card, and the club notification firing from a real placed gift card order (it was triggered by hand from drush) (the browser test stopped before card entry), and the guest checkout block end to end (anonymous users can't reach the add to cart form, so the backstop only matters if a gift card gets into an anonymous cart some other way).

## Deployment

1. `composer install` (adds `drupal/commerce_giftcard` and `dompdf/dompdf`).
2. `drush cim -y` (installs `commerce_giftcard` and `picc_giftcard`, the gift card types, fields, checkout pane, permissions).
3. `drush updb -y` (imports the French strings).
4. `drush cr`.
5. Create the "Gift cards" product, its amounts and stock (see the user guide). Products are content, not config.
6. Add the French translation of the product and a French URL alias.
7. Add a menu link to `/gift-cards`.
8. After the first real sale, check **Reports > Recent log messages** for `picc_giftcard` errors, and confirm the buyer got the PDF. If the PDF can't be built, the email still goes out without it and the error is only in the log.

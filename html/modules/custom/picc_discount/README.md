# PICC Family Discount

Provides graduated multi-participant family discounts for the Petrie Island Canoe Club (PICC).

## What It Does

Applies progressive discounts when multiple participants (e.g., siblings) register for the same program session:

- **Participant 1**: 0% discount (pays full price)
- **Participant 2**: 10% discount (example)
- **Participant 3**: 20% discount (example)
- **Participant 4**: 30% discount (example)
- **Participant 5+**: 100% discount (free) (example)

The discount percentages are configurable per variant, allowing different programs to have different discount structures.

## How It Works

1. Groups order items by variation (e.g., "Canoe Kids - Week 1")
2. Counts how many order items exist for each variation
3. Applies graduated discounts based on position:
   - 1st registration for that variant: discount_1 percentage
   - 2nd registration for that variant: discount_2 percentage
   - 3rd registration for that variant: discount_3 percentage
   - 4th registration for that variant: discount_4 percentage
   - 5th+ registrations for that variant: discount_5_up percentage
4. Only processes variants that have discount values > 0 (all zeros = no family discount)

### Important: Runs After Other Promotions

The family discount is calculated on the **already-discounted price** after early bird, coupons, or other promotions have been applied. This prevents over-discounting while still rewarding multi-child families.

Set the promotion weight to 10 or higher to ensure it runs last.

## Requirements

### Variant Fields Required

Your product variations MUST have these five fields:

| Field Machine Name | Type | Label | Default Value |
|-------------------|------|-------|---------------|
| `field_discount_1` | Integer | Participant 1 Discount (%) | 0 |
| `field_discount_2` | Integer | Participant 2 Discount (%) | 0 |
| `field_discount_3` | Integer | Participant 3 Discount (%) | 0 |
| `field_discount_4` | Integer | Participant 4 Discount (%) | 0 |
| `field_discount_5_up` | Integer | Participant 5+ Discount (%) | 0 |

**Field Settings:**
- Type: Integer
- Minimum: 0
- Maximum: 100
- Default value: 0
- Required: No

## Installation

### Step 1: Add Fields to Product Variation Type

1. Go to: **Commerce → Configuration → Product variation types**
2. Click on your variation type (e.g., "Activity Session")
3. Click: **Manage fields**
4. Add five new fields with the exact machine names listed above
5. For each field:
   - Type: Integer
   - Minimum value: 0
   - Maximum value: 100
   - Default value: 0
   - Suffix: % (optional, for clarity in the UI)

### Step 2: Enable the Module

```bash
drush en picc_discount
drush cr
```

### Step 3: Create the Promotion

1. Go to: **Commerce → Promotions**
2. Click: **Add promotion**
3. Configure:
   - **Name**: Family Multi-Participant Discount
   - **Offer type**: Select "PICC Family Discount (Graduated)"
   - **Weight**: 10 (ensures it runs after early bird/coupons)
   - **Status**: Enabled
4. **Conditions**: Leave empty (applies to all orders)
5. Save

## Usage

### Setting Up Discounts for a Program

1. Edit a product variation (e.g., "Canoe Kids - Week 1")
2. Set discount percentages:
   ```
   Participant 1 Discount: 0%
   Participant 2 Discount: 10%
   Participant 3 Discount: 20%
   Participant 4 Discount: 30%
   Participant 5+ Discount: 100%
   ```
3. Save

### Disabling Family Discount for Specific Programs

To disable family discounts for a program (e.g., "Special Workshop"):

1. Edit the variation
2. Leave all discount fields at 0%:
   ```
   Participant 1 Discount: 0%
   Participant 2 Discount: 0%
   Participant 3 Discount: 0%
   Participant 4 Discount: 0%
   Participant 5+ Discount: 0%
   ```
3. Save

The module will skip variations where all discount fields are 0.

## Examples

### Example 1: Two Siblings, Same Session

**Cart:**
- Mike for Canoe Kids Week 1 ($100)
- Mary for Canoe Kids Week 1 ($100)

**Discount Settings on "Canoe Kids Week 1":**
- P1: 0%, P2: 10%

**Result:**
- Mike: $100 (participant 1, 0% discount)
- Mary: $90 (participant 2, 10% discount)
- **Total: $190**

### Example 2: Three Siblings, Same Session

**Cart:**
- Mike for Canoe Kids Week 1 ($100)
- Mary for Canoe Kids Week 1 ($100)
- John for Canoe Kids Week 1 ($100)

**Discount Settings:**
- P1: 0%, P2: 10%, P3: 20%

**Result:**
- Mike: $100 (0%)
- Mary: $90 (10%)
- John: $80 (20%)
- **Total: $270**

### Example 3: Mixed Sessions (No Family Discount)

**Cart:**
- Mike for Canoe Kids Week 1 ($100)
- Mary for Canoe Kids Week 2 ($100)

**Result:**
- Mike: $100 (only 1 participant for Week 1)
- Mary: $100 (only 1 participant for Week 2)
- **Total: $200** (no family discount - different sessions)

### Example 4: Six Siblings, Same Session

**Cart:**
- Mike, Mary, John, Sally, Peter, Jane all for Canoe Kids Week 1 ($100 each)

**Discount Settings:**
- P1: 0%, P2: 10%, P3: 20%, P4: 30%, P5+: 100%

**Result:**
- Participant 1: $100 (0%)
- Participant 2: $90 (10%)
- Participant 3: $80 (20%)
- Participant 4: $70 (30%)
- Participant 5: $0 (100% - free!)
- Participant 6: $0 (100% - free!)
- **Total: $340** (instead of $600)

### Example 5: With Early Bird Discount

**Cart:**
- Mike for Canoe Kids Week 1 ($100)
- Mary for Canoe Kids Week 1 ($100)

**Promotions:**
1. Early Bird: 15% off (runs first)
2. Family Discount: P2 gets 10% (runs second)

**Calculation:**
- Mike: $100 → $85 (early bird) → $85 (participant 1, no family discount)
- Mary: $100 → $85 (early bird) → $76.50 (participant 2, 10% off $85)
- **Total: $161.50**

The family discount is calculated on the already-discounted $85, not the original $100.

## Troubleshooting

### Discount Not Applying

**Check:**
1. Variant has the five discount fields
2. At least one discount field has a value > 0
3. Multiple order items exist for the same variation
4. Promotion is enabled
5. Cache is cleared (`drush cr`)

### Wrong Discount Amount

**Check:**
1. Promotion weight (should be 10 or higher to run after other promotions)
2. Discount field values are correct (25 = 25%, not 0.25)
3. Other promotions aren't interfering

### Fields Missing

If the module doesn't find the discount fields, it will:
- Log a warning (if logging is enabled)
- Return 0 for that discount level
- Continue processing other variants

Add the required fields to fix this.

## Technical Details

### Module Structure

```
picc_discount/
├── picc_discount.info.yml
├── README.md
└── src/
    └── Plugin/
        └── Commerce/
            └── PromotionOffer/
                └── FamilyDiscount.php
```

### Promotion Offer Plugin

- **Plugin ID**: `picc_family_discount`
- **Entity Type**: `commerce_order_item`
- **Label**: "PICC Family Discount (Graduated)"

### Adjustment Label

Discounts appear on checkout/invoices as: **"Family Discount"**

You can change this by editing `FamilyDiscount.php` line 108.

## Support

For issues or questions:
- Check this README first
- Verify variant fields exist and are configured correctly
- Clear Drupal cache: `drush cr`
- Check Drupal logs: **Reports → Recent log messages**

## License

GPL-2.0-or-later

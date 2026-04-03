# PICC Registration Module

Custom Drupal module for Petrie Island Canoe Club program registration.

## What It Does

Processes webform submissions and:
- Creates Participant profiles with emergency contacts and medical info
- Validates participant age against program requirements
- Creates Commerce orders and order items
- Links participants to orders
- Redirects to checkout

## Installation

1. Extract to `web/modules/custom/picc_registration`
2. Enable: `drush en picc_registration -y`
3. Clear cache: `drush cr`
4. Attach handler to webform:
   - Go to: Structure → Webforms → Program Registration → Settings → Handlers
   - Click: + Add handler
   - Select: PICC Registration Handler
   - Save

## Field Mappings

### Webform → Profile

- `participant_first_name` + `participant_last_name` → `field_name` (Name module)
- `participant_birth_date` → `field_birth_date`
- `emergency_1_name` → `field_emercency_contact_1_name`
- `emergency_1_phone` → `field_emercency_contact_1_phone`
- `emergency_1_relationship` → `field_emercency_contact_1_rel`
- `emergency_2_*` → `field_emercency_contact_2_*`
- `emergency_3_*` → `field_emercency_contact_3_*`
- `allergies` → `field_allergies`
- `medical_notes` → `field_medical_notes`
- `photo_consent` → `field_photo_consent`
- `swim_test_acknowledgment` → `field_swim_test_req`
- `who_participating` (myself) → `field_account_holder`

### Profile → Order

- Profile saved with `field_owner` = current user
- Order item created with `field_participant` = profile ID
- Order created with user as owner

## Features

✅ Age validation against product variation requirements
✅ Product/variation validation
✅ Automatic checkout redirect
✅ Comprehensive error handling and logging
✅ Support for optional emergency contacts
✅ Support for optional medical information

## Logging

All registration attempts logged to watchdog:
- Success: "Successfully created profile X and order Y"
- Failure: "Registration failed: [error message]"

View logs: `drush watchdog:show --type=picc_registration`

## Drush Commands

### Clean Up Orphaned Order Items

When an order is deleted, its order items (line items) can be left behind in the database. These orphans no longer belong to any order and can clutter views and reports.

The `picc:cleanup-orphans` command finds order items whose parent order no longer exists and deletes them using the entity API, so all related field data and revisions are properly cleaned up. Product variations are not affected.

Preview orphans without deleting:

```
drush picc:cleanup-orphans --dry-run
```

Delete orphans (prompts for confirmation):

```
drush picc:cleanup-orphans
```

## Troubleshooting

### Module won't enable
- Check dependencies are installed (webform, profile, commerce, name)
- Check .info.yml syntax

### Handler doesn't appear
- Clear cache: `drush cr`
- Check file location: `src/Plugin/WebformHandler/PiccRegistrationHandler.php`

### Registration fails silently
- Check logs: Reports → Recent log messages (type: picc_registration)
- Common issues:
  - Field names don't match
  - No store configured
  - Age validation failed

### Age validation too strict/loose
- Edit product variation
- Adjust field_age_min, field_age_max, field_age_calc_date

## Support

For issues, check:
1. Watchdog logs
2. Field machine names match config
3. Product variations have age fields set
4. Store exists in Commerce

## Version

1.0.0 - Initial release
Built for PICC by volunteer dev team

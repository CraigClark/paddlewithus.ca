# Creating swim test sessions

Swim test sessions are created as **variations** on the existing "Swim test" product at `/en/product/23`.

## Steps

1. Go to `/en/product/23/variations`.
2. Click **Add variation** and choose **Swim test slot**.
3. Fill in:
   - **Title**: short description (e.g. "Ray Friel Pool — Group 1"). This appears on the coach roster and receipts.
   - **Date**: the single calendar day of the test.
   - **Date and time** (the time range widget): start and end time for this slot.
   - **Capacity**: number of spots. Set after first save if the field isn't visible.
   - **Short description** (optional): shown in the variant listing.
4. Save.
5. If you didn't set capacity on the first save, click **Edit** on the new variation and set it now.

## Hidden from the form

- **Price**: always $0 CAD for swim test slots. The system enforces this automatically — admins cannot change it.
- **SKU**: auto-generated.

## Sorting sessions on the product page

The variant_session view is sorted by date then time. Manual drag-and-drop ordering on the variations page is not currently honored — rearranging will have no effect on the public listing.

## Out of stock

When capacity reaches zero, the variant is automatically hidden from the public page. Parents can't register for full sessions.

## Cancelling a session

Disable the variation by toggling **Published** off, or delete it. Existing registrations for deleted variants will become orphaned — prefer disabling over deleting.

## Translation

After creating a variant, click **Translate** to add a French version. Only the title and description are translatable — dates, times, and capacity are shared across languages.

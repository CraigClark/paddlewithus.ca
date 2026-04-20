# Creating activity products and sessions

Activities are **products**; each offering of an activity is a **variation** (a.k.a. "session"). Commerce managers create both.

## Creating an activity product

1. Go to `/en/product/add/activity`.
2. Fill in:
   - **Title**: the activity name (e.g. "Canoe Kids").
   - **Description** (body): marketing description. Keep it simple — by the time people reach this page, they're already interested.
     - Use the summary field for shortened text that appears in activity listings and search results.
   - **Primary image**: shown as the hero on the activity page.
   - **Activity type** (taxonomy): camp, lesson, training, etc.
   - **Who is this for**: audience (youth/adult/all ages), age range, experience level.
   - **Coach language**: primarily French, primarily English, or bilingual.
   - **Contact email**: who fields questions about this activity.
   - **Location**: e.g. "Petrie Island".
   - **Schedule**: free-form text describing the activity schedule.
   - **Equipment provided**: what the club provides.
   - **What to bring**: what participants need.
   - **Season**: taxonomy reference.
3. Save. The product page is now live, but with no sessions yet.

## Creating session variations

1. Go to the product's Variations tab (e.g. `/en/product/{id}/variations`).
2. Click **Add variation** and choose **Activity**.
3. Fill in:
   - **Title**: typically the session description (e.g. "Week 1", "Spring session").
   - **Price**: the amount per participant. Set to $0 for free activities.
   - **Date range**: the full date span of the session (daterange, required).
   - **Short description** (`field_desc`): appears in the sessions listing.
   - **Capacity** (stock level): number of spots. Save the variation first if the field isn't visible — then edit to set capacity.
   - **Age requirements**:
     - Minimum age, maximum age.
     - Age calc date (optional): if set, age is calculated relative to this date instead of today.
   - **Family discounts** (optional):
     - Discount % for 1st, 2nd, 3rd, 4th, 5+ participants.
     - Percentages are integers 0–100.
     - Leave at 0 if no family discount.
4. Save.

## Multiple variations

A product can have multiple variations (sessions). Parents pick one when registering. Variations with a date range in the past are automatically hidden from the public listing. Variations at capacity are also hidden.

## Editing a running activity

Be cautious editing a variation once parents have registered:
- **Changing dates**: affects everyone already registered. Communicate out of band.
- **Changing price**: applies to future registrations, not existing orders.
- **Changing capacity**: adjusts the stock level. Be careful not to reduce below current registrations.
- **Changing age requirements**: doesn't affect existing registrations, only future ones.

## Translation

Click the Translate tab on a product or variation to add French. Only translatable fields (title, description, body) can be translated. Prices, dates, and capacity are shared across languages.

## SKU

Automatically generated — format `ACT-` + 8 hex chars. Hidden from the form. You don't need to do anything.

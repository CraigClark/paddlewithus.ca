# Gift cards

People can buy gift cards on the site and give them to someone. The card can pay for any program. It doesn't expire and can't be cashed in.

## How it works

1. A buyer goes to the Gift cards page, logs in, picks an amount and fills in To, From and a message (all optional).
2. They pay by credit card at checkout.
3. They get an email with each card's code and a PDF of the card to print, cut out and put in a greeting card.
4. The person who gets the card registers for a program and enters the code in the gift card box at checkout. Whatever's left on the card stays there for next time.
5. Anyone who has used a card sees its balance on the **Gift cards** tab of their account, and in the gift card box at checkout. Buyers see the cards they bought on the same tab, with a link to print them.

You get the usual club notification email for every gift card sale. Glance at each one. Gift cards are a common target for people using stolen credit cards.

## Adding or changing amounts

Each amount is a variation of the Gift cards product.

1. Go to **Commerce > Products**, find **Gift cards** and select **Variations**.
2. To add an amount, select **Add variation**:
   - **Title**: for example "$100 gift card". This is what buyers see in the Amount list.
   - **Price**: what the buyer pays, for example 100.
   - **Gift card amount**: the value of the card. Always fill this in. It can be higher than the price for a sale (for example price 225, amount 250). When they differ, the page shows "You pay $225.00" under the amount.
   - **Gift card type**: Standard. Only types that can be sold are listed.
   - **Number available**: how many to sell. Enter the number to add, not the total.
3. To stop selling an amount, uncheck **Published** on that variation. Cards already sold keep working.
4. To pause all gift card sales, unpublish the Gift cards product.

When an amount sells out, its button changes to "Out of stock". Add more with **Number available**.

## Looking up a card

Go to **Commerce > Gift cards**. You can search by code, owner or balance. Codes are stored without dashes, so search for `ABCDEFGHJKMN`, not `ABCD-EFGH-JKMN`.

Commerce managers can create, edit, print and send cards, but not delete them. To take a card out of use, edit it and turn off **Enabled**. Only site admins can delete a card.

Each card's menu has:

- **Edit**: change To, From or the message, or disable the card.
- **Print**: the printable card. **Download PDF** is at the top.
- **Send email**: sends the card and its PDF to any address. Use it when someone lost the email.
- **Transactions**: every change to the balance.

## Changing a balance

Don't type a new number in the card's **Balance** field. That skips the transaction log.

Use **Add transaction** instead:

- A program paid with a gift card was cancelled: add the amount back as a positive number. **Put the order number in the comment.** The site doesn't link it for you.
- A correction: add or subtract the difference, with a comment saying why.

Gift cards aren't refunded in cash. If a program paid with a gift card is cancelled, the money goes back on the card.

## Free (Complimentary) cards

For raffle prizes and thank-yous, use the **Complimentary** type. Complimentary cards can't be sold on the site. The Type list on gift card amounts doesn't offer them, and the site refuses to save an amount that uses them. They work at checkout like any other card.

1. Go to **Commerce > Gift cards** and open the **Generate** page (`/admin/commerce/giftcards/generate`).
2. Choose **Complimentary**, the **Initial balance** and **Number of gift cards** (1 for a single card).
3. Choose a **Reason** (raffle prize, volunteer thank-you, other) and add a **Note** if it helps, for example the event or who it thanks. The reason is required and goes into the bookkeeper report.
4. Find the new card in the list, select **Edit** and fill in To and From.
5. Use **Send email** or **Print** from the card's menu.

You can also use **Add gift card > Complimentary** and type your own code. Use letters and digits, at least 12 characters. The site stores codes in capitals and treats 0 as O and 1 as I, so avoid 0 and 1.

Each gift card type has a **Can be sold on the site** setting (Commerce > Configuration > Gift card types). Standard has it on, Complimentary off. A new type starts with it off.

## Changing the card's design

Go to **Commerce > Configuration > Gift cards > Gift card design**.

- **Card graphic**: the image across the top of the card (the holly for the holidays). Upload a PNG or JPG at least 1200 pixels wide, or select **Remove** to print the card with the logo only.
- **Contact email on the card**.

Changes apply to every card emailed or printed after you save.

## Don't delete the gift card type

The site won't let you delete the **Standard** gift card type while cards or products use it. Deleting it would break checkout.

## French names

The French page title, body and amount names are content. Translate the Gift cards product and each amount (variation) with the **Translate** tab. The French URL alias on ddev is `/cartes-cadeaux`.

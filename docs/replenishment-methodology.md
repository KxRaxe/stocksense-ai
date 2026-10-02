# Replenishment: how the advice is worked out

StockSense AI tells you **when to reorder each product and how much**. It is advice only: it never orders anything, and every decision is made, and recorded, by a person.

This page explains the rules so the numbers can be checked by hand, and says plainly where they stop being reliable. The code is `web/app/Services/Replenishment/`; `ReplenishmentCalculator` has no database and no clock, so every figure below can be reproduced from its inputs.

## Inputs

For each active product, at the default location:

| Input | Where it comes from |
|---|---|
| Expected demand, day by day | The latest completed forecast (weekly, else monthly). A weekly figure is spread evenly over its 7 days; a monthly one over the days of that month. Days before the first forecast period take that period's rate, and days after the last (a lead time longer than the horizon) take the average over the whole forecast. Only the **median** (P50) is used for demand. |
| Typical forecast error | The forecast run's `residual_std` for the product (how far past forecasts missed, in units per period). If a run has none for a product, it is taken from the width of the forecast's own 10-90% range (that range spans about 2.56 standard deviations). |
| Lead time `LT` | `products.lead_time_days` |
| Review period `R` | The review period setting (System settings, default 7): the gap between orders you would normally place |
| Service level | The product's category target, e.g. 95% |
| On hand, on order | `inventory_levels`. **On order** is the total of accepted recommendations not yet received. |
| Pack size, minimum order, overrides | The product's own fields |

## The rules

```
D(n)  expected demand over the next n days
z     standard deviations for the service level (95% -> 1.645)
σ     forecast error over LT + R days = error per period × √((LT + R) ÷ days per period)

safety stock   SS  = hand-set value, or  z × σ
reorder point  ROP = hand-set value, or  D(LT) + SS
order up to    S   = D(LT + R) + SS
position       IP  = on hand + on order
```

- If **IP ≤ ROP** an order is due **now**, for `S − IP`.
- Otherwise it falls due on the day IP is expected to **reach** ROP (the forecast is walked forward day by day, up to 180 days), for `S − ROP`.
- The quantity is raised to the **minimum order** and rounded **up to a whole pack**.
- A hand-set reorder point with no hand-set safety stock implies the safety stock behind it (what it holds above the lead-time demand), so an order always brings stock up above that point.
- With no demand expected there is nothing to protect against, so safety stock is zero and nothing is recommended, unless a hand-set reorder point says otherwise.

### Worked example

Nails: forecast 70 a week, lead time 7 days, review period 7 days, 10 on hand, none on order, forecast error 0 (to keep it simple).

- `D(7) = 70`, `D(14) = 140`, `SS = 0`
- `ROP = 70 + 0 = 70`, `S = 140 + 0 = 140`
- `IP = 10 ≤ 70`, so order now: `140 − 10 = 130` units.
- `IP = 10 < D(LT) = 70`, so it is **Critical**: it is expected to run out before an order could arrive.

## Risk levels

| Level | Meaning |
|---|---|
| **Critical** | `IP < D(LT)`: stock is expected to run out before a new order could arrive. Always due, whatever reorder point was set. |
| **Low** | `IP ≤ ROP`: at or below the reorder point. Order now. |
| **Watch** | Will reach the reorder point in **fewer than R days** (before the next review). Order soon. |
| **Overstock** | More than the overstock threshold (System settings, default 90) days of average demand on hand. Information only; no quantity. |
| **OK** | Everything else. Not listed. |

Days of cover is `IP ÷ average daily demand` over the forecast horizon.

## What happens to a recommendation

- A new recommendation is **Pending**. A person can **Accept** it, **Change the quantity** (accepted as *Adjusted*), or **Dismiss** it.
- An accepted or adjusted order can later be **Cancelled**.
- When the goods arrive, recording a **restock** takes that amount off *on order*.

- There is **one open recommendation per product**. A refresh updates it in place; when the product no longer needs ordering, it is closed.
- **Accepting** records "I will order this". The quantity is added to *on order*, so the product is not recommended again while it is on its way. Recording a **restock** (goods received) reduces *on order* by the amount received, never below zero; **cancelling** the order removes it.
- **Dismissing** leaves the product out of the recommendations for the snooze period (System settings, default 7 days) and then looks again.
- Each decision stores who made it, when, and a note, and goes to the audit log. Only the Owner and Manager can decide; inventory staff can look.
- On order is a **counter** on the stock level, not a stock movement: ordering something is not stock, and the ledger only records what physically moved.
- Recommendations are refreshed every morning (06:00 Asia/Manila), after every forecast run, and on demand (**Recalculate**), because stock changes daily even when the forecast does not. A forecast older than 14 days is flagged on the page.

## Limits

These rules are standard inventory theory (a periodic-review order-up-to policy), and share its assumptions.

- **Lead time is taken as fixed.** If a supplier is sometimes late, raise the product's lead time or set safety stock by hand.
- **Forecast error is taken as roughly bell-shaped** and the same from one period to the next. Real demand for slow, intermittent products is lumpy, so safety stock for those is a rough guide.
- **Products are treated independently.** Substitutes, bundles and shared suppliers are not considered.
- **One location.** The data model is ready for branches; the advice is not yet per branch ([future-multi-branch.md](future-multi-branch.md)).
- **No prices, budgets or supplier terms.** Quantities ignore cash, price breaks and delivery days. Pack size and minimum order are respected.
- **Young products** (under a year of sales) are forecast by a recent average and marked low confidence; their advice should be read as a rough guide.
- It trusts the stock figures. Wrong counts give wrong advice; **Count stock** on the product page fixes them.

## Notifications

Alerts and summaries are sent in the app and by email, according to each person's settings (**Settings → Notifications**).

| Notification | Who | When |
|---|---|---|
| Critical stock | Owner, Manager | While products are critical: at most once a day for each product. |
| Replenishment digest | Owner, Manager | Daily or Mondays, as each chose (default weekly, or never). Counts by level and the most urgent items. |
| Forecast ready / failed | Whoever started it, and the Owner | When a run finishes. |
| Import with problems | Whoever uploaded the file | When some rows could not be imported, or the import stopped. |

**Emails contain no personal data**: a generic greeting, product and stock figures, and a link back to the app. No names, no email addresses, no uploaded file names. This is enforced by the base notification class and by `EmailPrivacyTest`, which renders every email for a user with a distinctive name and address and searches the real output for them. Add every new email to that test's dataset.

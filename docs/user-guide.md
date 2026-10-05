# User guide

StockSense AI helps a shop decide **what to reorder, how much, and when**. It looks at what you have sold, forecasts what you are likely to sell, and compares that with what is on the shelf. It **advises**: it never orders anything for you. Every decision is yours, and is recorded.

## Signing in

You are added by the shop's Owner, who sends you an email with a link. Open it, **choose your own password**, then sign in. Nobody is ever sent a password.

- Passwords need at least 12 characters, with upper and lower case, a number and a symbol.
- Five wrong tries in a minute pause sign-in for a minute.
- Under **Settings** (your name, bottom left) you can change your password, turn on **two-factor authentication** (recommended for the Owner), choose light or dark appearance, and choose which **notifications** you get.

### Roles

| | Owner | Manager | Inventory staff |
|---|:-:|:-:|:-:|
| See the dashboard | yes | yes | yes (without forecasts) |
| Products, categories | change | change | look |
| Record deliveries and stock counts | yes | yes | yes |
| Enter and import sales | yes | yes | yes |
| Run forecasts | yes | yes | |
| See recommendations | yes | yes | yes |
| **Decide** on recommendations | yes | yes | |
| Reports | all four | all four | stock report only |
| Users, system settings, audit log | yes | | |

## The dashboard

The first page. It shows how the shop is doing over the **last 30 days** compared with the 30 before: sales, the value of the stock at cost, how many products **need ordering**, and how far off the forecast has typically been. Below are weekly sales, what is expected next with its likely range, which categories earn the most, the most urgent products, and the best and slowest sellers (the slow ones are money sitting on the shelf).

You see only what your role may see.

## Products, categories and stock

- **Products** holds each product: SKU, name, category, unit, cost, price, **lead time** (days for an order to arrive), minimum order and pack size (orders are rounded up to whole packs). You can set a **reorder point** or **safety stock** by hand; otherwise they are worked out.
- **Categories** group products and carry a **service level**: how often you want to have stock when someone wants it (95% is a common starting point). Higher means more safety stock.
- **Inventory** shows what is on hand and on order for every product, with low-stock flags. Open a product to **record a delivery** (goods received) or **count stock** (set it to what is physically on the shelf; the difference is recorded with your reason).
- Owners and Managers can bring in a whole product list from a spreadsheet (**Products → Import**).

## Sales

**Sales** is what the forecasts learn from.

- **Enter sales** by hand for a day, one line per product. Each sale also takes the stock off.
- **Import a file** (CSV or Excel) of past sales. You first see a **preview**: what will be imported, what will be skipped as a repeat, and what is wrong, with the reason for each problem. Nothing is saved until you press **Start import**. Afterwards you can download a list of the rows that failed, or **undo** the whole import.
- Importing history can leave stock alone, so old sales do not change today's stock.

A year or more of sales makes forecasts far better. Products with less than a year of history are forecast with a plain recent average and marked **low confidence**.

## Forecasts

**Forecasts** shows what each product is expected to sell, week by week (8 weeks ahead) or month by month (3 months ahead), with a range: actual sales should fall inside it about 8 times in 10.

- **Run weekly forecast** starts a run. It takes a minute or two and the page updates itself; you can leave it. They also run by themselves on a schedule.
- Open a product to see its recent sales followed by the forecast.
- **Accuracy** shows how far off the forecasts have been when replayed on recent history, against two simple guesses a forecast must beat ("the same week last year" and "a recent average"). The headline number is **WAPE**: total error as a percentage of units sold. Lower is better.

## Recommendations

**Recommendations** says what to reorder. Each card shows:

- how much to order and by when (**Order now** or a date);
- what is on hand and on order, the demand expected while an order arrives, the safety stock and the **reorder point** (the stock level at which to order);
- a sentence explaining it in plain words.

The **risk level** tells you how urgent it is:

| Level | Meaning |
|---|---|
| **Critical** | Stock will probably run out before a new order could arrive |
| **Low** | At or below the reorder point: order now |
| **Watch** | Will reach the reorder point within a week |
| **Overstock** | More than about three months of sales on hand; for information |

Owners and Managers can **Accept** (you will order this; it now counts as *on order* so the product is not recommended again), **Change quantity** (accept, but a different amount), or **Dismiss** (leave it for a week). Add a note if you like. An accepted order can be **cancelled** if it will not happen. When the goods arrive, **record the delivery** on the product and the on-order amount clears.

The page says how old the forecast behind the advice is, and warns if it is getting old. **Recalculate** refreshes the advice after you have changed stock; it is also refreshed every morning.

## Reports

**Reports** has four, each on screen with filters and as an **Excel** file or a **PDF**:

- **Sales**: units, revenue and share for each product over a period.
- **Inventory status**: what is on hand and on order and what it is worth, for every product, as of now.
- **Forecast accuracy**: how far off each forecast run was.
- **Replenishment history**: every recommendation raised in a period and what was decided, by whom.

The Excel file keeps real numbers and dates so you can sort and add them up, and has an **About** sheet with what it covers and how to read it.

## Notifications

The bell at the top shows what is new. You can also get them by email. Under **Settings → Notifications** choose, for each kind, whether it appears in the app, by email, or both:

- **Critical stock alerts** (Owner and Manager): products that may run out. At most once a day for each product.
- **Replenishment digest** (Owner and Manager): a summary of what needs ordering, daily, every Monday, or never.
- **Forecast runs**: when a forecast you started, or any forecast if you are the Owner, finishes or fails.
- **Imports with problems**: when a file you imported had rows that could not be imported.

**Emails never contain your name or email address**, or anything personal: only product and stock figures and a link back.

## For the Owner

- **Users**: add people, change their role, deactivate them (they are signed out at once; their history stays), and send a new set-up link. There is always at least one active Owner.
- **System settings**: how stock advice is worked out and when things run: the default service level for new categories, the review period (how often you order), the overstock threshold, how long dismissed advice stays hidden, how far ahead forecasts look, and when forecasts, advice and the digest are refreshed or sent. **Restore defaults** puts everything back.
- **Audit log**: who did what and when, newest first, with what changed from and to. Filter by area, person, period or a word. It can only be read, never edited.

## Words used

| Word | Meaning |
|---|---|
| **Lead time** | Days from placing an order to receiving it |
| **Review period** | How often you place orders (default 7 days) |
| **Service level** | How often you want to have stock when asked |
| **Safety stock** | Extra stock to cover surprises while an order arrives |
| **Reorder point** | The stock level at which to order |
| **On order** | Quantity you accepted that has not arrived yet |
| **Days of cover** | How many days the stock on hand would last at the average rate of sales |
| **WAPE** | Forecast error as a percentage of units sold |

The reasoning behind the numbers is in [replenishment-methodology.md](replenishment-methodology.md) and [ml-methodology.md](ml-methodology.md).

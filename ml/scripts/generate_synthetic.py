"""Synthetic sales and stock history for StockSense AI demos and tests.

Builds 24 months (October 2024 to September 2026) of daily sales for 50
products in the five categories from the proposal, then simulates how a shop
would have restocked them, so stock levels have a realistic history with the
occasional stockout. Everything is driven by a fixed seed: the same seed always
gives byte-identical files.

    python scripts/generate_synthetic.py --out ../web/database/data

Writes four files the Laravel demo seeder loads, plus a small sample file with
deliberate mistakes for trying out the import error report:

    categories.csv   name, description, service_level
    products.csv     sku, name, category, unit, unit_cost, unit_price,
                     lead_time_days, moq, pack_size, reorder_point,
                     opening_stock, start_date
    restocks.csv     date, sku, quantity           (deliveries after opening stock)
    sales.csv        date, sku, quantity, unit_price
    sales-sample-with-errors.csv

The demand patterns are chosen to differ by category, as the proposal asks, so
forecasting models can be judged under varied conditions: fast and slow
movers, paydays, December, back-to-school, dry and rainy season, intermittent
demand, and products with under twelve months of history.
"""

from __future__ import annotations

import argparse
import math
from collections.abc import Callable
from dataclasses import dataclass
from datetime import date, timedelta
from pathlib import Path

import numpy as np
import pandas as pd

SEED = 42
START = date(2024, 10, 1)
END = date(2026, 9, 30)

# Shops are closed on these days of the year (month, day).
CLOSED_DAYS = {(12, 25), (1, 1)}


@dataclass(frozen=True)
class ProductSpec:
    sku: str
    name: str
    category: str
    unit: str
    cost: float
    price: float
    base_daily: float  # average units sold per day in a normal period
    lead_days: int
    pack: int = 1
    moq: int = 1
    start: date = START  # first day the product was sold (later for new products)


# category -> (service level %, description)
CATEGORIES: dict[str, tuple[int, str]] = {
    "Food and beverages": (95, "Groceries and drinks. Fast-moving, with spikes on paydays and in December."),
    "Personal care": (95, "Toiletries and hygiene products. Steady demand."),
    "Household and cleaning": (90, "Cleaning and household supplies. Slightly higher in the rainy season."),
    "School and office supplies": (90, "Stationery. Peaks before the school year starts in June."),
    "Hardware and construction": (85, "Tools and building materials. Slow and intermittent; busier in the dry season."),
}

D = date
PRODUCTS: list[ProductSpec] = [
    # Food and beverages
    ProductSpec("FB-001", "Instant coffee 3-in-1 (30 sachets)", "Food and beverages", "box", 95.00, 125.00, 14, 5, 6),
    ProductSpec("FB-002", "Bottled water 500 ml", "Food and beverages", "pc", 8.00, 14.00, 55, 3, 24),
    ProductSpec("FB-003", "Canned sardines 155 g", "Food and beverages", "can", 17.50, 24.00, 22, 7, 24),
    ProductSpec("FB-004", "White sugar 1 kg", "Food and beverages", "pack", 62.00, 78.00, 9, 5, 10),
    ProductSpec("FB-005", "Cooking oil 1 L", "Food and beverages", "bottle", 88.00, 115.00, 11, 7, 12),
    ProductSpec("FB-006", "Instant noodles (pack of 6)", "Food and beverages", "pack", 48.00, 66.00, 26, 4, 12),
    ProductSpec("FB-007", "Rice 5 kg", "Food and beverages", "bag", 235.00, 275.00, 7, 6, 5),
    ProductSpec("FB-008", "Soy sauce 385 ml", "Food and beverages", "bottle", 24.00, 34.00, 9, 7, 12),
    ProductSpec("FB-009", "Soft drink 1.5 L", "Food and beverages", "bottle", 52.00, 68.00, 18, 4, 12),
    ProductSpec("FB-010", "Powdered milk 300 g", "Food and beverages", "pack", 118.00, 155.00, 6, 10, 6, start=D(2025, 11, 3)),
    # Personal care
    ProductSpec("PC-001", "Shampoo sachet (12 pcs)", "Personal care", "pack", 36.00, 52.00, 12, 7, 12),
    ProductSpec("PC-002", "Bath soap 90 g", "Personal care", "pc", 22.00, 32.00, 9, 7, 12),
    ProductSpec("PC-003", "Toothpaste 100 ml", "Personal care", "pc", 48.00, 68.00, 5, 10, 12),
    ProductSpec("PC-004", "Toothbrush", "Personal care", "pc", 24.00, 38.00, 4, 10, 12),
    ProductSpec("PC-005", "Deodorant roll-on 50 ml", "Personal care", "pc", 68.00, 95.00, 3, 10, 6),
    ProductSpec("PC-006", "Facial wash 100 ml", "Personal care", "pc", 92.00, 135.00, 2.2, 14, 6),
    ProductSpec("PC-007", "Cotton buds (200 pcs)", "Personal care", "pack", 26.00, 38.00, 3.5, 10, 12),
    ProductSpec("PC-008", "Hand sanitizer 250 ml", "Personal care", "bottle", 55.00, 79.00, 3, 10, 6),
    ProductSpec("PC-009", "Sanitary napkins (8 pcs)", "Personal care", "pack", 34.00, 49.00, 6, 7, 12),
    ProductSpec("PC-010", "Baby wipes (80 sheets)", "Personal care", "pack", 85.00, 120.00, 1.6, 14, 6, start=D(2026, 1, 12)),
    # Household and cleaning
    ProductSpec("HC-001", "Dishwashing liquid 500 ml", "Household and cleaning", "bottle", 38.00, 55.00, 9, 7, 12),
    ProductSpec("HC-002", "Laundry powder 1 kg", "Household and cleaning", "pack", 85.00, 115.00, 7, 10, 10),
    ProductSpec("HC-003", "Trash bags (medium, 10 pcs)", "Household and cleaning", "pack", 28.00, 42.00, 4, 14, 20),
    ProductSpec("HC-004", "Bleach 1 L", "Household and cleaning", "bottle", 42.00, 62.00, 5, 10, 12),
    ProductSpec("HC-005", "Fabric conditioner 800 ml", "Household and cleaning", "bottle", 72.00, 98.00, 4, 10, 12),
    ProductSpec("HC-006", "Floor cleaner 1 L", "Household and cleaning", "bottle", 58.00, 82.00, 2.5, 12, 6),
    ProductSpec("HC-007", "Sponge scourer (3 pcs)", "Household and cleaning", "pack", 18.00, 28.00, 6, 10, 24),
    ProductSpec("HC-008", "Tissue roll (4 rolls)", "Household and cleaning", "pack", 44.00, 62.00, 8, 7, 12),
    ProductSpec("HC-009", "Insect spray 300 ml", "Household and cleaning", "can", 96.00, 135.00, 1.8, 14, 6),
    ProductSpec("HC-010", "Laundry bar soap", "Household and cleaning", "pc", 14.00, 22.00, 10, 7, 24, start=D(2026, 2, 9)),
    # School and office supplies
    ProductSpec("SO-001", "Notebook 80 leaves", "School and office supplies", "pc", 18.00, 30.00, 14, 14, 20),
    ProductSpec("SO-002", "Ballpoint pen (box of 12)", "School and office supplies", "box", 60.00, 90.00, 4, 14, 10),
    ProductSpec("SO-003", "Bond paper A4 (ream)", "School and office supplies", "ream", 215.00, 260.00, 3, 10, 5),
    ProductSpec("SO-004", "Pencil #2 (box of 12)", "School and office supplies", "box", 48.00, 72.00, 3.5, 14, 10),
    ProductSpec("SO-005", "Crayons 24 colors", "School and office supplies", "box", 54.00, 85.00, 2.5, 14, 6),
    ProductSpec("SO-006", "Pad paper (10 pads)", "School and office supplies", "pack", 66.00, 95.00, 2.2, 14, 10),
    ProductSpec("SO-007", "Scissors", "School and office supplies", "pc", 28.00, 45.00, 1.5, 14, 12),
    ProductSpec("SO-008", "School glue 130 g", "School and office supplies", "bottle", 22.00, 35.00, 3, 14, 12),
    ProductSpec("SO-009", "Folder with fastener (10 pcs)", "School and office supplies", "pack", 52.00, 78.00, 1.8, 14, 10),
    ProductSpec("SO-010", "Scientific calculator", "School and office supplies", "pc", 420.00, 595.00, 0.5, 21, 1, start=D(2026, 2, 2)),
    # Hardware and construction
    ProductSpec("HW-001", "Common wire nails 2 in (1 kg)", "Hardware and construction", "kg", 62.00, 85.00, 2.2, 14, 10),
    ProductSpec("HW-002", "Cement 40 kg", "Hardware and construction", "bag", 235.00, 275.00, 4.5, 7, 20),
    ProductSpec("HW-003", "PVC pipe 1/2 in x 3 m", "Hardware and construction", "pc", 70.00, 98.00, 1.2, 14, 10),
    ProductSpec("HW-004", "Paint 4 L (white)", "Hardware and construction", "can", 520.00, 685.00, 0.7, 21, 4),
    ProductSpec("HW-005", "Paint brush 2 in", "Hardware and construction", "pc", 32.00, 48.00, 1.0, 21, 12),
    ProductSpec("HW-006", "Electrical tape", "Hardware and construction", "roll", 18.00, 28.00, 1.4, 14, 12),
    ProductSpec("HW-007", "Hacksaw blade", "Hardware and construction", "pc", 24.00, 38.00, 0.5, 21, 10),
    ProductSpec("HW-008", "Padlock 40 mm", "Hardware and construction", "pc", 85.00, 125.00, 0.4, 21, 6),
    ProductSpec("HW-009", "Plywood 1/4 in (4 x 8 ft)", "Hardware and construction", "sheet", 450.00, 595.00, 0.6, 14, 5, start=D(2025, 12, 1)),
    ProductSpec("HW-010", "Hollow blocks 4 in", "Hardware and construction", "pc", 11.00, 16.00, 9.0, 5, 50),
]

# Price rises: (category, effective date, factor). Sales rows carry the price in force that day.
PRICE_CHANGES = [
    ("Food and beverages", D(2025, 7, 1), 1.05),
    ("Household and cleaning", D(2026, 1, 1), 1.04),
    ("Hardware and construction", D(2025, 7, 1), 1.05),
]

# Weekday factors, Monday first.
WEEKDAY = {
    "Food and beverages": [0.90, 0.85, 0.90, 0.95, 1.10, 1.30, 1.15],
    "Personal care": [0.95, 0.95, 0.95, 1.00, 1.05, 1.10, 1.00],
    "Household and cleaning": [0.90, 0.90, 0.95, 0.95, 1.05, 1.25, 1.20],
    "School and office supplies": [1.15, 1.15, 1.10, 1.10, 1.05, 0.80, 0.65],
    "Hardware and construction": [1.00, 1.00, 1.00, 1.00, 1.05, 1.40, 0.55],
}

# How much busier the days around payday (15th and end of month) are.
PAYDAY_LIFT = {
    "Food and beverages": 0.30,
    "Personal care": 0.15,
    "Household and cleaning": 0.15,
    "School and office supplies": 0.0,
    "Hardware and construction": 0.10,
}

MONTHLY = {
    "Food and beverages": [1.00, 0.95, 0.95, 1.00, 1.00, 0.95, 1.00, 1.00, 1.00, 1.05, 1.10, 1.35],
    "Personal care": [1.00, 1.00, 1.00, 1.05, 1.05, 1.00, 1.00, 1.00, 1.00, 1.00, 1.05, 1.15],
    "Household and cleaning": [1.00, 1.00, 1.00, 1.05, 1.05, 1.10, 1.10, 1.10, 1.05, 1.00, 1.00, 1.10],
    # Hardware: busier in the dry season (Feb to May), quieter in the rains.
    "Hardware and construction": [1.00, 1.30, 1.40, 1.40, 1.30, 0.95, 0.80, 0.80, 0.80, 0.80, 0.95, 0.90],
}


def _school_season(dates: pd.DatetimeIndex) -> np.ndarray:
    """Back-to-school: a peak around 5 June, a smaller bump in January, quiet otherwise."""
    day_of_year = np.asarray(dates.dayofyear, dtype=float)
    month = np.asarray(dates.month)
    june_peak = 2.4 * np.exp(-((day_of_year - 156) ** 2) / (2 * 22**2))
    january = np.where(month == 1, 0.35, 0.0)
    base = np.where(np.isin(month, [10, 11, 12]), 0.7, 0.85)
    return base + june_peak + january


def _seasonal(category: str, dates: pd.DatetimeIndex) -> np.ndarray:
    if category == "School and office supplies":
        return _school_season(dates)

    factors = np.array(MONTHLY[category])
    season = factors[np.asarray(dates.month) - 1]

    if category == "Food and beverages":
        # The Christmas run-up: the second half of December is busier still.
        season = season * np.where((dates.month == 12) & (dates.day >= 15), 1.2, 1.0)

    return np.asarray(season, dtype=float)


def _payday(category: str, dates: pd.DatetimeIndex) -> np.ndarray:
    lift = PAYDAY_LIFT[category]
    day = np.asarray(dates.day)
    days_in_month = np.asarray(dates.days_in_month)
    # The 15th and the last day of the month, and the day either side of each.
    near_mid = np.abs(day - 15) <= 1
    near_end = (days_in_month - day <= 0) | (days_in_month - day == 1) | (day == 1)
    return 1.0 + lift * (near_mid | near_end)


def _daily_demand(
    spec: ProductSpec, dates: pd.DatetimeIndex, rng: np.random.Generator, growth: float
) -> np.ndarray:
    """Units wanted on each day, before stock limits what can be sold."""
    weekday = np.array(WEEKDAY[spec.category])[np.asarray(dates.dayofweek)]
    years = (np.asarray(dates.dayofyear, dtype=float) + 365 * (np.asarray(dates.year) - dates[0].year)) / 365
    trend = 1.0 + growth * (years - years[0])

    # A shared up-or-down swing for each week, so busy and quiet weeks come in runs.
    week_index = (np.arange(len(dates)) // 7).astype(int)
    weekly = rng.lognormal(0.0, 0.12, week_index.max() + 1)[week_index]

    mean = (
        spec.base_daily
        * _seasonal(spec.category, dates)
        * weekday
        * _payday(spec.category, dates)
        * trend
        * weekly
    )

    # Not yet on sale, or the shop is closed.
    open_for_sale = np.asarray(dates >= pd.Timestamp(spec.start))
    closed = np.array([(d.month, d.day) in CLOSED_DAYS for d in dates])
    mean = np.where(open_for_sale & ~closed, mean, 0.0)

    return rng.poisson(mean)


def _price_on(spec: ProductSpec, day: date) -> float:
    price = spec.price
    for category, effective, factor in PRICE_CHANGES:
        if spec.category == category and day >= effective:
            price *= factor
    return round(price, 2)


def _final_price(spec: ProductSpec) -> float:
    return _price_on(spec, END)


@dataclass
class Simulation:
    sales: list[tuple[date, str, int, float]]
    restocks: list[tuple[date, str, int]]
    opening_stock: int
    reorder_point: int
    final_on_hand: int
    stockout_days: int
    open_days: int


def _simulate(
    spec: ProductSpec,
    dates: pd.DatetimeIndex,
    demand: np.ndarray,
    rng: np.random.Generator,
) -> Simulation:
    """Replays the product day by day under an order-up-to reorder policy.

    When stock plus what is already ordered falls to the reorder point s, order
    up to S. Deliveries arrive after the lead time, give or take a few days, so
    now and then a busy spell runs the shelf empty and sales are lost.
    """
    mean = float(demand[demand > 0].mean()) if (demand > 0).any() else spec.base_daily
    mean = max(mean, spec.base_daily * 0.8)

    # Reorder point: demand over the lead time plus a cushion that is deliberately
    # a little thin, so stockouts happen occasionally rather than never.
    cushion = 0.9 * math.sqrt(mean * spec.lead_days) * 1.4
    reorder_point = max(spec.pack, math.ceil(mean * spec.lead_days + cushion))
    order_up_to = reorder_point + max(spec.pack, math.ceil(mean * 10))
    order_up_to = math.ceil(order_up_to / spec.pack) * spec.pack

    on_hand = order_up_to
    opening = on_hand
    on_order: list[tuple[date, int]] = []

    sales: list[tuple[date, str, int, float]] = []
    restocks: list[tuple[date, str, int]] = []
    stockout_days = 0
    open_days = 0

    for i, ts in enumerate(dates):
        day = ts.date()
        if day < spec.start:
            continue

        arrived = [item for item in on_order if item[0] <= day]
        on_order = [item for item in on_order if item[0] > day]
        for _, quantity in arrived:
            on_hand += quantity
            restocks.append((day, spec.sku, quantity))

        wanted = int(demand[i])
        if wanted > 0 or (day.month, day.day) not in CLOSED_DAYS:
            open_days += 1

        sold = min(wanted, on_hand)
        if wanted > on_hand:
            stockout_days += 1
        if sold > 0:
            on_hand -= sold
            sales.append((day, spec.sku, sold, _price_on(spec, day)))

        position = on_hand + sum(quantity for _, quantity in on_order)
        if position <= reorder_point:
            needed = order_up_to - position
            quantity = max(spec.moq, math.ceil(needed / spec.pack) * spec.pack)
            delay = max(1, spec.lead_days + int(rng.integers(-1, 3)))
            on_order.append((day + timedelta(days=delay), quantity))

    return Simulation(sales, restocks, opening, reorder_point, on_hand, stockout_days, open_days)


@dataclass
class Dataset:
    categories: pd.DataFrame
    products: pd.DataFrame
    restocks: pd.DataFrame
    sales: pd.DataFrame
    stockout_days: int
    open_days: int


def generate(seed: int = SEED) -> Dataset:
    """Builds the whole dataset. The same seed always gives the same result."""
    dates = pd.date_range(START, END, freq="D")
    streams = np.random.SeedSequence(seed).spawn(len(PRODUCTS))

    product_rows: list[dict[str, object]] = []
    sales: list[tuple[date, str, int, float]] = []
    restocks: list[tuple[date, str, int]] = []
    stockout_days = 0
    open_days = 0

    for index, (spec, stream) in enumerate(zip(PRODUCTS, streams, strict=True)):
        rng = np.random.default_rng(stream)

        # Each product sells a little more or less than the average of its kind,
        # and grows (or shrinks) at its own rate.
        scaled = ProductSpec(**{**spec.__dict__, "base_daily": spec.base_daily * float(rng.lognormal(0, 0.12))})
        growth = float(rng.uniform(-0.03, 0.12))

        demand = _daily_demand(scaled, dates, rng, growth)
        result = _simulate(scaled, dates, demand, rng)

        sales.extend(result.sales)
        restocks.extend(result.restocks)
        stockout_days += result.stockout_days
        open_days += result.open_days

        product_rows.append(
            {
                "sku": spec.sku,
                "name": spec.name,
                "category": spec.category,
                "unit": spec.unit,
                "unit_cost": f"{spec.cost:.2f}",
                "unit_price": f"{_final_price(spec):.2f}",
                "lead_time_days": spec.lead_days,
                "moq": spec.moq,
                "pack_size": spec.pack,
                # Half the products have a reorder point set; the rest rely on
                # the system to calculate one, as a new user's catalog would.
                "reorder_point": result.reorder_point if index % 2 == 0 else "",
                "opening_stock": result.opening_stock,
                "start_date": spec.start.isoformat(),
            }
        )

    categories = pd.DataFrame(
        [
            {"name": name, "description": description, "service_level": f"{level:.2f}"}
            for name, (level, description) in CATEGORIES.items()
        ]
    )

    sales_frame = pd.DataFrame(sales, columns=["date", "sku", "quantity", "unit_price"])
    sales_frame = sales_frame.sort_values(["date", "sku"], kind="stable").reset_index(drop=True)
    sales_frame["date"] = sales_frame["date"].map(lambda day: day.isoformat())
    sales_frame["unit_price"] = sales_frame["unit_price"].map(lambda value: f"{value:.2f}")

    restock_frame = pd.DataFrame(restocks, columns=["date", "sku", "quantity"])
    restock_frame = restock_frame.sort_values(["date", "sku"], kind="stable").reset_index(drop=True)
    restock_frame["date"] = restock_frame["date"].map(lambda day: day.isoformat())

    return Dataset(
        categories=categories,
        products=pd.DataFrame(product_rows),
        restocks=restock_frame,
        sales=sales_frame,
        stockout_days=stockout_days,
        open_days=open_days,
    )


def sample_with_errors(dataset: Dataset) -> pd.DataFrame:
    """Forty recent sales, a handful deliberately wrong, for trying the error report."""
    recent = dataset.sales.tail(40).reset_index(drop=True).copy()
    recent["quantity"] = recent["quantity"].astype(object)

    recent.loc[3, "sku"] = "XX-404"          # a product that does not exist
    recent.loc[8, "date"] = "2026-13-45"     # not a real date
    recent.loc[14, "quantity"] = "many"      # not a number
    recent.loc[21, "quantity"] = "2.5"       # not a whole number
    recent.loc[27, "date"] = "2999-01-01"    # in the future
    recent.loc[33, "unit_price"] = "cheap"   # not a price

    return recent


def write(dataset: Dataset, out_dir: Path) -> None:
    out_dir.mkdir(parents=True, exist_ok=True)

    files = {
        "categories.csv": dataset.categories,
        "products.csv": dataset.products,
        "restocks.csv": dataset.restocks,
        "sales.csv": dataset.sales,
        "sales-sample-with-errors.csv": sample_with_errors(dataset),
    }

    for name, frame in files.items():
        # Plain LF line endings so the files are identical on every machine.
        frame.to_csv(out_dir / name, index=False, lineterminator="\n")


def main(argv: list[str] | None = None, echo: Callable[[str], None] = print) -> None:
    parser = argparse.ArgumentParser(description=__doc__.split("\n")[0])
    parser.add_argument("--out", type=Path, default=Path("../web/database/data"))
    parser.add_argument("--seed", type=int, default=SEED)
    args = parser.parse_args(argv)

    dataset = generate(args.seed)
    write(dataset, args.out)

    echo(
        f"Wrote {len(dataset.products)} products, {len(dataset.sales):,} sales rows and "
        f"{len(dataset.restocks):,} restocks to {args.out}"
    )
    echo(f"Stockout days: {dataset.stockout_days:,} of {dataset.open_days:,} product-days")


if __name__ == "__main__":
    main()

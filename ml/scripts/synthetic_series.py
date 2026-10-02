"""Builds forecast requests from the synthetic dataset.

This does in Python what Laravel's SeriesBuilder does in PHP: sum daily sales
into weeks (Monday to Sunday) or calendar months, start each product at its
first sale, fill the quiet periods with zero, and stop at the last period that
was complete on the given day. The tests and the contract fixtures use it, and
`scripts/accuracy_report.py` runs the model on it.
"""

from __future__ import annotations

from datetime import date
from typing import Any

import pandas as pd

from app.periods import Granularity
from scripts.generate_synthetic import Dataset, generate


def periodise(sales: pd.DataFrame, granularity: Granularity, as_of: date) -> pd.DataFrame:
    """Units and average price per product per period, zero-filled, ending at the last complete period."""
    frame = sales.copy()
    frame["date"] = pd.to_datetime(frame["date"])
    frame["quantity"] = frame["quantity"].astype(int)
    frame["unit_price"] = frame["unit_price"].astype(float)
    frame["revenue"] = frame["quantity"] * frame["unit_price"]

    rule = "W-SUN" if granularity is Granularity.WEEK else "M"
    frame["start"] = frame["date"].dt.to_period(rule).dt.start_time
    frame["end"] = frame["date"].dt.to_period(rule).dt.end_time.dt.normalize()

    cutoff = pd.Timestamp(as_of)
    frame = frame[frame["end"] <= cutoff]

    totals = frame.groupby(["sku", "start"], as_index=False).agg(
        qty=("quantity", "sum"), revenue=("revenue", "sum")
    )
    freq = "W-MON" if granularity is Granularity.WEEK else "MS"
    last_start = totals["start"].max()

    filled: list[pd.DataFrame] = []

    for sku, group in totals.groupby("sku"):
        grid = pd.date_range(group["start"].min(), last_start, freq=freq)
        part = group.set_index("start").reindex(grid)
        part["sku"] = sku
        part["qty"] = part["qty"].fillna(0).astype(int)
        part["avg_price"] = (part["revenue"] / part["qty"].where(part["qty"] > 0)).round(2)
        filled.append(part.rename_axis("start").reset_index()[["sku", "start", "qty", "avg_price"]])

    return pd.concat(filled, ignore_index=True)


def build_request(
    granularity: Granularity,
    horizon: int,
    *,
    dataset: Dataset | None = None,
    as_of: date = date(2026, 9, 30),
    skus: list[str] | None = None,
    folds: int = 3,
) -> dict[str, Any]:
    """A forecast request (as a JSON-ready dict) for the synthetic shop."""
    data = dataset or generate()
    periods = periodise(data.sales, granularity, as_of)
    category = dict(zip(data.products["sku"], data.products["category"], strict=True))
    product_id = {sku: number for number, sku in enumerate(sorted(category), start=1)}

    series = []

    for sku, group in periods.groupby("sku"):
        if skus is not None and sku not in skus:
            continue

        series.append(
            {
                "series_key": f"{product_id[str(sku)]}:1",
                "product_id": product_id[str(sku)],
                "category": category[str(sku)],
                "periods": [
                    {
                        "start": row.start.date().isoformat(),
                        "qty": int(row.qty),
                        "avg_price": None if pd.isna(row.avg_price) else float(row.avg_price),
                    }
                    for row in group.itertuples()
                ],
            }
        )

    return {
        "granularity": granularity.value,
        "horizon": horizon,
        "series": series,
        "options": {"backtest_folds": folds},
    }

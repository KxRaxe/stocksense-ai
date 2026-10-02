"""Shared test helpers: small, quick, hand-made demand series."""

from __future__ import annotations

from datetime import date, timedelta
from typing import Any

import numpy as np
import pytest

from app import model
from app.periods import Granularity
from scripts.export_contracts import default_directory

# Where the schemas and examples shared with Laravel live.
CONTRACTS = default_directory()

# A Monday, and the first of a month: the last period of history in the examples.
LAST_WEEK = date(2026, 9, 21)
LAST_MONTH = date(2026, 9, 1)


def starts_ending_at(last: date, count: int, granularity: Granularity) -> list[date]:
    """`count` consecutive period starts whose last one is `last`."""
    if granularity is Granularity.WEEK:
        return [last - timedelta(days=7 * (count - 1 - i)) for i in range(count)]

    out = [last]
    for _ in range(count - 1):
        previous = out[0]
        year, month = (
            (previous.year - 1, 12) if previous.month == 1 else (previous.year, previous.month - 1)
        )
        out.insert(0, date(year, month, 1))

    return out


def demand(
    length: int,
    level: float,
    granularity: Granularity,
    rng: np.random.Generator,
    seasonal: float = 0.4,
) -> np.ndarray:
    """A yearly seasonal pattern around `level`, with noise, as whole units."""
    cycle = 52 if granularity is Granularity.WEEK else 12
    t = np.arange(length)
    shape = 1 + seasonal * np.sin(2 * np.pi * t / cycle)

    return rng.poisson(level * shape).astype(int)


def make_series(
    key: int,
    length: int,
    *,
    category: str = "A",
    level: float = 20,
    granularity: Granularity = Granularity.WEEK,
    last: date | None = None,
    seed: int = 0,
    values: list[int] | None = None,
) -> dict[str, Any]:
    rng = np.random.default_rng(seed + key)
    last = last or (LAST_WEEK if granularity is Granularity.WEEK else LAST_MONTH)
    qty = values if values is not None else demand(length, level, granularity, rng).tolist()
    starts = starts_ending_at(last, len(qty), granularity)

    return {
        "series_key": f"{key}:1",
        "product_id": key,
        "category": category,
        "periods": [
            {"start": s.isoformat(), "qty": int(q), "avg_price": 10.0 if q else None}
            for s, q in zip(starts, qty, strict=True)
        ],
    }


def make_request(
    *,
    granularity: Granularity = Granularity.WEEK,
    horizon: int = 4,
    folds: int = 2,
    lengths: tuple[int, ...] = (110, 110, 110, 110, 110, 110),
    seed: int = 0,
) -> dict[str, Any]:
    """A request over several series of the given lengths, in two categories."""
    return {
        "granularity": granularity.value,
        "horizon": horizon,
        "series": [
            make_series(
                i + 1,
                length,
                category="A" if i % 2 == 0 else "B",
                level=10 + 7 * i,
                granularity=granularity,
                seed=seed,
            )
            for i, length in enumerate(lengths)
        ],
        "options": {"backtest_folds": folds},
    }


@pytest.fixture(autouse=True)
def small_model(request: pytest.FixtureRequest, monkeypatch: pytest.MonkeyPatch) -> None:
    """Most tests only need a model that works, not a good one: use far fewer trees so they run quickly.

    Tests marked `quality` measure accuracy and use the real settings.
    """
    if request.node.get_closest_marker("quality") is None:
        monkeypatch.setitem(model.PARAMS, "n_estimators", 40)

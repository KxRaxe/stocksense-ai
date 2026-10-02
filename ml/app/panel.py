"""The request's series laid out as one grid, which is how the model sees them."""

from __future__ import annotations

import warnings
from dataclasses import dataclass

import numpy as np
import numpy.typing as npt
import pandas as pd

from app.periods import Granularity, period_range, period_starts
from app.schemas import SeriesRequest


@dataclass
class Panel:
    """Demand for every product on a shared timeline.

    Rows are periods (the earliest start of any series to the shared last
    period), columns are products. `y` is NaN before a product's first period.
    """

    granularity: Granularity
    keys: list[str]
    product_ids: list[int]
    categories: list[str]
    category_codes: npt.NDArray[np.float64]
    starts: pd.DatetimeIndex
    y: npt.NDArray[np.float64]
    # Average selling price in each period (NaN where nothing sold or the period is unknown).
    prices: npt.NDArray[np.float64]
    # Row of each product's first period.
    offsets: npt.NDArray[np.int64]

    @property
    def last(self) -> int:
        """Row of the last period of history."""
        return len(self.starts) - 1

    @property
    def n_series(self) -> int:
        return len(self.keys)

    def history_length(self, row: int) -> npt.NDArray[np.int64]:
        """Periods of history each product has up to and including `row` (0 if it had not started)."""
        return np.maximum(row - self.offsets + 1, 0)

    def price_at(self, row: int) -> npt.NDArray[np.float64]:
        """Each product's typical selling price as known at `row`: the median over its selling periods so far (0 if none)."""
        known = self.prices[: row + 1]

        with warnings.catch_warnings():
            warnings.simplefilter(
                "ignore", RuntimeWarning
            )  # an all-empty column is expected, and means 0
            median = np.nanmedian(known, axis=0)

        return np.nan_to_num(median, nan=0.0)

    def timeline(self, rows: int) -> pd.DatetimeIndex:
        """The first `rows` period starts, running on past the last period of history if need be."""
        return period_starts(self.starts[0].date(), rows, self.granularity)

    @classmethod
    def from_request(cls, request: SeriesRequest) -> Panel:
        series = request.series
        granularity = request.granularity

        first = min(s.periods[0].start for s in series)
        last = series[0].periods[-1].start
        grid = period_range(first, last, granularity)

        y = np.full((len(grid), len(series)), np.nan)
        offsets = np.zeros(len(series), dtype=np.int64)
        prices = np.full((len(grid), len(series)), np.nan)

        for column, s in enumerate(series):
            offset = int(grid.get_loc(pd.Timestamp(s.periods[0].start)))  # type: ignore[arg-type]
            offsets[column] = offset
            y[offset : offset + len(s.periods), column] = [p.qty for p in s.periods]
            prices[offset : offset + len(s.periods), column] = [
                p.avg_price if p.qty > 0 and p.avg_price else np.nan for p in s.periods
            ]

        names = sorted({s.category for s in series})
        codes = {name: float(position) for position, name in enumerate(names)}

        return cls(
            granularity=granularity,
            keys=[s.series_key for s in series],
            product_ids=[s.product_id for s in series],
            categories=[s.category for s in series],
            category_codes=np.array([codes[s.category] for s in series]),
            starts=grid,
            y=y,
            prices=prices,
            offsets=offsets,
        )

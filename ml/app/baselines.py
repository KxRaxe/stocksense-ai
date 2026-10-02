"""The two yardsticks the model has to beat.

A model is only worth running if it does better than something much simpler:

* **Seasonal naive**: the same period last year. Strong when demand is
  seasonal and steady, noisy because it copies last year's randomness.
* **Moving average**: the average of the last few periods, held flat. Strong
  when demand is stable, blind to any seasonal change.

The moving average is also what the service falls back on for a product with
too little history for the model.
"""

from __future__ import annotations

import numpy as np
import numpy.typing as npt

from app.periods import MOVING_AVERAGE_WINDOW, SEASON, Granularity

Grid = npt.NDArray[np.float64]

# Wide enough that roughly 8 in 10 periods fall inside, if demand were normal.
Z_80 = 1.2816


def moving_average(y: Grid, row: int, horizon: int, granularity: Granularity) -> Grid:
    """The mean of each product's last few periods up to `row`, repeated over the horizon.

    Returns one row per product and one column per period ahead; NaN for a
    product that had not started by `row`.
    """
    window = MOVING_AVERAGE_WINDOW[granularity]
    recent = y[max(row - window + 1, 0) : row + 1]

    counts = (~np.isnan(recent)).sum(axis=0)
    mean = np.where(counts > 0, np.nansum(recent, axis=0) / np.maximum(counts, 1), np.nan)

    return np.repeat(mean[:, None], horizon, axis=1)


def seasonal_naive(y: Grid, row: int, horizon: int, granularity: Granularity) -> Grid:
    """What happened in the same period a year earlier, for each period ahead.

    A product that did not exist a year earlier gets its moving average, so the
    baseline is defined for every product.
    """
    season = SEASON[granularity]
    fallback = moving_average(y, row, horizon, granularity)
    out = fallback.copy()

    for step in range(horizon):
        earlier = row + 1 + step - season

        if earlier < 0:
            continue

        known = ~np.isnan(y[earlier])
        out[known, step] = y[earlier, known]

    return out


def recent_std(values: npt.NDArray[np.float64], periods: int) -> float:
    """Spread of a product's last `periods` values (0 when there are fewer than two)."""
    recent = values[~np.isnan(values)][-periods:]

    return float(np.std(recent, ddof=1)) if len(recent) > 1 else 0.0


def moving_average_interval(mean: Grid, spread: npt.NDArray[np.float64]) -> npt.NDArray[np.float64]:
    """Lower, median and upper for a flat forecast: the mean give or take the product's usual swing."""
    half = Z_80 * spread[:, None]

    return np.stack([np.clip(mean - half, 0.0, None), mean, mean + half], axis=2)

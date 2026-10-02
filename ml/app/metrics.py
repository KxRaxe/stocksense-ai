"""Forecast accuracy measures: MAE, RMSE, MAPE and WAPE.

MAPE divides by the actual value, which is zero in a quiet week, so it is
computed only over periods that had sales. That makes it undefined for a set
with no sales at all (None), and flattering for slow, intermittent products;
WAPE (total error over total sales) is reported beside it for that reason.
"""

from __future__ import annotations

from dataclasses import dataclass

import numpy as np
import numpy.typing as npt

Floats = npt.NDArray[np.floating]


@dataclass(frozen=True)
class MetricSet:
    mae: float
    rmse: float
    mape: float | None
    wape: float | None
    n: int
    # Share of actual values inside the forecast's lower-upper interval, as a
    # percentage. Only set for a forecast that has an interval.
    coverage: float | None = None


def compute(
    actual: Floats,
    predicted: Floats,
    lower: Floats | None = None,
    upper: Floats | None = None,
) -> MetricSet | None:
    """Accuracy of `predicted` against `actual`, or None when there is nothing to compare."""
    actual = np.asarray(actual, dtype=float)
    predicted = np.asarray(predicted, dtype=float)

    if actual.shape != predicted.shape:
        raise ValueError("actual and predicted must have the same length")
    if actual.size == 0:
        return None

    error = predicted - actual
    sold = actual > 0

    mape = float(np.mean(np.abs(error[sold]) / actual[sold]) * 100) if sold.any() else None
    total = float(actual.sum())
    wape = float(np.abs(error).sum() / total * 100) if total > 0 else None

    coverage = None
    if lower is not None and upper is not None:
        inside = (actual >= np.asarray(lower, dtype=float)) & (
            actual <= np.asarray(upper, dtype=float)
        )
        coverage = float(inside.mean() * 100)

    return MetricSet(
        mae=float(np.abs(error).mean()),
        rmse=float(np.sqrt((error**2).mean())),
        mape=mape,
        wape=wape,
        n=int(actual.size),
        coverage=coverage,
    )

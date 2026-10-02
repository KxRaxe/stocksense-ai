"""Measuring accuracy by replaying the past: a rolling-origin backtest.

Pick a point in history, pretend it is "now", train on what was known then, and
forecast the next few periods; then compare with what actually happened. Repeat
from several points, each time letting the model see a bit more (an expanding
window). The test windows do not overlap and the last one ends at the latest
data, so the numbers describe how the model does on recent, unseen weeks.

Only products with at least a year of history *at that point* are scored, since
those are the ones the model is trusted with. The same periods are scored for
the two baselines, so the comparison is like for like.
"""

from __future__ import annotations

from dataclasses import dataclass

import numpy as np
import numpy.typing as npt

from app.baselines import moving_average, seasonal_naive
from app.engine import fit, predict
from app.metrics import MetricSet, compute
from app.panel import Panel
from app.periods import MIN_HISTORY

Grid = npt.NDArray[np.float64]


@dataclass
class Fold:
    """One test window: what each scored product actually sold and what each method predicted."""

    origin: int
    columns: npt.NDArray[np.int64]
    actual: Grid
    model: Grid
    seasonal_naive: Grid
    moving_average: Grid

    @property
    def horizon(self) -> int:
        return int(self.actual.shape[1])


@dataclass
class Scores:
    model: MetricSet
    seasonal_naive: MetricSet
    moving_average: MetricSet


def origins(panel: Panel, horizon: int, folds: int) -> list[int]:
    """The last row of history each test window may see, oldest first.

    The newest window ends at the latest data and each earlier one ends where
    the next begins. Windows that would start before there is any history are dropped.
    """
    rows = [panel.last - (folds - number) * horizon for number in range(folds)]

    return [row for row in rows if row >= 0]


def backtest(panel: Panel, horizon: int, folds: int, seed: int = 42) -> list[Fold]:
    """Runs the test windows that have enough history to be meaningful."""
    done: list[Fold] = []

    for origin in origins(panel, horizon, folds):
        scored = np.flatnonzero(panel.history_length(origin) >= MIN_HISTORY[panel.granularity])

        if len(scored) == 0:
            continue

        fitted = fit(panel, origin, seed)

        if fitted is None:
            continue

        window = slice(origin + 1, origin + 1 + horizon)

        done.append(
            Fold(
                origin=origin,
                columns=scored,
                actual=panel.y[window][:, scored].T,
                model=predict(panel, fitted, origin, horizon, scored),
                seasonal_naive=seasonal_naive(panel.y, origin, horizon, panel.granularity)[scored],
                moving_average=moving_average(panel.y, origin, horizon, panel.granularity)[scored],
            )
        )

    return done


def score(folds: list[Fold], select: npt.NDArray[np.bool_] | None = None) -> Scores | None:
    """Accuracy of the model and both baselines over the given windows.

    `select` picks products by column of the panel (e.g. one category); the
    default scores them all. None when nothing was scored.
    """
    actual: list[Grid] = []
    model: list[Grid] = []
    naive: list[Grid] = []
    average: list[Grid] = []

    for fold in folds:
        keep = np.ones(len(fold.columns), dtype=bool) if select is None else select[fold.columns]

        actual.append(fold.actual[keep].ravel())
        model.append(fold.model[keep].reshape(-1, 3))
        naive.append(fold.seasonal_naive[keep].ravel())
        average.append(fold.moving_average[keep].ravel())

    if not actual:
        return None

    truth = np.concatenate(actual)
    quantiles = np.concatenate(model)

    own = compute(truth, quantiles[:, 1], quantiles[:, 0], quantiles[:, 2])
    by_naive = compute(truth, np.concatenate(naive))
    by_average = compute(truth, np.concatenate(average))

    if own is None or by_naive is None or by_average is None:
        return None

    return Scores(own, by_naive, by_average)


def residual_std(folds: list[Fold], at_least: int = 4) -> dict[int, float]:
    """The typical size of the model's miss for each product, in units per period.

    This is the root mean square of the forecast errors, so a model that is
    consistently too high or too low is penalised as well as one that is noisy.
    It is the spread safety stock is sized against. Needs a few scored periods
    to mean anything; products with fewer are left out and the caller uses the
    spread of their own history instead.
    """
    misses: dict[int, list[float]] = {}

    for fold in folds:
        residual = fold.actual - fold.model[:, :, 1]

        for position, column in enumerate(fold.columns):
            misses.setdefault(int(column), []).extend(residual[position].tolist())

    return {
        column: float(np.sqrt(np.mean(np.square(values))))
        for column, values in misses.items()
        if len(values) >= at_least
    }

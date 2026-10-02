"""Training the model and forecasting with it.

Both operate "as of" a row of the panel: only periods up to that row are used,
which is what lets the backtest replay the past honestly. Forecasting several
periods ahead is recursive: predict the next period, treat the median as if it
had happened, and predict the one after.
"""

from __future__ import annotations

from dataclasses import dataclass

import numpy as np
import numpy.typing as npt

from app.features import build_matrix, calendar_frame, history_features
from app.model import QUANTILES, DemandModel, train
from app.panel import Panel

Grid = npt.NDArray[np.float64]

# Below this the model has too little to learn from and is not trained at all.
MIN_TRAIN_ROWS = 150


@dataclass
class Fitted:
    model: DemandModel
    scale: Grid
    n_rows: int


def scales(y: Grid) -> Grid:
    """Each product's average demand over the given periods (0 when it has none)."""
    known = ~np.isnan(y)

    return np.asarray(np.nansum(y, axis=0) / np.maximum(known.sum(axis=0), 1), dtype=np.float64)


def _scaled(y: Grid, scale: Grid) -> Grid:
    """Demand as a multiple of each product's scale; NaN for a product with no sales to scale by."""
    return y / np.where(scale > 0, scale, np.nan)


def _static(panel: Panel, scale: Grid, row: int) -> dict[str, Grid]:
    """Per-product features that do not change over time, as known at `row`."""
    return {
        "category_code": panel.category_codes,
        "log_scale": np.log1p(scale),
        "log_price": np.log1p(panel.price_at(row)),
    }


def fit(panel: Panel, row: int, seed: int = 42) -> Fitted | None:
    """Trains on everything up to and including `row`; None if there is too little to learn from."""
    granularity = panel.granularity
    scale = scales(panel.y[: row + 1])
    scaled = _scaled(panel.y[: row + 1], scale)

    history = history_features(scaled, granularity, panel.offsets)
    calendar = calendar_frame(panel.starts[: row + 1], granularity)

    # A row can teach the model once it has a value to predict and a previous one to start from.
    rows, cols = np.nonzero(np.isfinite(scaled) & np.isfinite(history["lag_1"]))

    if len(rows) < MIN_TRAIN_ROWS:
        return None

    X = build_matrix(history, calendar, _static(panel, scale, row), rows, cols, granularity)

    return Fitted(train(X, scaled[rows, cols], seed), scale, len(rows))


def predict(
    panel: Panel, fitted: Fitted, row: int, horizon: int, columns: npt.NDArray[np.int64]
) -> Grid:
    """Forecasts `horizon` periods after `row` for the given products.

    Returns an array shaped (products, periods ahead, 3) holding the lower,
    median and upper forecast in units.
    """
    granularity = panel.granularity
    scale = fitted.scale
    known = _scaled(panel.y[: row + 1], scale)

    # The known history, then a blank row for each period to be predicted.
    grid = np.vstack([known, np.full((horizon, panel.n_series), np.nan)])
    calendar = calendar_frame(panel.timeline(row + 1 + horizon), granularity)
    static = _static(panel, scale, row)

    out = np.zeros((len(columns), horizon, len(QUANTILES)))

    for step in range(horizon):
        target = row + 1 + step
        history = history_features(grid[: target + 1], granularity, panel.offsets)
        X = build_matrix(
            history,
            calendar.iloc[: target + 1],
            static,
            np.full(len(columns), target, dtype=np.int64),
            columns,
            granularity,
        )
        quantiles = fitted.model.predict(X)

        # The median becomes the "actual" for the next step's lags.
        grid[target, columns] = quantiles[:, 1]
        out[:, step, :] = quantiles * scale[columns, None]

    return out

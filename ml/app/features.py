"""Features for the demand model.

The model learns one pattern across every product, so each product's history is
first divided by its own average (its *scale*). A value of 1.0 then means "a
typical period for this product", and a fast mover and a slow mover look alike
to the model, which is what lets products with short histories borrow strength
from the rest. Predictions are multiplied back by the scale.

Every history feature at period `t` is computed from periods *before* `t` only
(lags and rolling statistics are shifted by one), so the model can never see the
value it is predicting. tests/test_features.py checks that by changing the
future and confirming nothing moves. Training and forecasting use the same
functions, so a feature means the same thing in both.

The panel is a grid: one row per period, one column per product, NaN where a
product did not exist yet. Operating on the grid keeps products with different
start dates aligned without any per-product loop.
"""

from __future__ import annotations

import numpy as np
import numpy.typing as npt
import pandas as pd

from app.periods import Granularity, period_end

Grid = npt.NDArray[np.float64]

LAGS: dict[Granularity, tuple[int, ...]] = {
    Granularity.WEEK: (1, 2, 3, 4, 8, 12, 52),
    Granularity.MONTH: (1, 2, 3, 6, 12),
}
ROLL_MEAN: dict[Granularity, tuple[int, ...]] = {
    Granularity.WEEK: (4, 8, 12),
    Granularity.MONTH: (3, 6),
}
ROLL_STD: dict[Granularity, tuple[int, ...]] = {
    Granularity.WEEK: (4, 8),
    Granularity.MONTH: (3, 6),
}
ZERO_SHARE_WINDOW: dict[Granularity, int] = {Granularity.WEEK: 8, Granularity.MONTH: 6}

CALENDAR_FEATURES = (
    "period_of_year",
    "month",
    "quarter",
    "december_share",
    "christmas_rush",
    "back_to_school",
    "paydays",
    "days_in_period",
)
STATIC_FEATURES = ("category_code", "log_scale", "log_price")


def history_feature_names(granularity: Granularity) -> list[str]:
    return [
        *(f"lag_{k}" for k in LAGS[granularity]),
        *(f"roll_mean_{w}" for w in ROLL_MEAN[granularity]),
        *(f"roll_std_{w}" for w in ROLL_STD[granularity]),
        "zero_share",
        "age",
    ]


def feature_names(granularity: Granularity) -> list[str]:
    """Every feature, in the order the model sees them."""
    return [*history_feature_names(granularity), *CALENDAR_FEATURES, *STATIC_FEATURES]


def calendar_frame(starts: pd.DatetimeIndex, granularity: Granularity) -> pd.DataFrame:
    """Calendar features for each period.

    The Philippine retail calendar matters here: December (Christmas, 13th-month
    pay), the back-to-school rush before classes open in June, and the 15th /
    end-of-month paydays that drive grocery and household spending. Window
    features are the *share of the period's days* inside the window, so weeks
    that straddle an edge are handled smoothly.
    """
    ends = pd.DatetimeIndex([period_end(s, granularity) for s in starts])
    days = pd.date_range(starts[0], ends[-1], freq="D")

    month = days.month.to_numpy()
    day = days.day.to_numpy()

    flags = {
        "december_share": month == 12,
        "christmas_rush": (month == 12) & (day >= 15) & (day <= 24),
        "back_to_school": ((month == 5) & (day >= 15)) | (month == 6),
        "paydays": (day == 15) | (day == days.days_in_month.to_numpy()),
    }

    first = (starts - days[0]).days.to_numpy()
    last = (ends - days[0]).days.to_numpy()
    length = last - first + 1

    frame: dict[str, npt.NDArray[np.float64]] = {}

    for name, flag in flags.items():
        running = np.concatenate([[0], np.cumsum(flag)])
        inside = running[last + 1] - running[first]
        # Paydays are counted; the rest are the share of the period's days.
        frame[name] = inside.astype(float) if name == "paydays" else inside / length

    # A week is filed under the month and ISO week of its Thursday, the day that
    # decides which year an ISO week belongs to; a month is simply itself.
    anchor = starts + pd.Timedelta(days=3) if granularity is Granularity.WEEK else starts

    if granularity is Granularity.WEEK:
        frame["period_of_year"] = anchor.isocalendar().week.to_numpy().astype(float)
    else:
        frame["period_of_year"] = anchor.month.to_numpy().astype(float)

    frame["month"] = anchor.month.to_numpy().astype(float)
    frame["quarter"] = anchor.quarter.to_numpy().astype(float)
    frame["days_in_period"] = length.astype(float)

    return pd.DataFrame(frame, index=starts)[list(CALENDAR_FEATURES)]


def history_features(
    scaled: Grid, granularity: Granularity, offsets: npt.NDArray[np.int64]
) -> dict[str, Grid]:
    """Lag, rolling and age features for every (period, product) cell.

    `scaled` is the grid of demand divided by each product's scale, with NaN
    where a value is unknown (before a product started, or not yet forecast).
    Row `t` of every returned grid depends only on rows before `t`.
    """
    frame = pd.DataFrame(scaled)
    past = frame.shift(1)
    out: dict[str, Grid] = {}

    for k in LAGS[granularity]:
        out[f"lag_{k}"] = frame.shift(k).to_numpy()

    for w in ROLL_MEAN[granularity]:
        out[f"roll_mean_{w}"] = past.rolling(w, min_periods=w).mean().to_numpy()

    for w in ROLL_STD[granularity]:
        out[f"roll_std_{w}"] = past.rolling(w, min_periods=w).std().to_numpy()

    # How intermittent demand has been: the share of recent periods with no sales.
    zero = (past <= 0).astype(float).where(past.notna())
    window = ZERO_SHARE_WINDOW[granularity]
    out["zero_share"] = zero.rolling(window, min_periods=window).mean().to_numpy()

    # Periods since the product's first, so the model can tell a launch from a staple.
    out["age"] = (np.arange(len(frame))[:, None] - offsets[None, :]).astype(float)

    return out


def build_matrix(
    history: dict[str, Grid],
    calendar: pd.DataFrame,
    static: dict[str, npt.NDArray[np.float64]],
    rows: npt.NDArray[np.int64],
    cols: npt.NDArray[np.int64],
    granularity: Granularity,
) -> pd.DataFrame:
    """Gathers the features of the given (period, product) cells into a model-ready table."""
    data: dict[str, npt.NDArray[np.float64]] = {}

    for name in history_feature_names(granularity):
        data[name] = history[name][rows, cols]

    cal = calendar.to_numpy()

    for position, name in enumerate(CALENDAR_FEATURES):
        data[name] = cal[rows, position]

    for name in STATIC_FEATURES:
        data[name] = static[name][cols]

    return pd.DataFrame(data)[feature_names(granularity)]

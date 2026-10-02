"""Calendar arithmetic for the two granularities the service forecasts at.

A *week* is Monday to Sunday and is named by its Monday; a *month* is a
calendar month named by its first day. Laravel builds the series on exactly
these boundaries, so anything not aligned to them is rejected.
"""

from __future__ import annotations

from datetime import date, timedelta
from enum import StrEnum

import pandas as pd


class Granularity(StrEnum):
    WEEK = "week"
    MONTH = "month"


# A product needs a full year of history before the model is trusted with it
# (the proposal's rule); shorter series fall back to a moving average and are
# flagged low confidence. A year is 52 weeks or 12 months.
MIN_HISTORY: dict[Granularity, int] = {Granularity.WEEK: 52, Granularity.MONTH: 12}

# How far back the seasonal-naive baseline looks: the same period last year.
SEASON: dict[Granularity, int] = {Granularity.WEEK: 52, Granularity.MONTH: 12}

# The longest horizon that can be asked for. It is also what keeps the
# seasonal-naive baseline defined: it never has to look past the data it has.
MAX_HORIZON: dict[Granularity, int] = {Granularity.WEEK: 26, Granularity.MONTH: 12}

# Periods averaged by the moving-average baseline and the short-history fallback.
MOVING_AVERAGE_WINDOW: dict[Granularity, int] = {Granularity.WEEK: 4, Granularity.MONTH: 3}

_FREQ: dict[Granularity, str] = {Granularity.WEEK: "W-MON", Granularity.MONTH: "MS"}


def is_aligned(day: date, granularity: Granularity) -> bool:
    """Whether `day` is the first day of a period."""
    if granularity is Granularity.WEEK:
        return day.weekday() == 0
    return day.day == 1


def next_start(day: date, granularity: Granularity) -> date:
    """The first day of the period after the one starting on `day`."""
    if granularity is Granularity.WEEK:
        return day + timedelta(days=7)
    return date(day.year + (day.month == 12), day.month % 12 + 1, 1)


def period_starts(first: date, count: int, granularity: Granularity) -> pd.DatetimeIndex:
    """The first days of `count` consecutive periods beginning at `first`."""
    return pd.date_range(first, periods=count, freq=_FREQ[granularity])


def period_range(first: date, last: date, granularity: Granularity) -> pd.DatetimeIndex:
    """The first days of every period from the one starting on `first` to the one starting on `last`."""
    return pd.date_range(first, last, freq=_FREQ[granularity])


def period_end(start: pd.Timestamp, granularity: Granularity) -> pd.Timestamp:
    """The last day of the period beginning on `start` (inclusive)."""
    if granularity is Granularity.WEEK:
        return start + pd.Timedelta(days=6)
    return start + pd.offsets.MonthEnd(0)

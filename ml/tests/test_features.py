"""Feature engineering: the calendar, the history features, and above all no leakage.

The model must never see the value it is predicting. The leakage tests change
the future and check that nothing the model would have used moves.
"""

from __future__ import annotations

from datetime import date

import numpy as np
import pandas as pd
import pytest

from app.features import (
    CALENDAR_FEATURES,
    LAGS,
    ROLL_MEAN,
    STATIC_FEATURES,
    build_matrix,
    calendar_frame,
    feature_names,
    history_feature_names,
    history_features,
)
from app.periods import Granularity, period_starts

W, M = Granularity.WEEK, Granularity.MONTH


def weeks(first: date, count: int = 1) -> pd.DatetimeIndex:
    return period_starts(first, count, W)


def months(first: date, count: int = 1) -> pd.DatetimeIndex:
    return period_starts(first, count, M)


def calendar_row(first: date, granularity: Granularity) -> pd.Series:
    index = weeks(first) if granularity is W else months(first)

    return calendar_frame(index, granularity).iloc[0]


class TestCalendar:
    def test_has_the_documented_columns_in_order(self):
        assert list(calendar_frame(weeks(date(2026, 9, 21), 3), W).columns) == list(
            CALENDAR_FEATURES
        )

    def test_december_and_the_christmas_rush(self):
        full = calendar_row(date(2026, 12, 14), W)  # 14-20 December
        assert full["december_share"] == 1.0
        assert full["christmas_rush"] == pytest.approx(6 / 7)  # the 15th to the 20th

        straddling = calendar_row(date(2026, 12, 28), W)  # 28 December to 3 January
        assert straddling["december_share"] == pytest.approx(4 / 7)
        assert straddling["christmas_rush"] == 0.0

        assert calendar_row(date(2026, 12, 1), M)["december_share"] == 1.0
        assert calendar_row(date(2026, 12, 1), M)["christmas_rush"] == pytest.approx(10 / 31)
        assert calendar_row(date(2026, 11, 1), M)["december_share"] == 0.0

    def test_back_to_school_runs_from_mid_may_to_the_end_of_june(self):
        assert calendar_row(date(2026, 5, 11), W)["back_to_school"] == pytest.approx(
            3 / 7
        )  # 15-17 May
        assert calendar_row(date(2026, 6, 8), W)["back_to_school"] == 1.0
        assert calendar_row(date(2026, 7, 6), W)["back_to_school"] == 0.0
        assert calendar_row(date(2026, 5, 1), M)["back_to_school"] == pytest.approx(17 / 31)
        assert calendar_row(date(2026, 6, 1), M)["back_to_school"] == 1.0
        assert calendar_row(date(2026, 4, 1), M)["back_to_school"] == 0.0

    def test_paydays_are_the_15th_and_the_last_day_of_the_month(self):
        assert calendar_row(date(2026, 9, 14), W)["paydays"] == 1  # contains 15 September
        assert calendar_row(date(2026, 9, 28), W)["paydays"] == 1  # contains 30 September
        assert calendar_row(date(2026, 9, 21), W)["paydays"] == 0
        assert calendar_row(date(2026, 9, 1), M)["paydays"] == 2
        assert calendar_row(date(2028, 2, 1), M)["paydays"] == 2  # a leap February still has two

    def test_days_in_period(self):
        assert calendar_row(date(2026, 9, 21), W)["days_in_period"] == 7
        assert calendar_row(date(2026, 2, 1), M)["days_in_period"] == 28
        assert calendar_row(date(2028, 2, 1), M)["days_in_period"] == 29

    def test_a_week_belongs_to_the_month_and_iso_week_of_its_thursday(self):
        # Monday 29 December 2025 to Sunday 4 January 2026: Thursday is 1 January.
        new_year = calendar_row(date(2025, 12, 29), W)
        assert (new_year["month"], new_year["period_of_year"], new_year["quarter"]) == (1, 1, 1)

        ordinary = calendar_row(date(2026, 9, 21), W)
        assert (ordinary["month"], ordinary["period_of_year"], ordinary["quarter"]) == (9, 39, 3)

    def test_a_month_is_its_own_period_of_year(self):
        row = calendar_row(date(2026, 4, 1), M)

        assert (row["month"], row["period_of_year"], row["quarter"]) == (4, 4, 2)

    def test_depends_only_on_the_dates(self):
        a = calendar_frame(weeks(date(2026, 1, 5), 10), W)
        b = calendar_frame(weeks(date(2026, 1, 5), 20), W).iloc[:10]

        pd.testing.assert_frame_equal(a, b)


def grid(rows: int = 120, columns: int = 4, seed: int = 0) -> np.ndarray:
    return np.random.default_rng(seed).uniform(0, 3, size=(rows, columns))


class TestHistoryFeatures:
    def test_names_match_the_columns_produced(self):
        produced = history_features(grid(80), W, np.zeros(4, dtype=np.int64))

        assert list(produced) == history_feature_names(W)

    def test_lags_are_the_values_that_many_periods_back(self):
        y = grid(80)
        out = history_features(y, W, np.zeros(4, dtype=np.int64))

        for k in LAGS[W]:
            assert np.array_equal(out[f"lag_{k}"][k:], y[:-k])
            assert np.isnan(out[f"lag_{k}"][:k]).all()

    def test_rolling_means_cover_the_periods_before_this_one(self):
        y = grid(80)
        out = history_features(y, W, np.zeros(4, dtype=np.int64))

        for w in ROLL_MEAN[W]:
            t = 40
            assert out[f"roll_mean_{w}"][t] == pytest.approx(y[t - w : t].mean(axis=0))
            # Not enough periods yet: unknown, not a guess.
            assert np.isnan(out[f"roll_mean_{w}"][:w]).all()

    def test_a_product_that_has_not_started_has_no_history(self):
        y = grid(60, 2)
        y[:10, 1] = np.nan  # the second product starts at row 10
        out = history_features(y, W, np.array([0, 10]))

        assert np.isnan(out["lag_1"][10, 1])  # nothing before its first period
        assert not np.isnan(out["lag_1"][11, 1])
        assert np.isnan(out["roll_mean_4"][13, 1])  # window still reaches back before the start
        assert not np.isnan(out["roll_mean_4"][14, 1])

    def test_age_counts_periods_since_the_products_first(self):
        out = history_features(grid(30, 2), W, np.array([0, 10]))

        assert out["age"][15].tolist() == [15.0, 5.0]

    def test_zero_share_is_how_often_recent_periods_were_empty(self):
        y = np.ones((30, 1))
        y[10:14, 0] = 0  # four empty periods
        out = history_features(y, W, np.zeros(1, dtype=np.int64))

        # Window of 8 ending the period before row 20 covers rows 12-19: two of them empty.
        assert out["zero_share"][20, 0] == pytest.approx(2 / 8)
        assert out["zero_share"][30 - 1, 0] == 0.0

    def test_monthly_uses_its_own_lags(self):
        out = history_features(grid(40), M, np.zeros(4, dtype=np.int64))

        assert "lag_12" in out
        assert "lag_52" not in out


class TestNoLeakage:
    @pytest.mark.parametrize("granularity", [W, M])
    @pytest.mark.parametrize("cut", [20, 55, 90])
    def test_changing_the_future_changes_no_feature_up_to_that_period(self, granularity, cut):
        offsets = np.array([0, 0, 5, 12], dtype=np.int64)
        y = grid(110, 4)
        y[: offsets[2], 2] = np.nan
        y[: offsets[3], 3] = np.nan

        changed = y.copy()
        changed[cut:] = np.random.default_rng(99).uniform(50, 100, size=changed[cut:].shape)

        before = history_features(y, granularity, offsets)
        after = history_features(changed, granularity, offsets)

        for name in history_feature_names(granularity):
            # Rows 0..cut only know about rows before `cut`, which are the same.
            assert np.array_equal(
                before[name][: cut + 1], after[name][: cut + 1], equal_nan=True
            ), name

    def test_the_target_never_appears_in_its_own_features(self):
        y = grid(100, 1)
        marker = y.copy()
        marker[60, 0] = 1e9  # an absurd value at the period being predicted

        for name, values in history_features(marker, W, np.zeros(1, dtype=np.int64)).items():
            assert values[60, 0] == pytest.approx(
                history_features(y, W, np.zeros(1, dtype=np.int64))[name][60, 0], nan_ok=True
            ), name

    def test_a_prediction_is_the_same_whether_or_not_later_periods_are_known(self):
        """The recursive forecaster appends predictions one at a time; they must not disturb earlier rows."""
        offsets = np.zeros(2, dtype=np.int64)
        known = grid(70, 2)
        extended = np.vstack([known, np.full((6, 2), np.nan)])
        extended[70] = [1.0, 2.0]  # a "prediction" filled in for the first future period

        short = history_features(known, W, offsets)
        longer = history_features(extended, W, offsets)

        for name in history_feature_names(W):
            assert np.array_equal(short[name], longer[name][:70], equal_nan=True), name


class TestMatrix:
    def test_columns_are_in_the_documented_order(self):
        y = grid(60, 3)
        offsets = np.zeros(3, dtype=np.int64)
        starts = weeks(date(2025, 1, 6), 60)
        static = {name: np.arange(3, dtype=float) + i for i, name in enumerate(STATIC_FEATURES)}

        matrix = build_matrix(
            history_features(y, W, offsets),
            calendar_frame(starts, W),
            static,
            np.array([30, 40], dtype=np.int64),
            np.array([2, 0], dtype=np.int64),
            W,
        )

        assert list(matrix.columns) == feature_names(W)
        assert matrix.shape == (2, len(feature_names(W)))

    def test_each_row_holds_the_features_of_its_cell(self):
        y = grid(60, 3)
        offsets = np.zeros(3, dtype=np.int64)
        starts = weeks(date(2025, 1, 6), 60)
        static = {
            "category_code": np.array([0.0, 1.0, 2.0]),
            "log_scale": np.array([5.0, 6.0, 7.0]),
            "log_price": np.array([1.0, 2.0, 3.0]),
        }
        history = history_features(y, W, offsets)
        calendar = calendar_frame(starts, W)

        matrix = build_matrix(history, calendar, static, np.array([30]), np.array([2]), W)

        assert matrix.loc[0, "lag_1"] == y[29, 2]
        assert matrix.loc[0, "log_scale"] == 7.0
        assert matrix.loc[0, "category_code"] == 2.0
        assert matrix.loc[0, "period_of_year"] == calendar.iloc[30]["period_of_year"]

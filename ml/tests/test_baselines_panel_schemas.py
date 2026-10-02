"""The two baselines, the panel the request becomes, and what the contract rejects."""

from __future__ import annotations

import copy
from datetime import date

import numpy as np
import pytest
from pydantic import ValidationError

from app.baselines import moving_average, moving_average_interval, recent_std, seasonal_naive
from app.panel import Panel
from app.periods import Granularity
from app.schemas import BacktestRequest, ForecastRequest
from tests.conftest import LAST_WEEK, make_request, make_series

W, M = Granularity.WEEK, Granularity.MONTH


class TestMovingAverage:
    def test_is_the_mean_of_the_last_four_weeks_held_flat(self):
        y = np.array([[1.0], [2.0], [3.0], [4.0], [5.0], [6.0]])

        out = moving_average(y, 5, 3, W)

        assert out.shape == (1, 3)
        assert out[0].tolist() == [4.5, 4.5, 4.5]  # (3 + 4 + 5 + 6) / 4

    def test_months_average_three(self):
        y = np.array([[10.0], [20.0], [30.0], [40.0]])

        assert moving_average(y, 3, 1, M)[0, 0] == 30.0

    def test_uses_only_periods_up_to_the_origin(self):
        y = np.array([[1.0], [2.0], [3.0], [100.0]])

        assert moving_average(y, 2, 1, M)[0, 0] == 2.0

    def test_a_product_that_started_recently_averages_what_it_has(self):
        y = np.array([[np.nan], [np.nan], [4.0], [6.0]])

        assert moving_average(y, 3, 1, W)[0, 0] == 5.0

    def test_a_product_that_has_not_started_has_no_average(self):
        y = np.array([[np.nan, 5.0], [np.nan, 7.0]])

        assert np.isnan(moving_average(y, 1, 1, W)[0, 0])


class TestSeasonalNaive:
    def test_repeats_the_same_week_last_year(self):
        y = np.arange(60, dtype=float)[:, None]

        out = seasonal_naive(y, 55, 3, W)

        # Periods 56, 57, 58 look back 52 weeks to 4, 5, 6.
        assert out[0].tolist() == [4.0, 5.0, 6.0]

    def test_repeats_the_same_month_last_year(self):
        y = np.arange(20, dtype=float)[:, None]

        assert seasonal_naive(y, 17, 2, M)[0].tolist() == [6.0, 7.0]

    def test_a_product_too_young_for_last_year_uses_its_moving_average(self):
        y = np.arange(30, dtype=float)[:, None]

        out = seasonal_naive(y, 29, 2, W)

        assert out[0].tolist() == [27.5, 27.5]

    def test_a_product_with_a_gap_before_last_year_falls_back_for_that_period_only(self):
        y = np.arange(60, dtype=float)[:, None]
        y[:5] = np.nan  # started at row 5

        out = seasonal_naive(y, 55, 3, W)  # looks back to rows 4, 5, 6

        assert out[0, 0] == pytest.approx(moving_average(y, 55, 1, W)[0, 0])
        assert out[0, 1:].tolist() == [5.0, 6.0]


class TestSpread:
    def test_is_the_standard_deviation_of_the_recent_values(self):
        assert recent_std(np.array([1.0, 2.0, 3.0, 4.0]), 4) == pytest.approx(
            np.std([1, 2, 3, 4], ddof=1)
        )

    def test_looks_only_at_the_most_recent_periods(self):
        assert recent_std(np.array([100.0, 1.0, 1.0, 1.0]), 3) == 0.0

    def test_skips_periods_before_the_product_started(self):
        assert recent_std(np.array([np.nan, np.nan, 2.0, 4.0]), 26) == pytest.approx(
            np.std([2, 4], ddof=1)
        )

    def test_is_zero_with_fewer_than_two_values(self):
        assert recent_std(np.array([np.nan, 3.0]), 12) == 0.0

    def test_interval_is_the_mean_give_or_take_the_swing_and_never_below_zero(self):
        out = moving_average_interval(np.array([[10.0, 10.0]]), np.array([20.0]))

        assert out.shape == (1, 2, 3)
        assert out[0, 0].tolist() == [0.0, 10.0, pytest.approx(10 + 1.2816 * 20)]


class TestPanel:
    def build(self, **kwargs):
        return Panel.from_request(ForecastRequest.model_validate(make_request(**kwargs)))

    def test_lays_series_out_on_a_shared_timeline(self):
        panel = self.build(lengths=(110, 60, 20), horizon=2)

        assert panel.y.shape == (110, 3)
        assert panel.starts[-1].date() == LAST_WEEK
        assert panel.offsets.tolist() == [0, 50, 90]
        assert panel.last == 109

    def test_leaves_the_time_before_a_product_started_unknown(self):
        panel = self.build(lengths=(110, 60), horizon=2)

        assert np.isnan(panel.y[:50, 1]).all()
        assert not np.isnan(panel.y[50:, 1]).any()

    def test_counts_history_per_product(self):
        panel = self.build(lengths=(110, 60, 20))

        assert panel.history_length(panel.last).tolist() == [110, 60, 20]
        assert panel.history_length(30).tolist() == [31, 0, 0]  # the others had not started

    def test_takes_the_median_price_of_periods_that_sold(self):
        payload = make_request(lengths=(6,), folds=0)
        payload["series"] = [make_series(1, 0, values=[5, 0, 5, 5, 0, 5], last=LAST_WEEK)]
        for period, price in zip(
            payload["series"][0]["periods"], [10, None, 12, 14, None, 100], strict=True
        ):
            period["avg_price"] = price

        panel = Panel.from_request(ForecastRequest.model_validate(payload))

        assert panel.price_at(panel.last)[0] == 13.0  # median of 10, 12, 14, 100
        # As known earlier on, the later prices do not count.
        assert panel.price_at(2)[0] == 11.0  # median of 10, 12
        assert panel.price_at(1)[0] == 10.0

    def test_codes_categories_by_name(self):
        panel = self.build(lengths=(110, 110, 110))

        assert panel.categories == ["A", "B", "A"]
        assert panel.category_codes.tolist() == [0.0, 1.0, 0.0]

    def test_the_timeline_continues_past_the_data(self):
        panel = self.build(lengths=(110,))

        future = panel.timeline(panel.last + 1 + 3)

        assert future[-1].date() == date(2026, 10, 12)
        assert future[: panel.last + 1].equals(panel.starts)


class TestContract:
    def valid(self):
        return make_request(lengths=(60, 60), folds=1, horizon=4)

    def test_accepts_a_well_formed_request(self):
        request = ForecastRequest.model_validate(self.valid())

        assert request.horizon == 4
        assert request.options.backtest_folds == 1

    def test_options_default_when_left_out(self):
        payload = self.valid()
        del payload["options"]

        assert ForecastRequest.model_validate(payload).options.backtest_folds == 3

    def rejects(self, payload, message):
        with pytest.raises(ValidationError, match=message):
            ForecastRequest.model_validate(payload)

    def test_rejects_a_series_key_used_twice(self):
        payload = self.valid()
        payload["series"][1]["series_key"] = payload["series"][0]["series_key"]

        self.rejects(payload, "series_key must be unique")

    def test_rejects_a_week_that_does_not_start_on_monday(self):
        payload = self.valid()
        payload["series"][0]["periods"][5]["start"] = "2025-11-05"  # a Wednesday

        self.rejects(payload, "is not the first day of a week")

    def test_rejects_a_gap(self):
        payload = self.valid()
        del payload["series"][0]["periods"][10]

        self.rejects(payload, "consecutive")

    def test_rejects_periods_out_of_order(self):
        payload = self.valid()
        periods = payload["series"][0]["periods"]
        periods[3], periods[4] = periods[4], periods[3]

        self.rejects(payload, "consecutive")

    def test_rejects_series_that_end_on_different_periods(self):
        payload = self.valid()
        payload["series"][1] = make_series(2, 60, last=date(2026, 9, 14))

        self.rejects(payload, "end on the same period")

    def test_rejects_a_horizon_beyond_the_limit(self):
        payload = self.valid()
        payload["horizon"] = 27

        self.rejects(payload, "at most 26")

    def test_months_have_a_shorter_limit(self):
        payload = make_request(granularity=M, lengths=(30,), horizon=13)

        self.rejects(payload, "at most 12")

    def test_rejects_negative_quantities(self):
        payload = self.valid()
        payload["series"][0]["periods"][0]["qty"] = -1

        self.rejects(payload, "greater than or equal to 0")

    def test_rejects_unknown_fields(self):
        payload = self.valid()
        payload["horizons"] = 4

        self.rejects(payload, "Extra inputs are not permitted")

    def test_rejects_no_series(self):
        payload = self.valid()
        payload["series"] = []

        self.rejects(payload, "at least 1 item")

    def test_months_must_start_on_the_first(self):
        payload = make_request(granularity=M, lengths=(20,), horizon=2)
        payload["series"][0]["periods"][4]["start"] = "2025-03-02"

        self.rejects(payload, "is not the first day of a month")

    def test_a_backtest_request_has_the_same_rules(self):
        payload = copy.deepcopy(self.valid())
        payload.pop("options")
        payload["folds"] = 3
        del payload["series"][0]["periods"][10]

        with pytest.raises(ValidationError, match="consecutive"):
            BacktestRequest.model_validate(payload)

    def test_a_backtest_needs_at_least_one_fold(self):
        payload = self.valid()
        payload.pop("options")
        payload["folds"] = 0

        with pytest.raises(ValidationError):
            BacktestRequest.model_validate(payload)

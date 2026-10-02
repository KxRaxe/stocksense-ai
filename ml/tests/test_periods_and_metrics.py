"""Period arithmetic and the accuracy measures."""

from __future__ import annotations

from datetime import date

import numpy as np
import pytest

from app import metrics
from app.periods import Granularity, is_aligned, next_start, period_end, period_range, period_starts

W, M = Granularity.WEEK, Granularity.MONTH


class TestPeriods:
    def test_a_week_starts_on_monday_and_a_month_on_the_first(self):
        assert is_aligned(date(2026, 9, 21), W)
        assert not is_aligned(date(2026, 9, 22), W)
        assert is_aligned(date(2026, 9, 1), M)
        assert not is_aligned(date(2026, 9, 2), M)

    def test_next_period(self):
        assert next_start(date(2026, 9, 21), W) == date(2026, 9, 28)
        assert next_start(date(2026, 9, 1), M) == date(2026, 10, 1)

    def test_a_month_rolls_over_the_year(self):
        assert next_start(date(2026, 12, 1), M) == date(2027, 1, 1)

    def test_ranges_are_consecutive(self):
        weeks = period_starts(date(2026, 9, 21), 3, W)
        months = period_range(date(2026, 11, 1), date(2027, 2, 1), M)

        assert [d.date() for d in weeks] == [
            date(2026, 9, 21),
            date(2026, 9, 28),
            date(2026, 10, 5),
        ]
        assert [d.date() for d in months] == [
            date(2026, 11, 1),
            date(2026, 12, 1),
            date(2027, 1, 1),
            date(2027, 2, 1),
        ]

    @pytest.mark.parametrize(
        ("start", "granularity", "end"),
        [
            (date(2026, 9, 21), W, date(2026, 9, 27)),
            (date(2026, 9, 1), M, date(2026, 9, 30)),
            (date(2028, 2, 1), M, date(2028, 2, 29)),
            (date(2026, 12, 1), M, date(2026, 12, 31)),
        ],
    )
    def test_period_end_is_the_last_day(self, start, granularity, end):
        import pandas as pd

        assert period_end(pd.Timestamp(start), granularity).date() == end


class TestMetrics:
    def test_known_values(self):
        result = metrics.compute(np.array([10.0, 20.0, 30.0]), np.array([12.0, 18.0, 30.0]))

        assert result is not None
        assert result.mae == pytest.approx(4 / 3)
        assert result.rmse == pytest.approx(np.sqrt(8 / 3))
        assert result.mape == pytest.approx((0.2 + 0.1 + 0.0) / 3 * 100)
        assert result.wape == pytest.approx(4 / 60 * 100)
        assert result.n == 3
        assert result.coverage is None

    def test_a_perfect_forecast_has_no_error(self):
        result = metrics.compute(np.array([5.0, 0.0, 9.0]), np.array([5.0, 0.0, 9.0]))

        assert result is not None
        assert (result.mae, result.rmse, result.mape, result.wape) == (0, 0, 0, 0)

    def test_mape_ignores_periods_with_no_sales(self):
        result = metrics.compute(np.array([0.0, 10.0]), np.array([5.0, 12.0]))

        assert result is not None
        # Only the second period counts: |12 - 10| / 10.
        assert result.mape == pytest.approx(20.0)
        assert result.mae == pytest.approx(3.5)

    def test_mape_and_wape_are_undefined_when_nothing_sold(self):
        result = metrics.compute(np.array([0.0, 0.0]), np.array([1.0, 2.0]))

        assert result is not None
        assert result.mape is None
        assert result.wape is None
        assert result.mae == pytest.approx(1.5)

    def test_wape_weights_by_volume(self):
        # A big miss on a big seller counts for more than the same percentage on a small one.
        result = metrics.compute(np.array([100.0, 1.0]), np.array([90.0, 2.0]))

        assert result is not None
        assert result.wape == pytest.approx(11 / 101 * 100)
        assert result.mape == pytest.approx((0.1 + 1.0) / 2 * 100)

    def test_coverage_is_the_share_inside_the_interval(self):
        result = metrics.compute(
            np.array([5.0, 20.0, 9.0, 0.0]),
            np.array([6.0, 10.0, 9.0, 1.0]),
            lower=np.array([4.0, 8.0, 7.0, 0.0]),
            upper=np.array([8.0, 12.0, 11.0, 2.0]),
        )

        assert result is not None
        # Inside: 5 yes, 20 no, 9 yes, 0 yes.
        assert result.coverage == pytest.approx(75.0)

    def test_nothing_to_compare_gives_nothing(self):
        assert metrics.compute(np.array([]), np.array([])) is None

    def test_mismatched_lengths_are_an_error(self):
        with pytest.raises(ValueError, match="same length"):
            metrics.compute(np.array([1.0, 2.0]), np.array([1.0]))

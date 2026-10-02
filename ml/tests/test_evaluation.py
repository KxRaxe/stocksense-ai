"""The rolling-origin backtest: its windows, its honesty, and its arithmetic."""

from __future__ import annotations

import numpy as np
import pytest

from app.engine import fit, predict, scales
from app.evaluation import Fold, backtest, origins, residual_std, score
from app.panel import Panel
from app.periods import Granularity
from app.schemas import ForecastRequest
from tests.conftest import make_request

W = Granularity.WEEK


def panel_of(**kwargs) -> Panel:
    return Panel.from_request(ForecastRequest.model_validate(make_request(**kwargs)))


class TestWindows:
    def test_the_newest_window_ends_at_the_latest_data_and_none_overlap(self):
        panel = panel_of(lengths=(110,))

        rows = origins(panel, 4, 3)

        assert rows == [109 - 12, 109 - 8, 109 - 4]
        # Each window starts where the previous one ended.
        assert [row + 4 for row in rows] == [109 - 8, 109 - 4, 109]

    def test_windows_that_would_start_before_the_data_are_dropped(self):
        panel = panel_of(lengths=(6,))

        assert origins(panel, 4, 3) == [1]

    def test_one_fold_is_the_latest_window(self):
        assert origins(panel_of(lengths=(110,)), 8, 1) == [109 - 8]


class TestHonesty:
    def test_a_model_trained_at_an_origin_has_never_seen_what_comes_after(self):
        """Replace everything after the origin with nonsense: the forecast made at the origin must not move."""
        original = panel_of(lengths=(110, 110, 110, 110), seed=3)
        origin = 90

        altered = panel_of(lengths=(110, 110, 110, 110), seed=3)
        altered.y[origin + 1 :] = np.random.default_rng(5).integers(
            500, 900, size=altered.y[origin + 1 :].shape
        )

        columns = np.arange(4)
        before = predict(original, fit(original, origin), origin, 4, columns)  # type: ignore[arg-type]
        after = predict(altered, fit(altered, origin), origin, 4, columns)  # type: ignore[arg-type]

        assert np.array_equal(before, after)

    def test_a_test_window_is_scored_against_what_really_sold(self):
        panel = panel_of(lengths=(110, 110, 110, 110))

        fold = backtest(panel, 4, 1)[0]

        assert fold.origin == panel.last - 4
        assert np.array_equal(
            fold.actual, panel.y[fold.origin + 1 : fold.origin + 5][:, fold.columns].T
        )

    def test_the_baselines_look_only_at_what_was_known_at_the_origin(self):
        panel = panel_of(lengths=(110, 110, 110, 110))
        fold = backtest(panel, 4, 1)[0]

        # Moving average of the four weeks up to the origin, held flat.
        expected = panel.y[fold.origin - 3 : fold.origin + 1][:, fold.columns].mean(axis=0)

        assert np.allclose(fold.moving_average, expected[:, None])
        # Seasonal naive: the same weeks a year earlier.
        assert np.array_equal(
            fold.seasonal_naive,
            panel.y[fold.origin + 1 - 52 : fold.origin + 5 - 52][:, fold.columns].T,
        )


class TestWhoIsScored:
    def test_only_products_with_a_year_of_history_at_the_origin(self):
        panel = panel_of(lengths=(110, 110, 110, 70, 40))

        fold = backtest(panel, 4, 1)[0]

        # At the origin (row 105) the 70-week product has 66 weeks of history; the 40-week one has 36.
        assert fold.columns.tolist() == [0, 1, 2, 3]

    def test_a_product_joins_the_windows_once_it_is_a_year_old(self):
        panel = panel_of(lengths=(110, 110, 110, 110, 60))

        first, second, third = backtest(panel, 4, 3)

        # Origins 97, 101, 105: the 60-week product has 48, 52 and 56 weeks of history by then.
        assert 4 not in first.columns
        assert 4 in second.columns
        assert 4 in third.columns

    def test_no_windows_when_nothing_is_old_enough(self):
        panel = panel_of(lengths=(40, 40, 30))

        assert backtest(panel, 4, 3) == []

    def test_no_windows_when_there_is_too_little_to_learn_from(self):
        # Old enough, but only a handful of rows to train on.
        panel = panel_of(lengths=(53,), horizon=1)

        assert backtest(panel, 1, 1) == []


class TestScoring:
    def fold(self, actual, model, naive, average, columns=(0,)):
        actual = np.array(actual, dtype=float)
        model = np.array(model, dtype=float)  # (products, periods, 3)

        return Fold(
            origin=10,
            columns=np.array(columns),
            actual=actual,
            model=model,
            seasonal_naive=np.array(naive, dtype=float),
            moving_average=np.array(average, dtype=float),
        )

    def test_pools_every_product_and_period(self):
        fold = self.fold(
            actual=[[10, 20], [30, 40]],
            model=[[[8, 12, 14], [18, 22, 26]], [[28, 28, 34], [38, 42, 48]]],
            naive=[[10, 10], [30, 30]],
            average=[[15, 15], [35, 35]],
            columns=(0, 1),
        )

        result = score([fold])

        assert result is not None
        assert result.model.n == 4
        assert result.model.mae == pytest.approx((2 + 2 + 2 + 2) / 4)
        assert result.seasonal_naive.mae == pytest.approx((0 + 10 + 0 + 10) / 4)
        assert result.moving_average.mae == pytest.approx((5 + 5 + 5 + 5) / 4)
        # Every actual value lies inside its interval.
        assert result.model.coverage == 100.0
        assert result.seasonal_naive.coverage is None

    def test_can_score_one_group_of_products(self):
        fold = self.fold(
            actual=[[10], [100]],
            model=[[[9, 11, 13]], [[80, 90, 120]]],
            naive=[[10], [100]],
            average=[[10], [100]],
            columns=(0, 1),
        )

        only_second = score([fold], np.array([False, True]))

        assert only_second is not None
        assert only_second.model.n == 1
        assert only_second.model.mae == 10.0

    def test_a_group_with_no_scored_products_gives_nothing(self):
        fold = self.fold(
            actual=[[10]], model=[[[9, 11, 13]]], naive=[[10]], average=[[10]], columns=(0,)
        )

        assert score([fold], np.array([False, True])) is None
        assert score([]) is None

    def test_combines_several_windows(self):
        one = self.fold([[10]], [[[10, 10, 10]]], [[10]], [[10]])
        two = self.fold([[20]], [[[10, 10, 10]]], [[10]], [[10]])

        result = score([one, two])

        assert result is not None
        assert result.model.n == 2
        assert result.model.mae == pytest.approx(5.0)


class TestResiduals:
    def test_is_the_root_mean_square_miss_per_product(self):
        fold = Fold(
            origin=10,
            columns=np.array([3]),
            actual=np.array([[10.0, 14.0, 6.0, 10.0]]),
            model=np.array([[[0, 8, 0], [0, 10, 0], [0, 10, 0], [0, 6, 0]]], dtype=float),
            seasonal_naive=np.zeros((1, 4)),
            moving_average=np.zeros((1, 4)),
        )

        # Misses are +2, +4, -4, +4: root mean square sqrt((4 + 16 + 16 + 16) / 4).
        assert residual_std([fold]) == {3: pytest.approx(np.sqrt(13))}

    def test_needs_a_few_scored_periods(self):
        fold = Fold(
            origin=10,
            columns=np.array([0]),
            actual=np.array([[1.0, 2.0, 3.0]]),
            model=np.zeros((1, 3, 3)),
            seasonal_naive=np.zeros((1, 3)),
            moving_average=np.zeros((1, 3)),
        )

        assert residual_std([fold]) == {}
        assert residual_std([fold], at_least=3) != {}


def test_the_scale_of_a_product_is_its_average_demand():
    y = np.array([[2.0, np.nan], [4.0, np.nan], [6.0, 10.0]])

    assert scales(y).tolist() == [4.0, 10.0]


def test_a_product_with_no_sales_has_no_scale():
    assert scales(np.zeros((5, 1))).tolist() == [0.0]

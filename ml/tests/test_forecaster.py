"""The forecaster end to end on small hand-made series: structure, the low-confidence rule, and the registry."""

from __future__ import annotations

from datetime import date

import numpy as np
import pandas as pd
import pytest

from app.forecaster import Forecaster
from app.periods import Granularity
from app.registry import Registry
from app.schemas import BacktestRequest, ForecastRequest
from tests.conftest import LAST_MONTH, LAST_WEEK, make_request, make_series

W, M = Granularity.WEEK, Granularity.MONTH


@pytest.fixture
def registry(tmp_path) -> Registry:
    return Registry(tmp_path / "models")


def forecast(registry: Registry, **kwargs):
    return Forecaster(registry).forecast(ForecastRequest.model_validate(make_request(**kwargs)))


@pytest.fixture(scope="module")
def mixed(tmp_path_factory):
    """Four products with a long history, one with 30 weeks and one brand new, forecast 4 weeks ahead."""
    registry = Registry(tmp_path_factory.mktemp("models"))
    response = forecast(registry, lengths=(110, 110, 110, 110, 30, 8), horizon=4, folds=2)

    return response, registry


class TestForecasts:
    def test_one_forecast_per_product_in_the_order_given(self, mixed):
        response, _ = mixed

        assert [f.series_key for f in response.forecasts] == [
            "1:1",
            "2:1",
            "3:1",
            "4:1",
            "5:1",
            "6:1",
        ]
        assert [f.product_id for f in response.forecasts] == [1, 2, 3, 4, 5, 6]

    def test_periods_continue_from_the_end_of_the_history(self, mixed):
        response, _ = mixed

        assert [p.start for p in response.forecasts[0].periods] == [
            date(2026, 9, 28),
            date(2026, 10, 5),
            date(2026, 10, 12),
            date(2026, 10, 19),
        ]
        assert response.training.last_period == LAST_WEEK

    def test_the_interval_brackets_the_median_and_is_never_negative(self, mixed):
        response, _ = mixed

        for series in response.forecasts:
            for period in series.periods:
                assert 0 <= period.yhat_lower <= period.yhat <= period.yhat_upper

    def test_forecasts_are_in_the_right_ballpark(self, mixed):
        response, _ = mixed
        # Product 1 sells about 10 a week, product 4 about 31 (see make_request).
        low, high = response.forecasts[0], response.forecasts[3]

        assert 4 < low.periods[0].yhat < 20
        assert 15 < high.periods[0].yhat < 60
        assert high.periods[0].yhat > low.periods[0].yhat

    def test_products_with_a_year_of_history_use_the_model(self, mixed):
        response, _ = mixed

        for series in response.forecasts[:4]:
            assert (series.method, series.low_confidence) == ("xgboost", False)

    def test_products_under_a_year_old_use_a_moving_average_and_are_flagged(self, mixed):
        response, _ = mixed

        for series in response.forecasts[4:]:
            assert (series.method, series.low_confidence) == ("moving_average", True)

        assert [f.history_periods for f in response.forecasts] == [110, 110, 110, 110, 30, 8]

    def test_a_moving_average_forecast_is_flat(self, mixed):
        response, _ = mixed

        assert len({p.yhat for p in response.forecasts[5].periods}) == 1

    def test_a_year_is_the_cut_off(self, registry):
        response = forecast(registry, lengths=(110, 110, 110, 110, 52, 51), folds=0)

        assert [f.low_confidence for f in response.forecasts] == [
            False,
            False,
            False,
            False,
            False,
            True,
        ]

    def test_says_what_it_trained_on(self, mixed):
        response, _ = mixed
        training = response.training

        assert (training.n_series, training.n_model_series, training.n_low_confidence) == (6, 4, 2)
        assert training.n_rows > 400
        assert training.backtest_folds == 2
        assert training.backtest_origins == [date(2026, 7, 27), date(2026, 8, 24)]
        assert training.first_period < training.last_period

    def test_gives_every_product_an_error_estimate(self, mixed):
        response, _ = mixed

        assert set(response.residual_std) == {"1:1", "2:1", "3:1", "4:1", "5:1", "6:1"}
        assert all(value >= 0 for value in response.residual_std.values())

    def test_names_the_features_the_model_leaned_on(self, mixed):
        response, _ = mixed

        assert response.feature_importance
        assert sum(item.importance for item in response.feature_importance) == pytest.approx(
            1.0, abs=1e-3
        )
        shares = [item.importance for item in response.feature_importance]
        assert shares == sorted(shares, reverse=True)


class TestAccuracyReport:
    def test_measures_the_model_against_both_baselines(self, mixed):
        response, _ = mixed

        assert response.metrics is not None
        assert response.baseline_metrics is not None
        assert (
            response.metrics.n
            == response.baseline_metrics.seasonal_naive.n
            == response.baseline_metrics.moving_average.n
        )
        # 4 scored products x 4 weeks x 2 windows.
        assert response.metrics.n == 32
        assert response.metrics.coverage is not None
        assert response.baseline_metrics.seasonal_naive.coverage is None

    def test_breaks_accuracy_down_by_category(self, mixed):
        response, _ = mixed

        assert set(response.per_category_metrics) == {"A", "B"}
        assert response.per_category_metrics["A"].model.n == 16

    def test_only_scores_products_the_model_is_trusted_with(self, mixed):
        response, _ = mixed

        # The two young products are not in the scored 32.
        assert response.metrics is not None
        assert response.metrics.n == 4 * 4 * 2

    def test_skipping_the_backtest_still_forecasts(self, registry):
        response = forecast(registry, folds=0)

        assert response.metrics is None
        assert response.baseline_metrics is None
        assert response.per_category_metrics == {}
        assert response.training.backtest_folds == 0
        assert len(response.forecasts) == 6
        assert all(f.method == "xgboost" for f in response.forecasts)


class TestWithoutAModel:
    def test_all_young_products_get_moving_averages_and_no_metrics(self, registry):
        response = forecast(registry, lengths=(30, 20, 10), folds=2)

        assert {f.method for f in response.forecasts} == {"moving_average"}
        assert all(f.low_confidence for f in response.forecasts)
        assert response.metrics is None
        assert response.feature_importance == []
        assert response.training.n_rows == 0
        assert response.model_version.startswith("moving-average-week-")
        assert registry.list() == []

    def test_a_product_that_never_sold_is_forecast_at_zero(self, registry):
        payload = make_request(lengths=(110, 110), folds=0)
        payload["series"].append(make_series(3, 0, values=[0] * 20))

        response = Forecaster(registry).forecast(ForecastRequest.model_validate(payload))
        quiet = response.forecasts[2]

        assert quiet.low_confidence
        assert all(p.yhat == p.yhat_lower == p.yhat_upper == 0 for p in quiet.periods)

    def test_a_steady_new_product_is_forecast_at_its_level(self, registry):
        payload = make_request(lengths=(110, 110), folds=0)
        payload["series"].append(make_series(3, 0, values=[10, 10, 10, 10, 10, 10, 10, 10]))

        response = Forecaster(registry).forecast(ForecastRequest.model_validate(payload))
        new = response.forecasts[2]

        assert [p.yhat for p in new.periods] == [10.0] * 4
        assert [p.yhat_lower for p in new.periods] == [10.0] * 4  # no spread, so no interval


class TestRegistry:
    def test_saves_the_trained_model_under_its_version(self, mixed):
        response, registry = mixed
        saved = registry.list(W)

        assert [info.version for info in saved] == [response.model_version]
        assert saved[0].granularity is W
        assert saved[0].last_period == LAST_WEEK
        assert saved[0].n_rows == response.training.n_rows
        assert "lag_1" in saved[0].features
        assert saved[0].metrics == response.metrics

    def test_a_saved_model_can_be_loaded_and_used(self, mixed):
        _, registry = mixed
        info = registry.list(W)[0]
        model = registry.load(W, info.version)

        row = pd.DataFrame([dict.fromkeys(info.features, 1.0)])

        assert model.predict(row).shape == (1, 3)
        assert np.all(np.diff(model.predict(row), axis=1) >= 0)  # lower <= median <= upper

    def test_keeps_only_the_most_recent_models(self, registry, monkeypatch):
        versions = iter(f"xgb-week-2026100{n}T000000Z" for n in range(1, 8))
        monkeypatch.setattr(registry, "new_version", lambda granularity, now=None: next(versions))

        for _ in range(7):
            forecast(registry, folds=0, lengths=(60, 60, 60, 60))

        kept = sorted(info.version for info in registry.list(W))

        assert kept == [f"xgb-week-2026100{n}T000000Z" for n in range(3, 8)]
        assert len(list((registry.root / "week").glob("*.ubj"))) == 5

    def test_a_forecast_run_with_a_model_gets_a_model_version(self, mixed):
        response, _ = mixed

        assert response.model_version.startswith("xgb-week-")


class TestMonthly:
    def test_forecasts_calendar_months(self, registry):
        response = forecast(
            registry, granularity=M, horizon=3, folds=1, lengths=(40, 40, 40, 40, 40, 9)
        )

        assert [p.start for p in response.forecasts[0].periods] == [
            date(2026, 10, 1),
            date(2026, 11, 1),
            date(2026, 12, 1),
        ]
        assert response.training.last_period == LAST_MONTH
        assert response.forecasts[0].method == "xgboost"
        assert response.forecasts[5].low_confidence
        assert response.model_version.startswith("xgb-month-")

    def test_a_year_of_months_is_enough(self, registry):
        response = forecast(
            registry, granularity=M, horizon=2, folds=0, lengths=(40, 40, 40, 40, 40, 12, 11)
        )

        assert [f.low_confidence for f in response.forecasts] == [False] * 6 + [True]


class TestRepeatability:
    def test_the_same_request_gives_the_same_forecast(self, registry):
        first = forecast(registry, lengths=(110, 110, 110), folds=1)
        second = forecast(registry, lengths=(110, 110, 110), folds=1)

        for a, b in zip(first.forecasts, second.forecasts, strict=True):
            assert [p.yhat for p in a.periods] == pytest.approx(
                [p.yhat for p in b.periods], rel=1e-6
            )

        assert first.metrics is not None and second.metrics is not None
        assert first.metrics.mae == pytest.approx(second.metrics.mae, rel=1e-6)


class TestBacktestEndpointLogic:
    def test_reports_each_window_and_the_whole(self, registry):
        payload = make_request(lengths=(110, 110, 110, 110), folds=0)
        payload.pop("options")
        payload["folds"] = 3

        report = Forecaster(registry).backtest(BacktestRequest.model_validate(payload))

        assert len(report.folds) == 3
        assert report.metrics is not None
        assert report.metrics.n == sum(f.metrics.n for f in report.folds)
        assert report.feature_importance
        # Each window starts the period after its origin and spans the horizon.
        for fold in report.folds:
            assert (fold.test_end - fold.test_start).days == 7 * 3
            assert (fold.test_start - fold.origin).days == 7

    def test_windows_do_not_overlap(self, registry):
        payload = make_request(lengths=(110, 110, 110, 110), folds=0)
        payload.pop("options")
        payload["folds"] = 3

        report = Forecaster(registry).backtest(BacktestRequest.model_validate(payload))

        for earlier, later in zip(report.folds, report.folds[1:], strict=False):
            assert earlier.test_end < later.test_start

    def test_the_last_window_ends_at_the_latest_data(self, registry):
        payload = make_request(lengths=(110, 110, 110, 110), folds=0)
        payload.pop("options")
        payload["folds"] = 2

        report = Forecaster(registry).backtest(BacktestRequest.model_validate(payload))

        assert report.folds[-1].test_end == LAST_WEEK

"""A guard on the model's accuracy: it has to earn its place against the simple baselines.

Runs the real settings over the synthetic shop (two years of weekly demand for
50 products) and checks the model beats both yardsticks on weeks it never saw.
If a change to the features or the model makes it worse than "same week last
year", this fails. The thresholds are deliberately loose: they catch a model
that has stopped working, not small wobbles.
"""

from __future__ import annotations

import pytest

from app.forecaster import Forecaster
from app.periods import Granularity
from app.registry import Registry
from app.schemas import BacktestRequest
from scripts.synthetic_series import build_request

pytestmark = pytest.mark.quality


@pytest.fixture(scope="module")
def weekly_report(tmp_path_factory):
    payload = build_request(Granularity.WEEK, 8)
    payload.pop("options")
    payload["folds"] = 4

    return Forecaster(Registry(tmp_path_factory.mktemp("models"))).backtest(
        BacktestRequest.model_validate(payload)
    )


def test_the_model_beats_the_same_week_last_year(weekly_report):
    model, naive = weekly_report.metrics, weekly_report.baseline_metrics.seasonal_naive

    assert model.wape < naive.wape
    assert model.mae < naive.mae
    assert model.rmse < naive.rmse


def test_the_model_beats_a_moving_average(weekly_report):
    model, average = weekly_report.metrics, weekly_report.baseline_metrics.moving_average

    assert model.wape < average.wape
    assert model.mae < average.mae


def test_the_model_is_clearly_useful_not_just_marginally_better(weekly_report):
    model, naive = weekly_report.metrics, weekly_report.baseline_metrics.seasonal_naive

    # At least a tenth better than last year's number.
    assert model.wape < naive.wape * 0.9


def test_it_beats_last_year_in_most_categories(weekly_report):
    wins = [
        name
        for name, scores in weekly_report.per_category_metrics.items()
        if scores.model.wape < scores.seasonal_naive.wape
    ]

    assert len(weekly_report.per_category_metrics) == 5
    assert len(wins) >= 4, f"only beat seasonal naive in {wins}"


def test_the_error_is_reasonable_for_weekly_demand(weekly_report):
    assert weekly_report.metrics.wape < 25  # percent of units sold


def test_the_interval_is_not_wildly_off_its_80_percent_target(weekly_report):
    # A P10-P90 interval should hold about 80% of actual values; allow a wide margin either side.
    assert 55 < weekly_report.metrics.coverage < 95


def test_the_scored_set_is_large_enough_to_mean_something(weekly_report):
    assert weekly_report.metrics.n >= 1000
    assert len(weekly_report.folds) == 4


def test_the_model_leans_on_recent_demand_and_the_calendar(weekly_report):
    top = {item.feature for item in weekly_report.feature_importance[:8]}

    assert top & {"roll_mean_4", "roll_mean_8", "lag_1", "lag_2"}
    assert top & {
        "period_of_year",
        "lag_52",
        "month",
        "paydays",
        "back_to_school",
        "christmas_rush",
    }

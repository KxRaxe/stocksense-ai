"""Turns a request into a response: measure, train, forecast.

For a forecast run the order is: replay the recent past to measure how accurate
the model is (the backtest), train a final model on all the history, then
forecast every product, using the model for products with a year or more of
history and a moving average, flagged low confidence, for the rest.
"""

from __future__ import annotations

from dataclasses import asdict
from datetime import UTC, datetime

import numpy as np
import numpy.typing as npt

from app.baselines import moving_average, moving_average_interval, recent_std
from app.engine import Fitted, fit, predict
from app.evaluation import Fold, Scores, backtest, residual_std, score
from app.metrics import MetricSet
from app.panel import Panel
from app.periods import MIN_HISTORY, Granularity
from app.registry import Registry
from app.schemas import (
    BacktestRequest,
    BacktestResponse,
    BaselineMetrics,
    CategoryMetrics,
    FeatureImportance,
    FoldResult,
    ForecastPeriod,
    ForecastRequest,
    ForecastResponse,
    Metrics,
    SeriesForecast,
    TrainingInfo,
)

# How many recent periods a product's own spread is taken from when the
# backtest has too few scored periods for it.
SPREAD_PERIODS = {"model": 26, "fallback": 12}


def _metrics(measured: MetricSet) -> Metrics:
    fields = {
        name: (round(value, 4) if isinstance(value, float) else value)
        for name, value in asdict(measured).items()
    }

    return Metrics(**fields)


def _baselines(scores: Scores) -> BaselineMetrics:
    return BaselineMetrics(
        seasonal_naive=_metrics(scores.seasonal_naive),
        moving_average=_metrics(scores.moving_average),
    )


class Forecaster:
    def __init__(self, registry: Registry, seed: int = 42) -> None:
        self.registry = registry
        self.seed = seed

    # ------------------------------------------------------------------ forecast

    def forecast(self, request: ForecastRequest) -> ForecastResponse:
        panel = Panel.from_request(request)
        granularity = panel.granularity
        horizon = request.horizon
        last = panel.last

        folds = (
            backtest(panel, horizon, request.options.backtest_folds, self.seed)
            if request.options.backtest_folds > 0
            else []
        )
        overall = score(folds)

        eligible = panel.history_length(last) >= MIN_HISTORY[granularity]
        fitted = fit(panel, last, self.seed) if eligible.any() else None

        by_model = np.flatnonzero(eligible) if fitted else np.array([], dtype=np.int64)
        by_average = np.setdiff1d(np.arange(panel.n_series), by_model)

        # Interval per product and period: (lower, median, upper) in units.
        bands: dict[int, npt.NDArray[np.float64]] = {}

        if fitted and len(by_model):
            ahead = predict(panel, fitted, last, horizon, by_model)
            bands.update({int(column): ahead[position] for position, column in enumerate(by_model)})

        if len(by_average):
            flat = moving_average(panel.y, last, horizon, granularity)[by_average]
            spread = np.array(
                [recent_std(panel.y[:, c], SPREAD_PERIODS["fallback"]) for c in by_average]
            )
            interval = moving_average_interval(flat, spread)
            bands.update(
                {int(column): interval[position] for position, column in enumerate(by_average)}
            )

        starts = panel.timeline(last + 1 + horizon)[last + 1 :]
        modelled = set(int(c) for c in by_model)

        forecasts = [
            SeriesForecast(
                series_key=panel.keys[column],
                product_id=panel.product_ids[column],
                method="xgboost" if column in modelled else "moving_average",
                low_confidence=column not in modelled,
                history_periods=int(panel.history_length(last)[column]),
                periods=[
                    ForecastPeriod(
                        start=starts[step].date(),
                        yhat=round(float(band[step, 1]), 4),
                        yhat_lower=round(float(band[step, 0]), 4),
                        yhat_upper=round(float(band[step, 2]), 4),
                    )
                    for step in range(horizon)
                ],
            )
            for column, band in sorted(bands.items())
        ]

        version = self._version(granularity, fitted, panel, overall)

        return ForecastResponse(
            model_version=version,
            granularity=granularity,
            horizon=horizon,
            generated_at=datetime.now(UTC),
            forecasts=forecasts,
            metrics=_metrics(overall.model) if overall else None,
            baseline_metrics=_baselines(overall) if overall else None,
            per_category_metrics=self._by_category(panel, folds),
            residual_std=self._spreads(panel, folds, modelled),
            feature_importance=self._importance(fitted),
            training=TrainingInfo(
                first_period=panel.starts[0].date(),
                last_period=panel.starts[last].date(),
                n_series=panel.n_series,
                n_model_series=len(by_model),
                n_low_confidence=len(by_average),
                n_rows=fitted.n_rows if fitted else 0,
                backtest_folds=len(folds),
                backtest_origins=[panel.starts[fold.origin].date() for fold in folds],
            ),
        )

    # ------------------------------------------------------------------ backtest

    def backtest(self, request: BacktestRequest) -> BacktestResponse:
        panel = Panel.from_request(request)
        horizon = request.horizon

        folds = backtest(panel, horizon, request.folds, self.seed)
        overall = score(folds)
        fitted = fit(panel, panel.last, self.seed)
        modelled = set(
            int(c)
            for c in np.flatnonzero(
                panel.history_length(panel.last) >= MIN_HISTORY[panel.granularity]
            )
        )

        results = []

        for fold in folds:
            scores = score([fold])

            if scores is None:
                continue

            results.append(
                FoldResult(
                    origin=panel.starts[fold.origin].date(),
                    test_start=panel.starts[fold.origin + 1].date(),
                    test_end=panel.starts[fold.origin + horizon].date(),
                    n_series=len(fold.columns),
                    metrics=_metrics(scores.model),
                    baseline_metrics=_baselines(scores),
                )
            )

        return BacktestResponse(
            model_version=self.registry.new_version(panel.granularity),
            granularity=panel.granularity,
            horizon=horizon,
            folds=results,
            metrics=_metrics(overall.model) if overall else None,
            baseline_metrics=_baselines(overall) if overall else None,
            per_category_metrics=self._by_category(panel, folds),
            residual_std=self._spreads(panel, folds, modelled),
            feature_importance=self._importance(fitted),
        )

    # ------------------------------------------------------------------- pieces

    def _version(
        self, granularity: Granularity, fitted: Fitted | None, panel: Panel, overall: Scores | None
    ) -> str:
        """Saves the trained model and returns its version; a run with no model gets a version saying so."""
        version = self.registry.new_version(granularity)

        if fitted is None:
            return version.replace("xgb-", "moving-average-", 1)

        self.registry.save(
            fitted.model,
            version=version,
            granularity=granularity,
            last_period=panel.starts[panel.last].date().isoformat(),
            n_rows=fitted.n_rows,
            n_series=panel.n_series,
            metrics=_metrics(overall.model) if overall else None,
        )

        return version

    @staticmethod
    def _by_category(panel: Panel, folds: list[Fold]) -> dict[str, CategoryMetrics]:
        result: dict[str, CategoryMetrics] = {}
        categories = np.array(panel.categories)

        for name in sorted(set(panel.categories)):
            scores = score(folds, categories == name)

            if scores is not None:
                result[name] = CategoryMetrics(
                    model=_metrics(scores.model),
                    seasonal_naive=_metrics(scores.seasonal_naive),
                    moving_average=_metrics(scores.moving_average),
                )

        return result

    @staticmethod
    def _spreads(panel: Panel, folds: list[Fold], modelled: set[int]) -> dict[str, float]:
        """Typical forecast error per product: from the backtest where it has enough, else from the product's own history."""
        measured = residual_std(folds)

        return {
            panel.keys[column]: round(
                measured.get(
                    column,
                    recent_std(
                        panel.y[:, column],
                        SPREAD_PERIODS["model" if column in modelled else "fallback"],
                    ),
                ),
                4,
            )
            for column in range(panel.n_series)
        }

    @staticmethod
    def _importance(fitted: Fitted | None) -> list[FeatureImportance]:
        if fitted is None:
            return []

        return [
            FeatureImportance(feature=name, importance=round(share, 6))
            for name, share in fitted.model.importance()
        ]

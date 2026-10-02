"""The contract between Laravel and this service.

These models are the single definition of what is sent and returned. Their
JSON Schemas are exported to `contracts/` (see scripts/export_contracts.py), and
Laravel validates the requests it builds and the responses it receives against
those files, so the two sides cannot drift apart without a test failing.

Series arrive already aggregated and zero-filled by Laravel: one value per
period, no gaps, every series ending on the same period. Nothing here is
personal data: a series is a product's units sold per week or month.
"""

from __future__ import annotations

from datetime import date, datetime
from typing import Literal

from pydantic import BaseModel, ConfigDict, Field, model_validator

from app.periods import MAX_HORIZON, Granularity, is_aligned, next_start


class _Strict(BaseModel):
    """Requests reject fields the contract does not define, so a typo is an error, not a silent default."""

    model_config = ConfigDict(extra="forbid")


# --------------------------------------------------------------------------- request


class Period(_Strict):
    start: date = Field(
        description="First day of the period: a Monday for weeks, the 1st for months."
    )
    qty: float = Field(ge=0, description="Units sold in the period (0 when nothing sold).")
    avg_price: float | None = Field(
        default=None,
        ge=0,
        description="Average selling price in the period; null when nothing sold.",
    )


class Series(_Strict):
    series_key: str = Field(
        min_length=1,
        max_length=64,
        description="Opaque identifier of the series, echoed back. Today product:location; "
        "forecasting per branch later changes the key, not the contract.",
    )
    product_id: int = Field(ge=1)
    category: str = Field(min_length=1, max_length=100)
    periods: list[Period] = Field(min_length=1, max_length=1500)


class SeriesRequest(_Strict):
    granularity: Granularity
    series: list[Series] = Field(min_length=1, max_length=5000)

    @model_validator(mode="after")
    def series_are_well_formed(self) -> SeriesRequest:
        keys = [s.series_key for s in self.series]
        if len(set(keys)) != len(keys):
            raise ValueError("series_key must be unique")

        ends: set[date] = set()

        for s in self.series:
            for position, period in enumerate(s.periods):
                if not is_aligned(period.start, self.granularity):
                    raise ValueError(
                        f"{s.series_key}: {period.start} is not the first day of a {self.granularity.value}"
                    )
                if position and period.start != next_start(
                    s.periods[position - 1].start, self.granularity
                ):
                    raise ValueError(
                        f"{s.series_key}: periods must be consecutive and in order "
                        f"(a gap or repeat at {period.start})"
                    )
            ends.add(s.periods[-1].start)

        if len(ends) > 1:
            raise ValueError("every series must end on the same period")

        return self


class ForecastOptions(_Strict):
    backtest_folds: int = Field(
        default=3,
        ge=0,
        le=12,
        description="How many rolling-origin test windows to measure accuracy on. 0 skips the "
        "measurement (the forecast is made all the same).",
    )


class ForecastRequest(SeriesRequest):
    horizon: int = Field(
        ge=1, description="Periods ahead to forecast (weeks: at most 26, months: at most 12)."
    )
    options: ForecastOptions = Field(default_factory=ForecastOptions)

    @model_validator(mode="after")
    def horizon_fits_granularity(self) -> ForecastRequest:
        limit = MAX_HORIZON[self.granularity]
        if self.horizon > limit:
            raise ValueError(f"horizon for {self.granularity.value}s is at most {limit}")
        return self


class BacktestRequest(SeriesRequest):
    horizon: int = Field(ge=1, description="Length of each test window, in periods.")
    folds: int = Field(default=4, ge=1, le=12, description="Number of rolling-origin test windows.")

    @model_validator(mode="after")
    def horizon_fits_granularity(self) -> BacktestRequest:
        limit = MAX_HORIZON[self.granularity]
        if self.horizon > limit:
            raise ValueError(f"horizon for {self.granularity.value}s is at most {limit}")
        return self


# -------------------------------------------------------------------------- response


class Metrics(BaseModel):
    mae: float = Field(description="Mean absolute error, in units.")
    rmse: float = Field(description="Root mean squared error, in units.")
    mape: float | None = Field(
        description="Mean absolute percentage error over periods that had sales; null if none did."
    )
    wape: float | None = Field(
        description="Total absolute error as a percentage of total units sold; null if none were sold."
    )
    n: int = Field(description="Product-periods compared.")
    coverage: float | None = Field(
        default=None,
        description="Percentage of actual values inside the forecast interval (the target is 80). "
        "Null for forecasts without an interval.",
    )


class BaselineMetrics(BaseModel):
    seasonal_naive: Metrics = Field(description="Same period last year.")
    moving_average: Metrics = Field(description="Average of the last few periods.")


class CategoryMetrics(BaseModel):
    model: Metrics
    seasonal_naive: Metrics
    moving_average: Metrics


class FeatureImportance(BaseModel):
    feature: str
    importance: float = Field(description="Share of the model's total gain; all features sum to 1.")


class ForecastPeriod(BaseModel):
    start: date
    yhat: float = Field(ge=0, description="Median (P50) forecast, in units.")
    yhat_lower: float = Field(
        ge=0, description="P10: demand is expected to be at least this 9 times in 10."
    )
    yhat_upper: float = Field(
        ge=0, description="P90: demand is expected to be at most this 9 times in 10."
    )


class SeriesForecast(BaseModel):
    series_key: str
    product_id: int
    method: Literal["xgboost", "moving_average"]
    low_confidence: bool = Field(
        description="True when the product has under a year of history and the forecast is a plain moving average."
    )
    history_periods: int = Field(description="Periods of history the series has.")
    periods: list[ForecastPeriod]


class TrainingInfo(BaseModel):
    first_period: date
    last_period: date = Field(description="Start of the last period of history.")
    n_series: int
    n_model_series: int = Field(description="Series forecast by the model.")
    n_low_confidence: int = Field(description="Series forecast by the moving-average fallback.")
    n_rows: int = Field(
        description="Training rows the final model learned from (0 if none was trained)."
    )
    backtest_folds: int = Field(description="Test windows that were measured.")
    backtest_origins: list[date] = Field(
        description="Last period of history at each test window's origin."
    )


class ForecastResponse(BaseModel):
    model_version: str
    granularity: Granularity
    horizon: int
    generated_at: datetime
    forecasts: list[SeriesForecast]
    metrics: Metrics | None = Field(
        description="The model's accuracy over the test windows; null if not measured."
    )
    baseline_metrics: BaselineMetrics | None
    per_category_metrics: dict[str, CategoryMetrics]
    residual_std: dict[str, float] = Field(
        description="Typical error of one period's forecast, in units, by series_key. "
        "From the test windows where there are enough of them, otherwise from the product's own history."
    )
    feature_importance: list[FeatureImportance]
    training: TrainingInfo


class FoldResult(BaseModel):
    origin: date = Field(description="Start of the last period the model was allowed to see.")
    test_start: date
    test_end: date = Field(description="Start of the last period tested.")
    n_series: int
    metrics: Metrics
    baseline_metrics: BaselineMetrics


class BacktestResponse(BaseModel):
    model_version: str
    granularity: Granularity
    horizon: int
    folds: list[FoldResult]
    metrics: Metrics | None
    baseline_metrics: BaselineMetrics | None
    per_category_metrics: dict[str, CategoryMetrics]
    residual_std: dict[str, float]
    feature_importance: list[FeatureImportance]


class ModelInfo(BaseModel):
    version: str
    granularity: Granularity
    trained_at: datetime
    last_period: date
    n_rows: int
    n_series: int
    features: list[str]
    params: dict[str, float | int | str]
    metrics: Metrics | None

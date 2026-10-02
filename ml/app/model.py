"""The demand model: one gradient-boosted model that predicts three quantiles.

A single XGBoost model is trained with the quantile-error objective for the 10th,
50th and 90th percentiles at once, so each prediction is a median forecast plus
an interval ("demand will be at least P10 nine times in ten, and at most P90
nine times in ten"). The three outputs share their trees' structure per round,
and are sorted afterwards so the interval can never be upside down.
"""

from __future__ import annotations

from dataclasses import dataclass
from pathlib import Path

import numpy as np
import numpy.typing as npt
import pandas as pd
from xgboost import XGBRegressor

from app.config import settings

QUANTILES = (0.1, 0.5, 0.9)

PARAMS: dict[str, float | int | str] = {
    "objective": "reg:quantileerror",
    "tree_method": "hist",
    "n_estimators": 250,
    "learning_rate": 0.05,
    "max_depth": 5,
    "min_child_weight": 3,
    "subsample": 0.8,
    "colsample_bytree": 0.8,
    "reg_lambda": 2.0,
}


@dataclass
class DemandModel:
    regressor: XGBRegressor
    features: list[str]

    def predict(self, X: pd.DataFrame) -> npt.NDArray[np.float64]:
        """Lower, median and upper demand for each row, as multiples of the product's scale (never below 0)."""
        raw = np.asarray(self.regressor.predict(X[self.features]), dtype=float)
        # One-row input comes back flat; the quantiles are the columns.
        raw = raw.reshape(len(X), len(QUANTILES))
        return np.clip(np.sort(raw, axis=1), 0.0, None)

    def importance(self) -> list[tuple[str, float]]:
        """Each feature's share of the model's total gain, largest first."""
        raw = self.regressor.get_booster().get_score(importance_type="total_gain")
        gain = {name: float(np.sum(value)) for name, value in raw.items()}
        total = sum(gain.values())

        if total <= 0:
            return []

        shares = [(name, gain.get(name, 0.0) / total) for name in self.features]

        return sorted((s for s in shares if s[1] > 0), key=lambda item: item[1], reverse=True)

    def save(self, path: Path) -> None:
        self.regressor.save_model(path)

    @classmethod
    def load(cls, path: Path, features: list[str]) -> DemandModel:
        regressor = XGBRegressor()
        regressor.load_model(path)

        return cls(regressor, features)


def train(X: pd.DataFrame, y: npt.NDArray[np.float64], seed: int = 42) -> DemandModel:
    """Fits the model. `y` is demand divided by each product's scale."""
    regressor = XGBRegressor(
        quantile_alpha=np.array(QUANTILES),
        random_state=seed,
        n_jobs=settings.training_threads,
        **PARAMS,
    )
    regressor.fit(X, y)

    return DemandModel(regressor, list(X.columns))

"""Prints how the model compares with the baselines on the synthetic shop.

    docker compose exec ml python -m scripts.accuracy_report week 8 --folds 4

A quick way to see the effect of a change to the features or the model without
going through Laravel. The numbers it prints are the ones the Accuracy page shows.
"""

from __future__ import annotations

import argparse
import tempfile
import time

from app.forecaster import Forecaster
from app.periods import Granularity
from app.registry import Registry
from app.schemas import BacktestRequest
from scripts.synthetic_series import build_request


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("granularity", choices=[g.value for g in Granularity])
    parser.add_argument("horizon", type=int)
    parser.add_argument("--folds", type=int, default=4)
    args = parser.parse_args()

    granularity = Granularity(args.granularity)
    payload = build_request(granularity, args.horizon, folds=args.folds)
    payload.pop("options")
    payload["folds"] = args.folds

    started = time.time()
    with tempfile.TemporaryDirectory() as models:
        report = Forecaster(Registry(models)).backtest(BacktestRequest.model_validate(payload))
    seconds = time.time() - started

    def line(name: str, m) -> str:  # type: ignore[no-untyped-def]
        mape = "-" if m.mape is None else f"{m.mape:6.1f}"
        wape = "-" if m.wape is None else f"{m.wape:6.1f}"
        cover = "" if m.coverage is None else f"  inside P10-P90: {m.coverage:5.1f}%"
        return f"  {name:<16} MAE {m.mae:8.2f}  RMSE {m.rmse:8.2f}  MAPE {mape}  WAPE {wape}  n={m.n}{cover}"

    print(
        f"{granularity.value}ly, horizon {args.horizon}, {len(report.folds)} test windows ({seconds:.1f}s)\n"
    )

    if report.metrics is None or report.baseline_metrics is None:
        print("Not enough history to measure.")
        return

    print(line("model", report.metrics))
    print(line("seasonal naive", report.baseline_metrics.seasonal_naive))
    print(line("moving average", report.baseline_metrics.moving_average))

    print("\nBy category (WAPE %, lower is better):")
    for name, cat in report.per_category_metrics.items():
        print(
            f"  {name:<24} model {cat.model.wape or 0:6.1f}   "
            f"seasonal naive {cat.seasonal_naive.wape or 0:6.1f}   moving avg {cat.moving_average.wape or 0:6.1f}"
        )

    print("\nTop features:")
    for item in report.feature_importance[:8]:
        print(f"  {item.feature:<16} {item.importance * 100:5.1f}%")


if __name__ == "__main__":
    main()

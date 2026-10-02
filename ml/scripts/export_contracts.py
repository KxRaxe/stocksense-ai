"""Writes the JSON Schemas and example payloads that Laravel and this service share.

    docker compose exec ml python -m scripts.export_contracts          # rewrite
    docker compose exec ml python -m scripts.export_contracts --check  # fail if out of date

The schemas come straight from the Pydantic models in app/schemas.py, so the
models are the single definition of the contract. The example request and
response (contracts/fixtures/) let each side test against the other's shape:
the ML tests check this service accepts the example request and that its
answers fit the response schema; the Laravel tests check the requests it builds
and the response it parses against the very same files.

A test fails if the committed files differ from what this script produces.
"""

from __future__ import annotations

import argparse
import json
import os
import sys
import tempfile
from datetime import UTC, datetime
from pathlib import Path
from typing import Any

from pydantic import BaseModel

from app.forecaster import Forecaster
from app.periods import Granularity
from app.registry import Registry
from app.schemas import BacktestRequest, BacktestResponse, ForecastRequest, ForecastResponse
from scripts.generate_synthetic import generate
from scripts.synthetic_series import build_request

SCHEMAS: dict[str, type[BaseModel]] = {
    "forecast-request": ForecastRequest,
    "forecast-response": ForecastResponse,
    "backtest-request": BacktestRequest,
    "backtest-response": BacktestResponse,
}

# Fixed, so regenerating the example response does not rewrite it for no reason.
FIXTURE_TIME = datetime(2026, 10, 1, tzinfo=UTC)
FIXTURE_VERSION = "xgb-week-20261001T000000Z"


def default_directory() -> Path:
    configured = os.environ.get("CONTRACTS_DIR")

    return Path(configured) if configured else Path(__file__).resolve().parents[2] / "contracts"


def render(payload: Any) -> str:
    return json.dumps(payload, indent=2, ensure_ascii=False) + "\n"


def example_request() -> dict[str, Any]:
    """Three weekly series: two the model can handle and one with under a year of history."""
    data = generate()
    full = build_request(Granularity.WEEK, 4, dataset=data, folds=1)
    by_length = sorted(full["series"], key=lambda s: len(s["periods"]))

    short = by_length[0]
    categories: list[str] = []
    long_enough: list[dict[str, Any]] = []

    for series in reversed(by_length):
        if series["category"] not in categories and len(long_enough) < 2:
            categories.append(series["category"])
            long_enough.append(series)

    # Keep two years of the long ones so the example stays small.
    chosen = [*long_enough, short]
    request = {**full, "series": chosen}
    request["series"] = sorted(chosen, key=lambda s: s["product_id"])

    return request


def example_response(request: dict[str, Any]) -> dict[str, Any]:
    with tempfile.TemporaryDirectory() as models:
        registry = Registry(models)
        registry.new_version = lambda granularity, now=None: FIXTURE_VERSION  # type: ignore[method-assign]
        response = Forecaster(registry).forecast(ForecastRequest.model_validate(request))

    response = response.model_copy(update={"generated_at": FIXTURE_TIME})

    return json.loads(response.model_dump_json())


def files() -> dict[str, str]:
    """Every file this script owns, by path relative to the contracts directory."""
    out = {
        f"{name}.schema.json": render(model.model_json_schema()) for name, model in SCHEMAS.items()
    }

    request = example_request()
    out["fixtures/forecast-request.json"] = render(request)
    out["fixtures/forecast-response.json"] = render(example_response(request))

    return out


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument(
        "--check", action="store_true", help="exit with an error if a file is out of date"
    )
    parser.add_argument("--out", type=Path, default=default_directory())
    args = parser.parse_args()

    stale = []

    for relative, content in files().items():
        path = args.out / relative

        if args.check:
            if not path.exists() or path.read_bytes().decode("utf-8") != content:
                stale.append(relative)

            continue

        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_bytes(content.encode("utf-8"))
        print(f"wrote {path}")

    if stale:
        print(
            "Out of date: " + ", ".join(stale) + "\nRun: python -m scripts.export_contracts",
            file=sys.stderr,
        )
        return 1

    return 0


if __name__ == "__main__":
    raise SystemExit(main())

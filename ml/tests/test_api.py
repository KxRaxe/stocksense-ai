"""The HTTP surface: who may call it, what it validates, and that its answers fit the contract."""

from __future__ import annotations

import json
from pathlib import Path

import pytest
from fastapi.testclient import TestClient
from jsonschema import Draft202012Validator

from app.config import settings
from app.main import app, registry
from app.periods import Granularity
from app.registry import Registry
from tests.conftest import CONTRACTS, make_request

TOKEN = {"X-Internal-Token": settings.internal_token}


@pytest.fixture
def client(tmp_path: Path):
    app.dependency_overrides[registry] = lambda: Registry(tmp_path / "models")
    yield TestClient(app)
    app.dependency_overrides.clear()


def schema(name: str) -> Draft202012Validator:
    return Draft202012Validator(
        json.loads((CONTRACTS / f"{name}.schema.json").read_text(encoding="utf-8"))
    )


def forecast_payload(**kwargs):
    return make_request(lengths=(110, 110, 110, 30), horizon=4, folds=1, **kwargs)


class TestAccess:
    def test_health_is_open(self, client):
        assert client.get("/health").json()["status"] == "ok"

    @pytest.mark.parametrize(
        ("method", "path"), [("post", "/forecast"), ("post", "/backtest"), ("get", "/models")]
    )
    def test_the_other_endpoints_need_the_internal_token(self, client, method, path):
        options = {"json": forecast_payload()} if method == "post" else {}
        response = getattr(client, method)(path, **options)

        assert response.status_code == 401

    def test_a_wrong_token_is_refused(self, client):
        response = client.post(
            "/forecast", json=forecast_payload(), headers={"X-Internal-Token": "guess"}
        )

        assert response.status_code == 401
        assert "token" in response.json()["detail"].lower()

    def test_the_token_is_checked_before_the_request_is_looked_at(self, client):
        # Without the token nothing about the payload is revealed, not even that it is malformed.
        assert client.post("/forecast", json={"nonsense": True}).status_code == 401


class TestForecast:
    def test_answers_in_the_contracts_shape(self, client):
        response = client.post("/forecast", json=forecast_payload(), headers=TOKEN)

        assert response.status_code == 200
        errors = list(schema("forecast-response").iter_errors(response.json()))
        assert errors == []

    def test_answers_with_what_was_asked(self, client):
        body = client.post("/forecast", json=forecast_payload(), headers=TOKEN).json()

        assert body["granularity"] == "week"
        assert body["horizon"] == 4
        assert [f["method"] for f in body["forecasts"]] == [
            "xgboost",
            "xgboost",
            "xgboost",
            "moving_average",
        ]
        assert all(len(f["periods"]) == 4 for f in body["forecasts"])

    def test_the_request_it_accepts_fits_the_request_schema(self, client):
        assert list(schema("forecast-request").iter_errors(forecast_payload())) == []

    def test_refuses_a_malformed_request_and_says_why(self, client):
        payload = forecast_payload()
        del payload["series"][0]["periods"][7]

        response = client.post("/forecast", json=payload, headers=TOKEN)

        assert response.status_code == 422
        assert "consecutive" in json.dumps(response.json())

    def test_refuses_a_horizon_that_is_too_long(self, client):
        payload = forecast_payload()
        payload["horizon"] = 40

        response = client.post("/forecast", json=payload, headers=TOKEN)

        assert response.status_code == 422
        assert "at most 26" in json.dumps(response.json())

    def test_refuses_an_unknown_granularity(self, client):
        payload = forecast_payload()
        payload["granularity"] = "day"

        assert client.post("/forecast", json=payload, headers=TOKEN).status_code == 422

    def test_works_for_months(self, client):
        payload = make_request(granularity=Granularity.MONTH, lengths=(40,) * 5, horizon=3, folds=1)

        response = client.post("/forecast", json=payload, headers=TOKEN)

        assert response.status_code == 200
        assert response.json()["granularity"] == "month"


class TestBacktest:
    def test_answers_in_the_contracts_shape(self, client):
        payload = make_request(lengths=(110, 110, 110, 110), horizon=4)
        payload.pop("options")
        payload["folds"] = 2

        response = client.post("/backtest", json=payload, headers=TOKEN)

        assert response.status_code == 200
        assert list(schema("backtest-response").iter_errors(response.json())) == []
        assert len(response.json()["folds"]) == 2

    def test_a_request_without_folds_gets_the_default(self, client):
        payload = make_request(lengths=(110, 110, 110, 110), horizon=4)
        payload.pop("options")

        response = client.post("/backtest", json=payload, headers=TOKEN)

        assert response.status_code == 200
        assert len(response.json()["folds"]) == 4


class TestModels:
    def test_lists_the_models_a_forecast_saved(self, client):
        assert client.get("/models", headers=TOKEN).json() == []

        version = client.post("/forecast", json=forecast_payload(), headers=TOKEN).json()[
            "model_version"
        ]
        listed = client.get("/models", headers=TOKEN).json()

        assert [m["version"] for m in listed] == [version]
        assert listed[0]["granularity"] == "week"

    def test_can_be_narrowed_to_one_granularity(self, client):
        client.post("/forecast", json=forecast_payload(), headers=TOKEN)

        assert client.get("/models?granularity=month", headers=TOKEN).json() == []
        assert len(client.get("/models?granularity=week", headers=TOKEN).json()) == 1

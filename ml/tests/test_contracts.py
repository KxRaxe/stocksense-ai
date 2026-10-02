"""The contract files shared with Laravel: current, valid, and satisfied by this service.

The schemas and examples in `contracts/` are what both sides test against. If a
model changes without the files being regenerated, or an example stops
matching its schema, these fail.
"""

from __future__ import annotations

import json

import pytest
from jsonschema import Draft202012Validator

from app.schemas import ForecastRequest, ForecastResponse
from scripts.export_contracts import SCHEMAS, files
from tests.conftest import CONTRACTS


def load(relative: str):
    return json.loads((CONTRACTS / relative).read_text(encoding="utf-8"))


@pytest.mark.parametrize("name", sorted(SCHEMAS))
def test_the_schemas_are_valid_json_schema(name):
    Draft202012Validator.check_schema(load(f"{name}.schema.json"))


@pytest.mark.parametrize("name", sorted(SCHEMAS))
def test_the_committed_schemas_match_the_models(name):
    expected = json.dumps(SCHEMAS[name].model_json_schema(), indent=2, ensure_ascii=False) + "\n"

    assert (CONTRACTS / f"{name}.schema.json").read_bytes().decode("utf-8") == expected, (
        "Out of date. Run: docker compose exec ml python -m scripts.export_contracts"
    )


def test_the_example_request_fits_its_schema_and_is_accepted():
    example = load("fixtures/forecast-request.json")

    assert (
        list(Draft202012Validator(load("forecast-request.schema.json")).iter_errors(example)) == []
    )
    assert ForecastRequest.model_validate(example).horizon == example["horizon"]


def test_the_example_response_fits_its_schema_and_parses():
    example = load("fixtures/forecast-response.json")

    assert (
        list(Draft202012Validator(load("forecast-response.schema.json")).iter_errors(example)) == []
    )
    assert ForecastResponse.model_validate(example).model_version == example["model_version"]


def test_the_example_shows_both_ways_a_product_can_be_forecast():
    methods = {f["method"] for f in load("fixtures/forecast-response.json")["forecasts"]}

    assert methods == {"xgboost", "moving_average"}


def test_the_example_response_answers_the_example_request():
    request = load("fixtures/forecast-request.json")
    response = load("fixtures/forecast-response.json")

    assert [s["series_key"] for s in request["series"]] == [
        f["series_key"] for f in response["forecasts"]
    ]
    assert response["horizon"] == request["horizon"]
    assert response["granularity"] == request["granularity"]


def test_every_contract_file_is_current():
    """Regenerates everything (including the example response) and compares with what is committed."""
    stale = [
        relative
        for relative, content in files().items()
        # The example response depends on library versions down to the last decimal, so it is
        # checked above for shape rather than byte for byte.
        if not relative.endswith("forecast-response.json")
        and (CONTRACTS / relative).read_bytes().decode("utf-8") != content
    ]

    assert stale == [], (
        "Out of date: " + ", ".join(stale) + ". Run: python -m scripts.export_contracts"
    )


def test_the_schema_rejects_what_the_service_would_reject_for_shape():
    validator = Draft202012Validator(load("forecast-request.schema.json"))
    bad = load("fixtures/forecast-request.json")
    bad["horizon"] = "four"
    bad["extra"] = 1
    bad["series"][0]["periods"][0]["qty"] = -5

    messages = " ".join(error.message for error in validator.iter_errors(bad))

    assert "four" in messages
    assert "extra" in messages
    assert "-5" in messages

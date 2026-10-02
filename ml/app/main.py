import hmac
from functools import lru_cache
from typing import Annotated

from fastapi import Depends, FastAPI, Header, HTTPException

from app.config import settings
from app.forecaster import Forecaster
from app.periods import Granularity
from app.registry import Registry
from app.schemas import (
    BacktestRequest,
    BacktestResponse,
    ForecastRequest,
    ForecastResponse,
    ModelInfo,
)

app = FastAPI(title="StockSense ML", version=settings.service_version)


def require_token(x_internal_token: str | None = Header(default=None)) -> None:
    """Only the Laravel app may call the forecasting endpoints; it presents a shared secret."""
    if x_internal_token is None or not hmac.compare_digest(
        x_internal_token, settings.internal_token
    ):
        raise HTTPException(status_code=401, detail="Missing or invalid internal token.")


@lru_cache
def registry() -> Registry:
    return Registry(settings.models_dir)


SavedModels = Annotated[Registry, Depends(registry)]


def forecaster(models: SavedModels) -> Forecaster:
    return Forecaster(models)


Engine = Annotated[Forecaster, Depends(forecaster)]


@app.get("/health")
def health() -> dict[str, str]:
    return {"status": "ok", "version": settings.service_version}


# These are plain `def` handlers, not `async`: training is CPU-bound, so FastAPI
# runs them in a worker thread and the service keeps answering /health meanwhile.


@app.post("/forecast", response_model=ForecastResponse, dependencies=[Depends(require_token)])
def forecast(request: ForecastRequest, engine: Engine) -> ForecastResponse:
    return engine.forecast(request)


@app.post("/backtest", response_model=BacktestResponse, dependencies=[Depends(require_token)])
def backtest(request: BacktestRequest, engine: Engine) -> BacktestResponse:
    return engine.backtest(request)


@app.get("/models", response_model=list[ModelInfo], dependencies=[Depends(require_token)])
def models(saved: SavedModels, granularity: Granularity | None = None) -> list[ModelInfo]:
    return saved.list(granularity)

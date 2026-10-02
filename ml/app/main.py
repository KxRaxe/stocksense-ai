from fastapi import FastAPI

from app.config import settings

app = FastAPI(title="StockSense ML", version=settings.service_version)


@app.get("/health")
def health() -> dict[str, str]:
    return {"status": "ok", "version": settings.service_version}

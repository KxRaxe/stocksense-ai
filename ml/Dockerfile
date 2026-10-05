# syntax=docker/dockerfile:1.7
FROM python:3.12-slim AS base

COPY --from=ghcr.io/astral-sh/uv:latest /uv /usr/local/bin/uv

ENV UV_COMPILE_BYTECODE=1 \
    UV_LINK_MODE=copy \
    UV_PROJECT_ENVIRONMENT=/opt/venv \
    PATH="/opt/venv/bin:$PATH" \
    PYTHONUNBUFFERED=1

RUN apt-get update && apt-get install -y --no-install-recommends curl libgomp1 \
 && rm -rf /var/lib/apt/lists/*

WORKDIR /srv

# ---------------------------------------------------------------------------
# prod: only what the service needs to run, from the lockfile, as an unprivileged user,
# and it refuses to start with the development token (see app/config.py).
# ---------------------------------------------------------------------------
FROM base AS prod
COPY pyproject.toml uv.lock ./
RUN uv sync --frozen --no-install-project --no-dev
COPY app ./app
RUN useradd --system --uid 10001 --no-create-home ml \
 && mkdir -p /models && chown ml /models
USER ml
ENV ML_MODELS_DIR=/models \
    ML_ENVIRONMENT=production
EXPOSE 8000
HEALTHCHECK --interval=10s --timeout=3s --retries=5 \
  CMD curl -fsS http://localhost:8000/health || exit 1
CMD ["uvicorn", "app.main:app", "--host", "0.0.0.0", "--port", "8000"]

# ---------------------------------------------------------------------------
# dev: the default target. Adds the test tools and the tests.
# ---------------------------------------------------------------------------
FROM base AS dev

# Dependencies first for layer caching
COPY pyproject.toml ./
RUN uv sync --no-install-project --group dev

COPY app ./app
COPY tests ./tests

ENV ML_MODELS_DIR=/models
RUN mkdir -p /models

EXPOSE 8000
HEALTHCHECK --interval=10s --timeout=3s --retries=5 \
  CMD curl -fsS http://localhost:8000/health || exit 1

CMD ["uvicorn", "app.main:app", "--host", "0.0.0.0", "--port", "8000"]

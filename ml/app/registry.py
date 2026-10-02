"""Where trained models are kept.

Each forecast run saves the model it trained, with a small JSON file next to it
saying what it was trained on and how accurate it measured. The version string
is recorded with every forecast Laravel stores, so any number can be traced to
the model that produced it. Only the most recent few are kept.
"""

from __future__ import annotations

import json
from datetime import UTC, datetime
from pathlib import Path

from app.model import PARAMS, DemandModel
from app.periods import Granularity
from app.schemas import Metrics, ModelInfo

KEEP = 5


class Registry:
    def __init__(self, root: str | Path) -> None:
        self.root = Path(root)

    def new_version(self, granularity: Granularity, now: datetime | None = None) -> str:
        stamp = (now or datetime.now(UTC)).strftime("%Y%m%dT%H%M%SZ")

        return f"xgb-{granularity.value}-{stamp}"

    def save(
        self,
        model: DemandModel,
        *,
        version: str,
        granularity: Granularity,
        last_period: str,
        n_rows: int,
        n_series: int,
        metrics: Metrics | None,
    ) -> ModelInfo:
        folder = self.root / granularity.value
        folder.mkdir(parents=True, exist_ok=True)

        info = ModelInfo(
            version=version,
            granularity=granularity,
            trained_at=datetime.now(UTC),
            last_period=datetime.fromisoformat(last_period).date(),
            n_rows=n_rows,
            n_series=n_series,
            features=model.features,
            params=dict(PARAMS),
            metrics=metrics,
        )

        model.save(folder / f"{version}.ubj")
        (folder / f"{version}.json").write_text(info.model_dump_json(indent=2), encoding="utf-8")

        self._prune(folder)

        return info

    def list(self, granularity: Granularity | None = None) -> list[ModelInfo]:
        """Saved models, newest first."""
        folders = [self.root / granularity.value] if granularity else sorted(self.root.glob("*"))
        found: list[ModelInfo] = []

        for folder in folders:
            for path in folder.glob("*.json") if folder.is_dir() else []:
                found.append(ModelInfo.model_validate(json.loads(path.read_text(encoding="utf-8"))))

        return sorted(found, key=lambda info: info.trained_at, reverse=True)

    def load(self, granularity: Granularity, version: str) -> DemandModel:
        folder = self.root / granularity.value
        info = ModelInfo.model_validate(
            json.loads((folder / f"{version}.json").read_text(encoding="utf-8"))
        )

        return DemandModel.load(folder / f"{version}.ubj", info.features)

    def _prune(self, folder: Path) -> None:
        saved = sorted(
            folder.glob("*.json"),
            key=lambda path: (path.stat().st_mtime_ns, path.name),
            reverse=True,
        )

        for old in saved[KEEP:]:
            old.unlink(missing_ok=True)
            old.with_suffix(".ubj").unlink(missing_ok=True)

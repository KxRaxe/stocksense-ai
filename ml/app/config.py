import os

from pydantic_settings import BaseSettings, SettingsConfigDict


class Settings(BaseSettings):
    model_config = SettingsConfigDict(env_prefix="ML_")

    internal_token: str = "change-me-dev-token"
    models_dir: str = "/models"
    service_version: str = "0.1.0"
    # Threads XGBoost may use. Training sets are small (thousands of rows), so more
    # threads add coordination cost without speeding anything up, and on a busy
    # machine they make it dramatically slower. 0 means a modest default.
    threads: int = 0

    @property
    def training_threads(self) -> int:
        return self.threads if self.threads > 0 else min(os.cpu_count() or 1, 4)


settings = Settings()

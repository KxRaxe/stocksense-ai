import os

from pydantic import model_validator
from pydantic_settings import BaseSettings, SettingsConfigDict

# What the token is when nobody has set it. It is in the compose file and the README, so it
# is public knowledge: anyone who can reach the service could use it.
DEFAULT_TOKEN = "change-me-dev-token"
MIN_PRODUCTION_TOKEN_LENGTH = 24


class Settings(BaseSettings):
    model_config = SettingsConfigDict(env_prefix="ML_")

    # "production" makes the service refuse to start with the development token.
    environment: str = "development"
    internal_token: str = DEFAULT_TOKEN
    models_dir: str = "/models"
    service_version: str = "0.1.0"
    # Threads XGBoost may use. Training sets are small (thousands of rows), so more
    # threads add coordination cost without speeding anything up, and on a busy
    # machine they make it dramatically slower. 0 means a modest default.
    threads: int = 0

    @model_validator(mode="after")
    def refuse_the_development_token_in_production(self) -> "Settings":
        if self.environment == "production" and (
            self.internal_token == DEFAULT_TOKEN
            or len(self.internal_token) < MIN_PRODUCTION_TOKEN_LENGTH
        ):
            raise ValueError(
                "ML_INTERNAL_TOKEN must be set to a long random value "
                f"(at least {MIN_PRODUCTION_TOKEN_LENGTH} characters) in production. "
                "It is the only thing standing between the network and the forecasting endpoints."
            )

        return self

    @property
    def training_threads(self) -> int:
        return self.threads if self.threads > 0 else min(os.cpu_count() or 1, 4)


settings = Settings()

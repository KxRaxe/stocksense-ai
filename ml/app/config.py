from pydantic_settings import BaseSettings, SettingsConfigDict


class Settings(BaseSettings):
    model_config = SettingsConfigDict(env_prefix="ML_")

    internal_token: str = "change-me-dev-token"
    models_dir: str = "/models"
    service_version: str = "0.1.0"


settings = Settings()

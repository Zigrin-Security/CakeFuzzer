from pathlib import Path

from pydantic import BaseSettings


class WebrootSettings(BaseSettings):
    webroot_dir: Path
    storage_path: Path = Path("databases")
    concurrent_queues: int = 10
    iterations: int = 32
    only_paths_with_prefix: str = "/"
    exclude_paths: str = ""
    payload_guid_phrase = "§CAKEFUZZER_PAYLOAD_GUID§"
    instrumentation_ini = Path("config/instrumentation.ini")

    class Config:
        env_file = "config/config.ini"
        env_file_encoding = "utf-8"

    @property
    def index_php(self) -> Path:
        index_php = (
            self.webroot_dir
            if self.webroot_dir.suffix == ".php"
            else self.webroot_dir / "index.php"
        )

        if index_php.exists() and index_php.is_file():
            return index_php

        raise ValueError(
            f"File {index_php} does not exist or is not a file. "
            "Provide a correct path to your application's webroot directory"
        )

    @property
    def scenarios_queue_path(self) -> Path:
        return self.storage_path / "scenarios.queue"

    @property
    def results_queue_path(self) -> Path:
        return self.storage_path / "iteration_results.queue"

    @property
    def vulnerabilities_queue_path(self) -> Path:
        return self.storage_path / "vulnerabilities.queue"

    @property
    def monitors_db_path(self) -> Path:
        return self.storage_path / "monitors.db"

    @property
    def registry_db_path(self) -> Path:
        return self.storage_path / "registry.db"

    @property
    def results_db_path(self) -> Path:
        return self.storage_path / "iteration_results.db"

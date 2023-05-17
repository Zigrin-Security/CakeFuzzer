import asyncio
from pathlib import Path

from pydantic import BaseModel

from cakefuzzer.instrumentation import InstrumentationError


async def install_php_parser() -> None:
    if Path("php-parser").exists():
        return

    command = ["bash", "cakefuzzer/phpfiles/instrumentation/install_php_parser.sh"]

    proc = await asyncio.create_subprocess_exec(command)
    if proc.returncode != 0:
        raise InstrumentationError(
            error="Error while installing php-parser, got non-zero response from subprocess",
            hint=" ".join(command),
        )

class RemoveAnnotationsInstrumentation(BaseModel):
    path: Path

    async def is_applied(self) -> bool:
        """Not really possible to check if this is applied"""

        return False

    async def apply(self) -> None:

        await install_php_parser()

        command = [
            "php",
            "cakefuzzer/phpfiles/instrumentation/remove_annotations.php",
            str(self.path),
        ]

        proc = await asyncio.create_subprocess_exec(command)
        if proc.returncode != 0:
            raise InstrumentationError(
                error="Error while instrumenting, got non-zero response from subprocess",
                hint=" ".join(command),
            )

    async def revert(self) -> None:
        for backup_file in self.path.rglob("*.preannotation"):
            backup_file.rename(backup_file.with_suffix(""))

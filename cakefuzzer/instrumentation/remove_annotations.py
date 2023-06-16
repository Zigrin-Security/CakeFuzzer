import asyncio
from pathlib import Path

from pydantic import BaseModel

from cakefuzzer.instrumentation import InstrumentationError


async def install_php_parser(lock: asyncio.Semaphore) -> None:
    # We only want to install php-parser once so we use a semaphore to make sure
    # that only one process can install it at a time
    async with lock:
        if Path("php-parser").exists():
            return

        command = ["bash", "cakefuzzer/phpfiles/instrumentation/install_php_parser.sh"]

        proc = await asyncio.create_subprocess_exec(*command)
        await proc.wait()
        if proc.returncode != 0:
            raise InstrumentationError(
                error="Error while installing php-parser, got non-zero response from subprocess",
                hint=" ".join(command),
            )


class RemoveAnnotationsInstrumentation(BaseModel):
    path: Path

    async def is_applied(self, lock: asyncio.Semaphore) -> bool:
        """Not really possible to check if this is applied"""

        return False

    async def apply(self, lock: asyncio.Semaphore) -> None:
        await install_php_parser(lock)

        async with lock:
            command = [
                "php",
                "cakefuzzer/phpfiles/instrumentation/remove_annotations.php",
                str(self.path),
            ]

            proc = await asyncio.create_subprocess_exec(*command)
            await proc.wait()
            if proc.returncode != 0:
                raise InstrumentationError(
                    error="Error while instrumenting, got non-zero response from subprocess",
                    hint=" ".join(command),
                )

    async def revert(self, lock: asyncio.Semaphore) -> None:
        for backup_file in self.path.rglob("*.preannotation"):
            backup_file.rename(backup_file.with_suffix(""))

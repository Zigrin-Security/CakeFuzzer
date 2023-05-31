import asyncio
from dataclasses import dataclass
from pathlib import Path

from pydantic import BaseModel

from cakefuzzer.instrumentation.remove_annotations import install_php_parser
from cakefuzzer.instrumentation import InstrumentationError



class FunctionCallRenameInstrumentation(BaseModel):
    path: Path
    rename_from: str
    rename_to: str

    async def is_applied(self) -> bool:
        """Not really possible to check if this is applied"""

        return False

    async def apply(self) -> None:

        await install_php_parser()

        command = [
            "php",
            "cakefuzzer/phpfiles/instrumentation/rename_function_call.php",
            str(self.path),
            self.rename_from,
            self.rename_to,
        ]

        proc = await asyncio.create_subprocess_exec(*command)
        await proc.wait()
        if proc.returncode != 0:
            print(proc.returncode)
            print("err", proc.stderr)
            print("out", proc.stdout)
            raise InstrumentationError(
                error="Error while instrumenting, got non-zero response from subprocess",
                hint=" ".join(command),
            )

    async def revert(self) -> None:
        for backup_file in self.path.rglob("*.prerename"):
            backup_file.rename(backup_file.with_suffix(""))

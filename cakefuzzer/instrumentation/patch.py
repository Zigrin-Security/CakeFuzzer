import asyncio
from pathlib import Path

from pydantic import BaseModel

from cakefuzzer.instrumentation import InstrumentationError


async def _run_subprocess(*args: str) -> None:
    proc = await asyncio.create_subprocess_exec(
        *args,
        stdin=asyncio.subprocess.PIPE,
        stdout=asyncio.subprocess.PIPE,
        stderr=asyncio.subprocess.PIPE,
    )
    await proc.wait()

    if proc.returncode != 0:
        raise InstrumentationError(
            error="Error while instrumenting, got non-zero response from subprocess",
            hint=" ".join(str(s) for s in args),
        )


class PatchInstrumentation(BaseModel):
    patch: Path
    original: Path

    async def is_applied(self, lock: asyncio.Semaphore) -> bool:
        try:
            await _run_subprocess(
                "patch",
                "--reverse",
                "-p0",
                "--silent",
                "--dry-run",
                str(self.original),
                str(self.patch),
            )
            return True
        except InstrumentationError:
            return False

    async def apply(self, lock: asyncio.Semaphore) -> None:
        await _run_subprocess("patch", str(self.original), str(self.patch))

    async def revert(self, lock: asyncio.Semaphore) -> None:
        await _run_subprocess("patch", "--reverse", str(self.original), str(self.patch))

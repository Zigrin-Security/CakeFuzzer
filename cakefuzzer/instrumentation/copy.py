import asyncio
import os
import shutil
from pathlib import Path
from pydantic import BaseModel


class CopyInstrumentation(BaseModel):
    src: Path
    dst: Path

    async def is_applied(self, lock: asyncio.Semaphore) -> bool:
        return self.dst.exists()

    async def apply(self, lock: asyncio.Semaphore) -> None:
        os.makedirs(self.dst.parent, exist_ok=True)
        shutil.copy(self.src, self.dst)

    async def revert(self, lock: asyncio.Semaphore) -> None:
        self.dst.unlink()

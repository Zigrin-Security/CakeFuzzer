import os
import shutil
from pathlib import Path

from pydantic import BaseModel


class CopyInstrumentation(BaseModel):
    src: Path
    dst: Path

    async def is_applied(self) -> bool:
        return self.dst.exists()

    async def apply(self) -> None:
        os.makedirs(self.dst.parent, exist_ok=True)
        shutil.copy(self.src, self.dst)

    async def revert(self) -> None:
        self.dst.unlink()

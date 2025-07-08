import asyncio
from pathlib import Path
from typing import List

import aioshutil
from pydantic import BaseModel

from cakefuzzer.instrumentation.patch import _run_subprocess


class IniEntry(BaseModel):
    # section: str = None # TODO: Implement
    setting_name: str
    setting_value: str
    # mode: int # TODO if needed: 1 - prepend, 2 - append, 3 - overwrite


class IniUpdateInstrumentation(BaseModel):
    ini_file_name: Path
    rules: List[IniEntry]

    async def is_applied(self, lock: asyncio.Semaphore) -> bool:
        path = Path(str(self.ini_file_name) + ".bak")
        return path.is_file()

    async def apply(self, lock: asyncio.Semaphore) -> None:
        if self.rules:
            commands = ["sed", "-i.bak"]
            for rule in self.rules:
                commands.append("-e")
                commands.append(
                    r"s@;*\s*"
                    + rule.setting_name
                    + r"\s*=\s*.*$@"
                    + rule.setting_name
                    + " = "
                    + rule.setting_value
                    + "@"
                )
            commands.append(str(self.ini_file_name))
            await _run_subprocess(*commands)

    async def revert(self, lock: asyncio.Semaphore) -> None:
        await aioshutil.move(str(self.ini_file_name) + ".bak", self.ini_file_name)

    # async def make_backup(self):
    #     await aioshutil.copyfile(self.ini_file_name, self.ini_file_name + '.bak')

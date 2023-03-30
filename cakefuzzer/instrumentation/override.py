import asyncio
import re
from dataclasses import dataclass
from pathlib import Path

import aiofiles


def _sub_fname(fname: str, new_fname: str, string: str) -> str:
    # Most of magic happens here!
    regex_from = f"([^a-zA-Z0-9_:>]|^)({fname}\\()(.*);(.*)"
    regex_to = f"\\1{new_fname}(\\3;\\4"

    return re.sub(regex_from, regex_to, string)


async def contains_any(
    filename: Path, *args: str, semaphore: asyncio.Semaphore
) -> bool:
    async with semaphore:
        async with aiofiles.open(filename, "r+") as f:
            contents = await f.read()

            for arg in args:
                if re.search(f"([^a-zA-Z0-9_:>]|^)({arg}\\()(.*);(.*)", contents):
                    return True

            return False


async def _override_fname(
    filename: Path,
    fname: str,
    new_fname: str,
    semaphore: asyncio.Semaphore,
    dry_run: bool = False,
) -> bool:
    async with semaphore:
        async with aiofiles.open(filename, "r+") as f:
            contents = await f.read()
            new_contents = _sub_fname(
                fname=fname,
                new_fname=new_fname,
                string=contents,
            )

            if not dry_run and contents != new_contents:
                await f.seek(0, 0)
                await f.write(new_contents)
                await f.truncate()

            # Return if the override happened
            return contents != new_contents


@dataclass
class FileFunctionExecutionOverrideInstrumentation:
    filename: Path
    function_name: str
    new_function_name: str
    semaphore: asyncio.Semaphore

    async def is_applied(self) -> bool:
        # Override is applied if reverting makes any difference
        differs = await _override_fname(
            filename=self.filename,
            fname=self.new_function_name,
            new_fname=self.function_name,
            semaphore=self.semaphore,
            dry_run=True,
        )
        return differs

    async def apply(self) -> None:
        await _override_fname(
            filename=self.filename,
            fname=self.function_name,
            new_fname=self.new_function_name,
            semaphore=self.semaphore,
        )

    async def revert(self) -> None:
        await _override_fname(
            filename=self.filename,
            fname=self.new_function_name,
            new_fname=self.function_name,
            semaphore=self.semaphore,
        )

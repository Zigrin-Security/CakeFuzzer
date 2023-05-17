import asyncio
import glob
import os
from pathlib import Path
from typing import List

from pydantic import BaseModel, BaseSettings

from cakefuzzer.instrumentation.copy import CopyInstrumentation
from cakefuzzer.instrumentation.override import (
    FileFunctionExecutionOverrideInstrumentation,
    contains_any,
)
from cakefuzzer.instrumentation.patch import PatchInstrumentation


class GlobFunctionOverrideInstrumentation(BaseModel):
    glob: str
    function_name: str
    new_function_name: str

    async def files(
        self, concurrent_limit: int
    ) -> List[FileFunctionExecutionOverrideInstrumentation]:
        semaphore = asyncio.Semaphore(concurrent_limit)

        files = [
            FileFunctionExecutionOverrideInstrumentation(
                filename=Path(pathname),
                function_name=self.function_name,
                new_function_name=self.new_function_name,
                semaphore=semaphore,
            )
            for pathname in glob.glob(self.glob, recursive=True) if os.path.isfile(pathname)
        ]

        contains = await asyncio.gather(
            *[
                contains_any(
                    f.filename,
                    f.function_name,
                    f.new_function_name,
                    semaphore=semaphore,
                )
                for f in files
            ]
        )
        return [f for f, does_contain in zip(files, contains) if does_contain]


class InstrumentationSettings(BaseSettings):
    class Config:
        env_file = os.getenv("INSTRUMENTATION_INI", "config/instrumentation.ini")
        env_file_encoding = "utf-8"

    patch_dir: Path
    patches: List[PatchInstrumentation] = []
    copies: List[CopyInstrumentation] = []
    overrides: List[GlobFunctionOverrideInstrumentation] = []
    concurrent_limit: int = 100

    @property
    async def file_overrides(
        self,
    ) -> List[FileFunctionExecutionOverrideInstrumentation]:
        overrides = []
        for o in self.overrides:
            overrides.extend(await o.files(self.concurrent_limit))

        return overrides

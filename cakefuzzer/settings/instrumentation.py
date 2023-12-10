import os
from pathlib import Path
from typing import List

from pydantic import BaseSettings

from cakefuzzer.instrumentation.copy import CopyInstrumentation
from cakefuzzer.instrumentation.ini_update import IniEntry
from cakefuzzer.instrumentation.override import FunctionCallRenameInstrumentation
from cakefuzzer.instrumentation.patch import PatchInstrumentation
from cakefuzzer.instrumentation.remove_annotations import (
    RemoveAnnotationsInstrumentation,
)


class InstrumentationSettings(BaseSettings):
    class Config:
        env_file = os.getenv("INSTRUMENTATION_INI", "config/instrumentation.ini")
        env_file_encoding = "utf-8"

    patch_dir: Path
    patches: List[PatchInstrumentation] = []
    copies: List[CopyInstrumentation] = []
    fcall_renames: List[FunctionCallRenameInstrumentation] = []
    remove_annotations: List[RemoveAnnotationsInstrumentation] = []
    php_ini_rules: List[IniEntry] = []
    concurrent_limit: int = 100

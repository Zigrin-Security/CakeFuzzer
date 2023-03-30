import os
from dataclasses import dataclass
from enum import Enum
from pathlib import Path
from time import time
from typing import List

from cakefuzzer.domain.components import Fuzzer
from cakefuzzer.scanners import UnboundedScanner


class FileAccessOps(Enum):
    access = "access"
    modify = "modify"
    change = "change"
    create = "create"
    delete = "delete"


@dataclass
class FileAccessStat:
    """
    Return file access timestamps in miliseconds.
    access - any access to file,
    modify - write operation,
    change - chmod operation,
    create - time of discovery of file creation.
    delete - time of discovery of file deletion.
    """

    access: float = -1.0
    modify: float = -1.0
    change: float = -1.0
    create: float = -1.0
    delete: float = -1.0

    @property
    def exists(self) -> bool:
        return self.access > 0 and self.modify > 0 and self.change > 0


class FileAccessScanner(UnboundedScanner):
    """
    When initializing, we should specify filename path.
    Optionally, we can specify watched operations (e.g. we only want to watch for access
    and modification of a file) by calling FileAccessScanner(filename="/path/to/file",
    watched_ops=[FileAccessOps.access, FileAccessOps.modify]).
    """

    filename: Path
    watched_ops: List[FileAccessOps] = [
        FileAccessOps.access,
        FileAccessOps.modify,
        FileAccessOps.change,
        FileAccessOps.create,
        FileAccessOps.delete,
    ]
    last_state: FileAccessStat = FileAccessStat()
    initial_read: bool = True

    async def _check_if_changed(self, other: FileAccessStat) -> bool:
        return all(
            [
                True
                if self.last_state.__getattribute__(operation.name)
                == other.__getattribute__(operation.name)
                else False
                for operation in self.watched_ops
            ]
        )

    async def _get_timestamp(self) -> FileAccessStat:
        """
        Handle timestamps for both existing and not existing files.
        """
        try:
            os_stats = os.stat(self.filename)
            stat = FileAccessStat(
                access=os_stats.st_atime,
                modify=os_stats.st_mtime,
                change=os_stats.st_ctime,
            )
            if not self.initial_read:
                stat.create = time()

            return stat

        except FileNotFoundError:
            # in case a file is deleted, we will capture it here, mark accordingly
            # and report it with timestamp of discovery.
            if self.last_state.exists:
                return FileAccessStat(
                    self.last_state.access,
                    self.last_state.modify,
                    self.last_state.change,
                    delete=time(),
                )
            else:
                return self.last_state

    async def scan(self, fuzzer: Fuzzer) -> None:
        current_state = await self._get_timestamp()
        # without this, every scan that is run for the first time, would look like
        # the file was just created. Linux does not provide an API to get
        # the birthtime of file.

        self.initial_read = False
        if self._check_if_changed(current_state):
            self.last_state = current_state
        # before using this scanner, we have to create a proper vulnerability here
        raise NotImplementedError(
            "Before using this scanner you must implement its vulnerability report"
        )
        # await fuzzer.registry.add({"sth": "sth"})  # type: ignore
        # {
        #    "file": str(self.filename),
        #    "watched_ops": [op.name for op in self.watched_ops],
        #    "state": current_state.__dict__,
        # }

import asyncio
import hashlib
from abc import ABC, abstractmethod
from collections import defaultdict
from dataclasses import dataclass
from pathlib import Path
from typing import Any, ClassVar, DefaultDict, Iterable, List, Protocol, Tuple, Type

import aiofiles
from pydantic import BaseModel

from cakefuzzer.attacks.executor import AttackScenario, IterationResult
from cakefuzzer.domain.interfaces import QueuePut
from cakefuzzer.domain.vulnerability import VulnerabilitiesAdd


class Scanner(ABC, BaseModel):
    def __hash__(self) -> int:
        return (
            int(
                hashlib.sha256(self.json(by_alias=True).encode("utf-8")).hexdigest(), 16
            )
            % 2**63
        )

    @abstractmethod
    async def scan(
        self,
        scenario_queue: QueuePut[AttackScenario],
        registry: VulnerabilitiesAdd,
        *args: Any,
    ) -> None:
        pass


class ScannerAdd(Protocol):
    async def register(self, scanner: Scanner) -> None:
        ...


class ScannerGet(Protocol):
    async def get(self, uid: int) -> Scanner:
        ...


@dataclass
class Monitor(ABC):
    scanner_type: ClassVar[Type[Scanner]]
    scanners: Iterable[Scanner]


class MonitorsGet(Protocol):
    async def get(self, _type: Type[Monitor]) -> Monitor:
        ...


class IterationResultScanner(Scanner, ABC):
    @abstractmethod
    async def scan(
        self,
        scenario_queue: QueuePut[AttackScenario],
        registry: VulnerabilitiesAdd,
        result: IterationResult,
    ) -> None:
        pass


@dataclass
class IterationResultsMonitor(Monitor):
    scanners: Iterable[IterationResultScanner]
    scanner_type: ClassVar[Type[Scanner]] = IterationResultScanner

    async def scan(
        self,
        scenario_queue: QueuePut[AttackScenario],
        registry: VulnerabilitiesAdd,
        result: IterationResult,
    ) -> None:
        await asyncio.gather(
            *[s.scan(scenario_queue, registry, result) for s in self.scanners]
        )


class ProcessListScanner(Scanner, ABC):
    @abstractmethod
    async def scan(
        self,
        scenario_queue: QueuePut[AttackScenario],
        registry: VulnerabilitiesAdd,
        ps_output: str,
    ) -> None:
        pass


async def exec_ps() -> str:
    command = ["ps", "-ef"]

    # Start the process
    proc = await asyncio.create_subprocess_exec(
        *command,
        stdout=asyncio.subprocess.PIPE,
        stderr=asyncio.subprocess.PIPE,
    )

    output, _ = await proc.communicate()

    return output.decode()


@dataclass
class ProcessListMonitor(Monitor):
    scanners: Iterable[ProcessListScanner]
    scanner_type: ClassVar[Type[Scanner]] = ProcessListScanner

    async def scan(
        self, scenario_queue: QueuePut[AttackScenario], registry: VulnerabilitiesAdd
    ) -> None:
        ps_output = await exec_ps()

        await asyncio.gather(
            *[s.scan(scenario_queue, registry, ps_output) for s in self.scanners]
        )


class FileContentsScanner(Scanner, ABC):
    filename: Path

    @abstractmethod
    async def scan(
        self,
        scenario_queue: QueuePut[AttackScenario],
        registry: VulnerabilitiesAdd,
        file_contents: str,
    ) -> None:
        pass


@dataclass
class FileContentsMonitor(Monitor):
    scanners: Iterable[FileContentsScanner]
    scanner_type: ClassVar[Type[Scanner]] = FileContentsScanner

    async def scan(
        self, scenario_queue: QueuePut[AttackScenario], registry: VulnerabilitiesAdd
    ) -> None:
        filenames: DefaultDict[Path, List[FileContentsScanner]] = defaultdict(list)

        for scanner in self.scanners:
            filenames[scanner.filename].append(scanner)

        for task in asyncio.as_completed(
            [read_file(filename) for filename in filenames.keys()]
        ):
            exists, filename, contents = await task

            if not exists:
                print(
                    f"[Warning] File {filename} currently does not exist. "
                    "Skipping scan()..."
                )
                continue

            if not contents:
                # Do not scan if nothing "new" was added in the file
                continue

            for scanner in filenames[filename]:
                await scanner.scan(
                    scenario_queue=scenario_queue,
                    registry=registry,
                    file_contents=contents,
                )


FILES_CACHE = {}


async def read_file(filename: Path) -> Tuple[bool, Path, str]:
    global FILES_CACHE
    # global POS

    if not filename.exists():
        return False, filename, ""

    try:
        async with aiofiles.open(filename) as f:
            contents = await f.read()

            curr_contents_len = len(contents)

            if filename not in FILES_CACHE:
                # First time reading this file
                FILES_CACHE[filename] = curr_contents_len

                # We are only interested in incremental changes,
                #  and not in the initial contents
                return True, filename, ""

            last_contents_len = FILES_CACHE[filename]

            if last_contents_len == curr_contents_len:
                return True, filename, ""

            # update cache
            FILES_CACHE[filename] = curr_contents_len

            if curr_contents_len < last_contents_len:
                return True, filename, contents

            return True, filename, contents[last_contents_len:]

    except FileNotFoundError:
        return False, filename, ""

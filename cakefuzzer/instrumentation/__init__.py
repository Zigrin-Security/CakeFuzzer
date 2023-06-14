import asyncio
from dataclasses import dataclass
from typing import List, Optional, Protocol, Tuple


class Instrumentation(Protocol):
    async def is_applied(self, lock: asyncio.Semaphore) -> bool:
        ...

    async def apply(self, lock: asyncio.Semaphore) -> None:
        ...

    async def revert(self, lock: asyncio.Semaphore) -> None:
        ...


@dataclass
class CakeFuzzerError(Exception):
    error: str
    hint: Optional[str] = None

    def __str__(self) -> str:
        return f"Error: {self.error}" f"\nTry: {self.hint}" if self.hint else ""


class InstrumentationError(CakeFuzzerError):
    pass


async def apply(*args: Instrumentation) -> List[Instrumentation]:
    semaphore = asyncio.Semaphore(1)
    await asyncio.gather(*[p.apply(semaphore) for p in args])
    return args


async def revert(*args: Instrumentation) -> List[Instrumentation]:
    semaphore = asyncio.Semaphore(1)
    await asyncio.gather(*[p.revert(semaphore) for p in args])
    return args


async def check(
    *args: Instrumentation,
) -> Tuple[List[Instrumentation], List[Instrumentation]]:
    semaphore = asyncio.Semaphore(1)
    are_applied = await asyncio.gather(*[i.is_applied(semaphore) for i in args])
    applied = [patch for is_applied, patch in zip(are_applied, args) if is_applied]
    unapplied = [
        patch for is_applied, patch in zip(are_applied, args) if not is_applied
    ]
    return applied, unapplied

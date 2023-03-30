import asyncio
from dataclasses import dataclass
from typing import List, Optional, Protocol, Tuple


class Instrumentation(Protocol):
    async def is_applied(self) -> bool:
        ...

    async def apply(self) -> None:
        ...

    async def revert(self) -> None:
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
    _, unapplied = await check(*args)
    await asyncio.gather(*[p.apply() for p in unapplied])
    return unapplied


async def revert(*args: Instrumentation) -> List[Instrumentation]:
    applied, _ = await check(*args)
    await asyncio.gather(*[p.revert() for p in applied])
    return applied


async def check(
    *args: Instrumentation,
) -> Tuple[List[Instrumentation], List[Instrumentation]]:
    are_applied = await asyncio.gather(*[i.is_applied() for i in args])
    applied = [patch for is_applied, patch in zip(are_applied, args) if is_applied]
    unapplied = [
        patch for is_applied, patch in zip(are_applied, args) if not is_applied
    ]
    return applied, unapplied

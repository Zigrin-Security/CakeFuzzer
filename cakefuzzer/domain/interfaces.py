from typing import Iterable, Optional, Protocol, TypeVar, Union

from cakefuzzer.attacks.executor import IterationResult

T = TypeVar("T")


class QueuePut(Protocol[T]):
    async def put(self, obj: Union[T, Iterable[T]]) -> None:
        ...


class QueueGet(Protocol[T]):
    async def get(self) -> T:
        ...

    def qsize(self) -> int:
        ...


class IterationResultGet(Protocol):
    async def get(self, uid: int) -> Optional[IterationResult]:
        ...

    async def get_by_payload_guid(self, payload_guid: str) -> Optional[IterationResult]:
        ...

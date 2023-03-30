from pathlib import Path
from typing import Generic, Iterable, Optional, TypeVar, Union

from persistqueue import Empty, SQLiteQueue
from pydantic import BaseModel

T = TypeVar("T", bound=BaseModel)


class PersistentQueue(Generic[T]):
    def __init__(self, _type: T, filename: Path) -> None:
        self._type = _type
        self.q = SQLiteQueue(
            path=filename.parent, db_file_name=filename.name, multithreading=True
        )

    async def put(self, obj: Union[T, Iterable[T]]) -> None:
        objs = [obj] if isinstance(obj, self._type) else obj

        for o in objs:
            self.q.put(o)

    async def get(self) -> Optional[T]:
        try:
            return self.q.get(block=False)
        except Empty:
            return None

    def qsize(self) -> int:
        return self.q._count()

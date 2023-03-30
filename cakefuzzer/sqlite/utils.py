import asyncio
import sys
from abc import ABC, abstractmethod
from pathlib import Path
from typing import Any, Generic, Iterable, Optional, Set, TypeVar, Union

import aiosqlite
from pydantic import BaseModel


class SqliteDatabase(ABC):
    def __init__(self, filename: Path = "cakefuzzer.db") -> None:
        self.filename = filename
        self.conn: aiosqlite.Connection = None  # type: ignore
        self.write_lock = asyncio.Semaphore(1)

    async def __aenter__(self) -> None:
        self.conn = await aiosqlite.connect(self.filename, timeout=15)

        await self.conn.executescript(
            """
            pragma journal_mode = WAL;
            pragma synchronous = normal;
            pragma temp_store = memory;
            pragma mmap_size = 30000000000;
        """
        )

        await self.create_tables()

        await self.conn.commit()

        print("created all that's necessary")

        return self

    async def __aexit__(self, exc_type: Any, exc: Any, tb: Any) -> None:
        if self.conn is not None:
            await self.conn.close()

    @abstractmethod
    async def create_tables(self) -> None:
        pass


T = TypeVar("T", bound=BaseModel)


class SqliteQueue(Generic[T], SqliteDatabase):
    def __init__(
        self, _type: T, filename: Path = "cakefuzzer.db", cache_size: int = 512
    ) -> None:
        super().__init__(filename)

        self.cache_size = cache_size

        self._type = _type
        self.sql_tablename = _type.__name__

        self.put_cache: Set[T] = set()
        self.get_cache: Set[T] = set()

        self._not_returned_count: int = 0

    async def create_tables(self) -> None:
        await self.conn.execute(
            f"""
            CREATE TABLE IF NOT EXISTS {self.sql_tablename} (
                uid INTEGER PRIMARY KEY,
                was_returned INTEGER DEFAULT 0,
                obj JSON
            );
        """
        )
        await self.conn.execute(
            f"""
            CREATE INDEX IF NOT EXISTS was_returned_idx 
            on {self.sql_tablename} (was_returned);
        """
        )
        # create index if not exists was_returned_idx on IterationResult (was_returned);

    async def put(self, obj: Union[T, Iterable[T]]) -> None:
        objs = [obj] if isinstance(obj, self._type) else obj

        # Caching
        new_objs = {obj for obj in objs if obj not in self.put_cache}
        self.put_cache.update(new_objs)
        while len(self.put_cache) > self.cache_size:
            self.put_cache.pop()

        if new_objs:
            async with self.write_lock:
                to_insert = [
                    (hash(obj), 0, obj.json(by_alias=True)) for obj in new_objs
                ]

                try:
                    await self.conn.executemany(
                        f"INSERT OR IGNORE INTO {self.sql_tablename} (uid,was_returned,obj) values(?,?,?)",  # noqa: E501
                        to_insert,
                    )
                    await self.conn.commit()

                except Exception as e:
                    print(
                        f"Error while inserting to database: {str(e)}", file=sys.stderr
                    )
                    print(f"{'Rows':-^80}", file=sys.stderr)
                    print(to_insert, file=sys.stderr)
                    print("=" * 80, file=sys.stderr)

    async def get(self) -> Optional[T]:
        # Return without returning control (async)
        if self.get_cache:
            return self.get_cache.pop()

        async with self.write_lock:
            # here are only the ones that got empty set when above ^
            # Max one coroutine is here at any given point
            if self.get_cache:
                return self.get_cache.pop()

            rows = await self.conn.execute_fetchall(
                f"SELECT * FROM {self.sql_tablename} WHERE was_returned=0 LIMIT {self.cache_size};"  # noqa: E501
            )

            objs = [self._type.parse_raw(obj) for _, _, obj in rows]

            if objs:
                await self.conn.executemany(
                    f"""
                    UPDATE {self.sql_tablename} 
                    SET 
                        was_returned = 1
                    WHERE
                        uid = ?
                    """,
                    [(hash(obj),) for obj in objs],
                )
                await self.conn.commit()

                rows_left = await self.conn.execute_fetchall(
                    f"SELECT count(uid) FROM {self.sql_tablename} WHERE was_returned=0;"
                )
                self._not_returned_count = rows_left[0][0]

                # print(f"Fetched: {len(objs)}, left: {rows_left[0][0]}")

                self.get_cache.update(objs)
                return self.get_cache.pop()

            return None

    def qsize(self) -> int:
        return self._not_returned_count + len(self.get_cache)

import importlib
import pathlib
from typing import Optional, Set, Type

from cakefuzzer.domain.scanners import Monitor, Scanner
from cakefuzzer.sqlite.utils import SqliteDatabase


class SqliteScanners(SqliteDatabase):
    async def create_tables(self) -> None:
        return

    async def get(self, uid: int) -> Optional[Scanner]:
        rows = await self.conn.execute_fetchall(
            """
                SELECT
                    uid,
                    type,
                    obj
                FROM
                    scanners
                WHERE uid = ?
            """,
            (uid,),
        )

        scanners = list(SqliteMonitors.parse_rows(rows))

        if scanners:
            assert len(scanners) == 1
            return scanners[0]

        return None


class SqliteMonitors(SqliteDatabase):
    def __init__(self, filename: pathlib = "cakefuzzer.db") -> None:
        super().__init__(filename)

        self.list_cache = set()

        self.get_cache = {}

    async def create_tables(self) -> None:
        await self.conn.execute(
            """
            CREATE TABLE IF NOT EXISTS scanners (
                uid INTEGER PRIMARY KEY,
                type TEXT,
                obj JSON
            );
        """
        )

    async def get(self, _type: Type[Monitor]) -> Monitor:
        if self.get_cache.get(_type) is None:
            scanners = await self._list_all()
            self.get_cache[_type] = _type(
                scanners=[
                    scanner
                    for scanner in scanners
                    if isinstance(scanner, _type.scanner_type)
                ]
            )
        return self.get_cache[_type]

    async def register(self, scanner: Scanner) -> None:
        scanners = [scanner] if isinstance(scanner, Scanner) else scanner

        async with self.write_lock:
            # print("adding", scanners)

            await self.conn.executemany(
                "INSERT OR IGNORE INTO scanners values(?,?,?)",
                [
                    (
                        hash(scanner),
                        scanner.__class__.__module__ + "." + scanner.__class__.__name__,
                        scanner.json(by_alias=True),
                    )
                    for scanner in scanners
                ],
            )
            await self.conn.commit()

    async def unregister(self, scanner: Scanner) -> None:
        # self._results_monitor.unsubscribe(scanner)
        pass

    async def _list_all(self) -> Set[Scanner]:
        if self.list_cache:
            return self.list_cache

        rows = await self.conn.execute_fetchall(
            """
                SELECT * FROM scanners;
            """
        )
        self.list_cache = self.parse_rows(rows)
        return self.list_cache

    @classmethod
    def parse_rows(cls, rows) -> Set[Scanner]:
        scanners = set()

        for _, _type_str, obj in rows:
            module_name, class_name = _type_str.rsplit(".", 1)
            module = importlib.import_module(module_name)
            _type = getattr(module, class_name)

            scanner = _type.parse_raw(obj)

            scanners.add(scanner)

        return scanners

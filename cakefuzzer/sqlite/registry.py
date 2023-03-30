from pathlib import Path
from typing import List

from cakefuzzer.domain.vulnerability import Vulnerability
from cakefuzzer.sqlite.utils import SqliteDatabase


class SqliteRegistry(SqliteDatabase):
    def __init__(self, filename: Path = "cakefuzzer.db") -> None:
        super().__init__(filename)

        # self.attack_insert_cache = set()
        # self.attack_get_cache = set()

    async def create_tables(self) -> None:
        await self.conn.execute(
            """
            CREATE TABLE IF NOT EXISTS vulnerabilities (
                vulnerability_id INTEGER PRIMARY KEY,
                obj JSON
            );
        """
        )

        # TODO deduplication logic here!

    async def add(self, vulnerability: Vulnerability) -> None:
        vs = (
            [vulnerability]
            if isinstance(vulnerability, Vulnerability)
            else vulnerability
        )

        async with self.write_lock:
            await self.conn.executemany(
                "INSERT OR IGNORE INTO vulnerabilities(vulnerability_id,obj) values(?,?)",  # noqa: E501
                [(hash(v), v.json(by_alias=True)) for v in vs],
            )
            await self.conn.commit()

    async def list_all(self) -> List[Vulnerability]:
        rows = await self.conn.execute_fetchall(
            """
                SELECT * FROM vulnerabilities;
            """
        )

        vulns = [Vulnerability.parse_raw(obj) for _, obj in rows]

        return vulns

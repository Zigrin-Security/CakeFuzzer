from pathlib import Path
from typing import Optional

from cakefuzzer.attacks.executor import IterationResult
from cakefuzzer.sqlite.utils import SqliteDatabase


class SqliteIterationResults(SqliteDatabase):
    def __init__(self, filename: Path = "cakefuzzer.db") -> None:
        super().__init__(filename)

    async def create_tables(self) -> None:
        return

    async def get(self, uid: int) -> Optional[IterationResult]:
        rows = await self.conn.execute_fetchall(
            """
                SELECT
                    uid,
                    obj
                FROM
                    IterationResult
                WHERE uid = ?
            """,
            (uid,),
        )

        iteration_results = [IterationResult.parse_raw(obj) for _, obj in rows]

        if iteration_results:
            assert len(iteration_results) == 1
            return iteration_results[0]

        return None

    async def get_by_payload_guid(self, payload_guid: str) -> Optional[IterationResult]:
        rows = await self.conn.execute_fetchall(
            """
                SELECT
                    uid,
                    obj,
                    value guid
                FROM
                    IterationResult ir, json_each(json_extract(ir.obj, '$.output.PAYLOAD_GUIDs'))
                WHERE guid = ?
            """,  # noqa: E501
            (payload_guid,),
        )

        iteration_results = [IterationResult.parse_raw(obj) for _, obj, guid in rows]

        if not iteration_results:
            return None

        assert len(iteration_results) == 1

        return iteration_results[0]

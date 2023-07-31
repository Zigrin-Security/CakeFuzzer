from pathlib import Path
from typing import Optional

from cakefuzzer.attacks.executor import IterationResult
from cakefuzzer.sqlite.utils import SqliteDatabase


class SqliteIterationResults(SqliteDatabase):
    def __init__(self, filename: Path = "cakefuzzer.db") -> None:
        super().__init__(filename)

    async def create_tables(self) -> None:

        await self.conn.execute(
            """
            CREATE TABLE IF NOT EXISTS PayloadGuids (
                iteration_result_id INTEGER,
                payload_guid TEXT PRIMARY KEY,
                FOREIGN KEY (iteration_result_id) REFERENCES IterationResult(uid)
            )
            """
        )

        # Fetch all IterationResults
        iteration_results = await self.conn.execute_fetchall(
            """
            SELECT
                uid,
                obj
            FROM
                IterationResult
            """
        )

        for uid, obj in iteration_results:
            iteration_result = IterationResult.parse_raw(obj)
            
            # Insert payload_guids into PayloadGuids table
            for payload_guid in iteration_result.output.PAYLOAD_GUIDs:
                await self.conn.execute(
                    """
                    INSERT OR IGNORE INTO PayloadGuids (iteration_result_id, payload_guid)
                    VALUES (?, ?)
                    """,
                    (uid, payload_guid),
                )

        await self.conn.commit()

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
                IterationResult.uid,
                IterationResult.obj
            FROM
                IterationResult
            INNER JOIN PayloadGuids ON IterationResult.uid = PayloadGuids.iteration_result_id
            WHERE PayloadGuids.payload_guid = ?
            """,
            (payload_guid,),
        )

        iteration_results = [IterationResult.parse_raw(obj) for _, obj in rows]

        if not iteration_results:
            return None

        assert len(iteration_results) == 1

        return iteration_results[0]

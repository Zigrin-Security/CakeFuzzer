#!/usr/bin/env python

import json
import sys
from abc import ABC

import aiosqlite


class SqliteDatabase(ABC):
    def __init__(self, filename="databases/iteration_results.db", debug=False) -> None:
        self.filename = filename
        self.conn: aiosqlite.Connection = None
        self.debug = debug

    async def connect(self):
        self.conn: aiosqlite.Connection = await aiosqlite.connect(
            self.filename, timeout=15
        )

    async def disconnect(self):
        if self.conn is not None:
            await self.conn.close()

    async def get_iteration_by_field(self, field, contains):
        contains = contains.replace('"', '""')
        query = f'SELECT obj FROM iterationresult WHERE json_extract(obj, "$.{field}") LIKE "%{contains}%"'
        r = await self._query(query)
        return self._parse_json_iteration(r)

    async def get_iteration_by_uid(self, uid):
        query = f'SELECT obj FROM iterationresult WHERE uid ="{uid}"'
        r = await self._query(query)
        r = self._parse_json_iteration(r)
        if r:
            return r[0]
        return {}

    async def get_iteration_field(self, field, contains):
        contains = contains.replace('"', '""')
        query = f'SELECT json_extract(obj, "$.{field}") as o FROM iterationresult WHERE o LIKE "%{contains}%"'
        return await self._query(query)

    async def get_iteration_field_stats(self, field):
        query = f'SELECT json_extract(obj, "$.{field}") as field_value, count(*) from IterationResult GROUP BY field_value'
        result = await self._query(query)
        formatted = {}
        for row in result:
            formatted[row[0]] = row[1]
        return formatted

    async def _query(self, query):
        if self.debug:
            print(query, file=sys.stderr)
        rows = await self.conn.execute_fetchall(query)
        return rows

    def _parse_json_iteration(self, db_result):
        parsed = []
        for item in db_result:
            parsed.append(json.loads(item[0]))

        return parsed

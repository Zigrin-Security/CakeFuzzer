#!/usr/bin/env python

import argparse
import asyncio
import json

from database import SqliteDatabase

GROUPS = {
    "output.first_http_line": "Response Status",
    "output.method": "Method",
    "output._SERVER.HTTP_ACCEPT": "HTTP_ACCEPT",
}


class StatsExtractor:
    def __init__(self, iteration_results, debug=False):
        self.database = SqliteDatabase(iteration_results, debug)
        self.stats = {}

    async def connect(self):
        await self.database.connect()

    async def disconnect(self):
        await self.database.disconnect()

    async def calculate_stats(self):
        global GROUPS
        for group in GROUPS:
            self.stats[GROUPS[group]] = await self.database.get_iteration_field_stats(
                group
            )
        return self.stats

    def print_stats(self, print_format="json"):
        if print_format == "json":
            print(json.dumps(self.stats, indent=4))
            return


async def main():
    args = parse_arguments()
    extractor = StatsExtractor(args.database.name)
    await extractor.connect()

    await extractor.calculate_stats()

    await extractor.disconnect()
    extractor.print_stats()


def parse_arguments():
    parser = argparse.ArgumentParser(
        description="Return statistics about scan iteration_results database"
    )
    parser.add_argument(
        "-d",
        "--database",
        help="iteration_results.db file",
        required=True,
        type=argparse.FileType("r"),
    )
    args = parser.parse_args()
    return args


if __name__ == "__main__":
    asyncio.run(main())

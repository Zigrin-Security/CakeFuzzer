#!/usr/bin/env python

import argparse
import asyncio
import json

from database import SqliteDatabase


class VulnExtractor:
    def __init__(self, iteration_results, debug=False):
        self.database = SqliteDatabase(iteration_results, debug)
        self.marker = "§CAKEFUZZER_PAYLOAD_GUID§"

    async def connect(self):
        await self.database.connect()

    async def disconnect(self):
        await self.database.disconnect()

    async def get_iteration(self, vulnerability):
        if not vulnerability:
            return False

        if vulnerability["strategy_name"] == "RXSSAttackStrategy":
            return await self.database.get_iteration_by_field(
                "output.output", vulnerability["detection_result"]
            )

        return False

    def get_vulnerability(self, vulns, vuln_id):
        for vuln in vulns:
            if vuln_id == vuln["vulnerability_id"]:
                return vuln

        return False


async def main():
    args = parse_arguments()
    with open(args.results, "r") as fp:
        vulns = json.load(fp)

    extractor = VulnExtractor(args.iteration_results.name, args.debug)
    await extractor.connect()

    vuln = extractor.get_vulnerability(vulns, args.vulnerability_id)
    iteration = await extractor.get_iteration(vuln)

    await extractor.disconnect()
    print(json.dumps(iteration, indent=4))


def parse_arguments():
    parser = argparse.ArgumentParser(
        description="Script to extract iteration result from the database based on the vulnerability from the results.json file."
    )
    parser.add_argument(
        "-r",
        "--results",
        help="Path to results.json file",
        required=True,
    )
    parser.add_argument(
        "-i",
        "--iteration-results",
        help="Path to iteration_results.db database file",
        required=True,
        type=argparse.FileType("r"),
    )
    parser.add_argument(
        "-v", "--vulnerability-id", help="Vulnerability ID", type=int, required=True
    )
    parser.add_argument(
        "-d",
        "--debug",
        help="Debug SQL queries",
        action="store_true",
    )
    args = parser.parse_args()
    return args


if __name__ == "__main__":
    asyncio.run(main())

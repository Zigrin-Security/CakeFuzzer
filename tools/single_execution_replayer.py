#!/usr/bin/env python

import argparse
import asyncio
import json
import shlex

from database import SqliteDatabase


class IterationHandler:
    def __init__(self, iteration_results, debug=False):
        self.database = SqliteDatabase(iteration_results, debug)
        self.single_executor = "/cake_fuzzer/cakefuzzer/phpfiles/single_execution.php"

    async def connect(self):
        await self.database.connect()

    async def disconnect(self):
        await self.database.disconnect()

    async def extract_iteration(self, iter_uid):
        return await self.database.get_iteration_by_uid(iter_uid)

    def get_config(self, iteration):
        config = {
            "fuzz_skip_keys": {"_SERVER": [
                "HTTP_CONTENT_ENCODING",
                "HTTP_X_HTTP_METHOD_OVERRIDE",
                "HTTP_AUTHORIZATION"
            ]},
            "global_exclude": [],
            "global_targets": [],
            "known_keywords": [],
            "oneParamPerPayload": False,
        }

        from_scenario = {
            "PAYLOAD_GUID_phrase": None,
            "cake_path": None,
            "webroot_file": None,
            "injectable": None,
            "strategy_name": None,
            "path": None,
        }
        for item in from_scenario:
            config[item] = iteration["scenario"][item.lower()]

        if isinstance(iteration["scenario"]["payload"], str):
            config["payloads"] = [iteration["scenario"]["payload"]]
        else:
            config["payloads"] = iteration["scenario"]["payload"]

        glob_vars = ("_GET", "_POST", "_REQUEST", "_COOKIE", "_FILES", "_SERVER")
        config["super_globals"] = {}
        for var in glob_vars:
            config["super_globals"][var] = iteration["output"][var]

        return config

    def get_command(self, config, xdebug):
        command = f"php {self.single_executor} | jq ."
        if xdebug:
            command = f"XDEBUG_SESSION=true {command}"
        command = f"echo {shlex.quote(json.dumps(config))}| jq . | {command}"
        return command


async def main():
    args = parse_arguments()
    handler = IterationHandler(args.database.name)
    await handler.connect()

    iteration = await handler.extract_iteration(args.iteration_uid)
    await handler.disconnect()
    config = handler.get_config(iteration)

    if args.command:
        print(handler.get_command(config, args.xdebug))
    else:
        print(json.dumps(config))


def parse_arguments():
    parser = argparse.ArgumentParser(description="Generate config from the iteration result ")
    parser.add_argument(
        "-d",
        "--database",
        help="iteration_results.db file",
        required=True,
        type=argparse.FileType("r"),
    )
    parser.add_argument(
        "-i",
        "--iteration-uid",
        help="UID of the iteration to generate config from",
        required=True,
    )
    parser.add_argument(
        "-c",
        "--command",
        help="Generate the PHP command with to replay the attack",
        default=False,
        action='store_true'
    )
    parser.add_argument(
        "-x",
        "--xdebug",
        help="Set the XDEBUG_SESSION flag for debugging. Has effect only with --command",
        default=False,
        action='store_true'
    )
    args = parser.parse_args()
    return args


if __name__ == "__main__":
    asyncio.run(main())

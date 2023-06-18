import asyncio
import itertools
import re
from enum import Enum
from pathlib import Path
from typing import Dict, List

import typer

from cakefuzzer.attacks.executor import AttackScenario, IterationResult
from cakefuzzer.domain.components import (
    AttackQueue,
    Monitoring,
    VulnerabilitiesRegistry,
)
from cakefuzzer.instrumentation.info_retriever import AppInfo
from cakefuzzer.instrumentation.instrumentator import Instrumentator
from cakefuzzer.instrumentation.route_computer import RouteComputer
from cakefuzzer.scanners.dns import PhraseDnsScanner
from cakefuzzer.scanners.filecontents import PhraseFileContentsScanner
from cakefuzzer.scanners.iteration_result import (
    ContextResultOutputScanner,
    ResultErrorsScanner,
    ResultOutputScanner,
)
from cakefuzzer.scanners.process import ProcessOutputScanner
from cakefuzzer.settings import load_webroot_settings
from cakefuzzer.settings.attack_definition import load_attack_definitions
from cakefuzzer.sqlite.iteration_results import SqliteIterationResults
from cakefuzzer.sqlite.queue import PersistentQueue
from cakefuzzer.sqlite.registry import SqliteRegistry
from cakefuzzer.sqlite.scanners import SqliteMonitors, SqliteScanners
from cakefuzzer.sqlite.utils import SqliteQueue


def limit_paths_to_prefix(
    paths: Dict[str, List[str]], prefix: str
) -> Dict[str, List[str]]:
    """
    Limit testing on specific paths.
    """
    new_paths = {}
    for file in paths:
        for path in paths[file]:
            if path.lower().startswith(prefix.lower()):
                if file in new_paths:
                    new_paths[file].append(path)
                else:
                    new_paths[file] = [
                        path,
                    ]
    return new_paths


def exclude_paths(paths: Dict[str, List[str]], pattern: str) -> Dict[str, List[str]]:
    """
    Exclude paths that match regular expression pattern.
    If the pattern is empty, no paths are excluded.
    """
    if pattern == "":
        return paths
    limited_paths = {}
    for file in paths:
        for path in paths[file]:
            if re.search(pattern, path, re.IGNORECASE) is None:
                if file in limited_paths:
                    limited_paths[file].append(path)
                else:
                    limited_paths[file] = [
                        path,
                    ]

    return limited_paths


def add_fuzzable_actions(actions):
    """
    To every controller add one path that will have fuzzable action part.
    """
    fuzzable = "~_CAKE_FUZZER_FUZZABLE_0_~optional~"
    for controller in actions:
        actions[controller].append(fuzzable)

    return actions


async def compute_paths(
    webroot: Path, only_paths_with_prefix: str, exclude_pattern: str
) -> Dict[str, List[str]]:
    app_info = AppInfo(webroot)
    paths = await app_info.paths

    paths = limit_paths_to_prefix(paths, prefix=only_paths_with_prefix)
    paths = exclude_paths(paths, exclude_pattern)
    return paths


async def compute_paths_old(
    webroot: Path, only_paths_with_prefix: str, exclude_pattern: str
) -> List[str]:
    app_info = AppInfo(webroot)
    # regexes = await app_info.routes
    # as per discussion in
    # https://github.com/Zigrin-Security/CakeFuzzer/pull/26#issuecomment-1320510117
    # we currently won't test all existing paths.
    # It would increase the number of paths from 1.5k to 90k

    regexes = [
        "#^/(?:(?P<controller>[^/]+))/(?:(?P<action>[^/]+))(?:/(?P<_args_>.*))?[/]*$#"
    ]
    actions = add_fuzzable_actions(await app_info.actions)

    computed_routes = RouteComputer().parse_all(
        regexes=regexes,  # regexes=await app_info.routes
        options={
            "controllers": await app_info.controllers_all_info,
            "controller": await app_info.controllers,
            "action": actions,
            "plugin": await app_info.plugins,
            "args": [
                # This should be sufficient basic detections in first path parg.
                "~_CAKE_FUZZER_FUZZABLE_1_~optional~/1/2",
                # "~_CAKE_FUZZER_FUZZABLE_1_~optional~/~_CAKE_FUZZER_FUZZABLE_2_~optional~/2"
                # "~_CAKE_FUZZER_FUZZABLE_1_~optional~/~_CAKE_FUZZER_FUZZABLE_2_~optional~/~_CAKE_FUZZER_FUZZABLE_3_~optional~"
            ],
        },
    )
    computed_routes = list(set(computed_routes))
    computed_routes.sort()  # Just for the development. To be removed.
    computed_routes = limit_paths_to_prefix(
        computed_routes, prefix=only_paths_with_prefix
    )  # return computed_routes
    computed_routes = exclude_paths(computed_routes, exclude_pattern)
    return computed_routes


async def start_registry() -> None:
    settings = load_webroot_settings()

    registry = SqliteRegistry(filename=settings.registry_db_path)
    iteration_results = SqliteIterationResults(filename=settings.results_db_path)
    scanners = SqliteScanners(filename=settings.monitors_db_path)

    async with registry, iteration_results, scanners:
        reg = VulnerabilitiesRegistry(
            vulnerabilities=registry,
            iteration_results=iteration_results,
            scanners=scanners,
        )

        await reg.save_to_file("results.json")


async def my_start_registry() -> None:
    settings = load_webroot_settings()

    registry = SqliteRegistry(filename=settings.registry_db_path)
    iteration_results = SqliteIterationResults(filename=settings.results_queue_path)
    scanners = SqliteScanners(filename=settings.monitors_db_path)

    async with registry, iteration_results, scanners:
        reg = VulnerabilitiesRegistry(
            vulnerabilities=registry,
            iteration_results=iteration_results,
            scanners=scanners,
        )

        await reg.save_to_file("results.json")


async def start_attack_queue() -> None:
    settings = load_webroot_settings()

    scenario_queue = PersistentQueue(
        AttackScenario, filename=settings.scenarios_queue_path
    )
    results_queue = PersistentQueue(
        IterationResult, filename=settings.results_queue_path
    )
    results_db = SqliteQueue(IterationResult, filename=settings.results_db_path)

    async with results_db:
        aq = AttackQueue(
            concurrent_queues=settings.concurrent_queues,
            scenario_queue=scenario_queue,
            results_queue=results_queue,
            results_db=results_db,
        )
        await aq.start()


async def my_start_attack_queue() -> None:
    settings = load_webroot_settings()

    scenario_queue = SqliteQueue(AttackScenario, filename=settings.scenarios_queue_path)
    results_queue = SqliteQueue(IterationResult, filename=settings.results_queue_path)

    async with scenario_queue, results_queue:
        aq = AttackQueue(
            concurrent_queues=settings.concurrent_queues,
            scenario_queue=scenario_queue,
            results_queue=results_queue,
            results_db=results_queue,
        )
        await aq.start()


async def start_iterations_monitors() -> None:
    settings = load_webroot_settings()

    scenario_queue = PersistentQueue(
        AttackScenario, filename=settings.scenarios_queue_path
    )
    result_queue = PersistentQueue(
        IterationResult, filename=settings.results_queue_path
    )
    monitors = SqliteMonitors(filename=settings.monitors_db_path)
    registry = SqliteRegistry(filename=settings.registry_db_path)

    async with monitors, registry:
        monitors = Monitoring(
            scenario_queue=scenario_queue,
            results_queue=result_queue,
            monitors=monitors,
            registry=registry,
        )
        await monitors.start()


async def my_start_iterations_monitors() -> None:
    settings = load_webroot_settings()

    scenario_queue = SqliteQueue(AttackScenario, filename=settings.scenarios_queue_path)
    results_queue = SqliteQueue(IterationResult, filename=settings.results_queue_path)

    monitors = SqliteMonitors(filename=settings.monitors_db_path)
    registry = SqliteRegistry(filename=settings.registry_db_path)

    async with scenario_queue, results_queue, monitors, registry:
        monitors = Monitoring(
            scenario_queue=scenario_queue,
            results_queue=results_queue,
            monitors=monitors,
            registry=registry,
        )
        await monitors.start()


async def start_periodic_monitors() -> None:
    settings = load_webroot_settings()

    scenario_queue = PersistentQueue(
        AttackScenario, filename=settings.scenarios_queue_path
    )
    result_queue = PersistentQueue(
        IterationResult, filename=settings.results_queue_path
    )
    monitors = SqliteMonitors(filename=settings.monitors_db_path)
    registry = SqliteRegistry(filename=settings.registry_db_path)

    async with monitors, registry:
        monitors = Monitoring(
            scenario_queue=scenario_queue,
            results_queue=result_queue,
            monitors=monitors,
            registry=registry,
        )
        await monitors.periodic()


async def my_start_periodic_monitors() -> None:
    settings = load_webroot_settings()

    scenario_queue = SqliteQueue(AttackScenario, filename=settings.scenarios_queue_path)
    results_queue = SqliteQueue(IterationResult, filename=settings.results_queue_path)
    monitors = SqliteMonitors(filename=settings.monitors_db_path)
    registry = SqliteRegistry(filename=settings.registry_db_path)

    async with scenario_queue, results_queue, monitors, registry:
        monitors = Monitoring(
            scenario_queue=scenario_queue,
            results_queue=results_queue,
            monitors=monitors,
            registry=registry,
        )
        await monitors.periodic()


async def start_others() -> None:
    settings = load_webroot_settings()

    defs = load_attack_definitions(Path("strategies"))

    # scenario_queue = SqliteQueue(AttackScenario, filename=settings.sqlite_path)
    scenario_queue = PersistentQueue(
        AttackScenario, filename=settings.scenarios_queue_path
    )
    monitors = SqliteMonitors(filename=settings.monitors_db_path)

    # async with scenario_queue, monitors:
    async with monitors:
        paths = await compute_paths(
            webroot=settings.webroot_dir,
            only_paths_with_prefix=settings.only_paths_with_prefix,
            exclude_pattern=settings.exclude_paths,
        )
        total_paths = sum(len(paths[php_file]) for php_file in paths)
        print(
            f"discovered {len(paths)} files to scan with total of {total_paths} paths"
        )

        app_info = AppInfo(settings.webroot_dir)
        log_paths = await app_info.log_paths
        framework_handler = await app_info.framework_handler

        for definition in defs:
            attacks = []
            for php_file in paths:
                attacks += [
                    AttackScenario(
                        framework_handler=framework_handler,
                        web_root=str(settings.webroot_dir),
                        webroot_file=str(php_file),
                        strategy_name=definition.strategy_name,
                        payload=payload,
                        path=path,
                        total_iterations=32,
                        payload_guid_phrase=settings.payload_guid_phrase,
                    )
                    for payload, path in itertools.product(
                        definition.scenarios, paths[php_file]
                    )
                ]

            scanners = []

            for scanner in definition.scanners:
                if scanner.scanner_type == "LogFilesContentsScanner":
                    for log_path in log_paths:
                        scanners.append(
                            PhraseFileContentsScanner(
                                filename=log_path,
                                phrase=scanner.phrase,
                                payload_guid_phrase=settings.payload_guid_phrase,
                                is_regex=scanner.is_regex,
                            )
                        )

                elif scanner.scanner_type == "ContextResultOutputScanner":
                    if scanner.extra is None or "context_location" not in scanner.extra:
                        raise ValueError(
                            "ContextResultOutputScanner requires extra.context_location"
                        )

                    scanners.append(
                        ContextResultOutputScanner(
                            phrase=scanner.phrase,
                            context_location=scanner.extra["context_location"],
                            payload_guid_phrase=settings.payload_guid_phrase,
                            is_regex=scanner.is_regex,
                        )
                    )

                else:
                    _type = {
                        "ResultOutputScanner": ResultOutputScanner,
                        "ProcessOutputScanner": ProcessOutputScanner,
                        "PhraseFileContentsScanner": PhraseFileContentsScanner,
                        "ResultErrorsScanner": ResultErrorsScanner,
                        "PhraseDnsScanner": PhraseDnsScanner,
                    }
                    kwargs = {
                        "phrase": scanner.phrase,
                        "payload_guid_phrase": settings.payload_guid_phrase,
                        "is_regex": scanner.is_regex,
                    }
                    scanners.append(_type[scanner.scanner_type](**kwargs))

            await monitors.register(scanners)
            await scenario_queue.put(attacks)

            print(
                f"Scheduled {definition.strategy_name}: "
                f"{len(attacks)} attacks, "
                f"{len(scanners)} scanners."
            )

        print("DONE!")


async def my_start_others() -> None:
    settings = load_webroot_settings()

    defs = load_attack_definitions(Path("strategies"))

    scenario_queue = SqliteQueue(AttackScenario, filename=settings.scenarios_queue_path)
    monitors = SqliteMonitors(filename=settings.monitors_db_path)

    async with scenario_queue, monitors:
        paths = await compute_paths(
            webroot=settings.webroot_dir,
            only_paths_with_prefix=settings.only_paths_with_prefix,
            exclude_pattern=settings.exclude_paths,
        )
        total_paths = sum(len(paths[php_file]) for php_file in paths)
        print(
            f"discovered {len(paths)} files to scan with total of {total_paths} paths"
        )

        app_info = AppInfo(settings.webroot_dir)
        log_paths = await app_info.log_paths
        framework_handler = await app_info.framework_handler

        for definition in defs:
            attacks = []
            for php_file in paths:
                attacks += [
                    AttackScenario(
                        framework_handler=framework_handler,
                        web_root=str(settings.webroot_dir),
                        webroot_file=str(php_file),
                        strategy_name=definition.strategy_name,
                        payload=payload,
                        path=path,
                        total_iterations=32,
                        payload_guid_phrase=settings.payload_guid_phrase,
                    )
                    for payload, path in itertools.product(
                        definition.scenarios, paths[php_file]
                    )
                ]

            scanners = []

            for scanner in definition.scanners:
                if scanner.scanner_type == "LogFilesContentsScanner":
                    for log_path in log_paths:
                        scanners.append(
                            PhraseFileContentsScanner(
                                filename=log_path,
                                phrase=scanner.phrase,
                                payload_guid_phrase=settings.payload_guid_phrase,
                                is_regex=scanner.is_regex,
                            )
                        )

                elif scanner.scanner_type == "ContextResultOutputScanner":
                    if scanner.extra is None or "context_location" not in scanner.extra:
                        raise ValueError(
                            "ContextResultOutputScanner requires extra.context_location"
                        )

                    scanners.append(
                        ContextResultOutputScanner(
                            phrase=scanner.phrase,
                            context_location=scanner.extra["context_location"],
                            payload_guid_phrase=settings.payload_guid_phrase,
                            is_regex=scanner.is_regex,
                        )
                    )

                else:
                    _type = {
                        "ResultOutputScanner": ResultOutputScanner,
                        "ProcessOutputScanner": ProcessOutputScanner,
                        "PhraseFileContentsScanner": PhraseFileContentsScanner,
                        "ResultErrorsScanner": ResultErrorsScanner,
                        "PhraseDnsScanner": PhraseDnsScanner,
                    }
                    kwargs = {
                        "phrase": scanner.phrase,
                        "payload_guid_phrase": settings.payload_guid_phrase,
                        "is_regex": scanner.is_regex,
                    }
                    scanners.append(_type[scanner.scanner_type](**kwargs))

            await monitors.register(scanners)
            await scenario_queue.put(attacks)

            print(
                f"Scheduled {definition.strategy_name}: "
                f"{len(attacks)} attacks, "
                f"{len(scanners)} scanners."
            )

        print("DONE!")


async def apply_instrumentation() -> None:
    settings = load_webroot_settings()
    inst = Instrumentator(settings.webroot_dir)
    await inst.apply()


async def revert_instrumentation() -> None:
    settings = load_webroot_settings()
    inst = Instrumentator(settings.webroot_dir)
    await inst.revert()


async def is_instrumented() -> None:
    settings = load_webroot_settings()
    inst = Instrumentator(settings.webroot_dir)
    await inst.is_applied()


app = typer.Typer()


class Component(str, Enum):
    Fuzzer = "fuzzer"
    PeriodicMonitors = "periodic_monitors"
    IterationMonitors = "iteration_monitors"
    Registry = "registry"
    AttackQueue = "attack_queue"
    Instrumentation = "instrument"


@app.command("run")
def run_component(component: Component, myqueue: bool = False) -> None:
    if myqueue:
        cmds_to_run = {
            Component.Fuzzer: my_start_others,
            Component.PeriodicMonitors: my_start_periodic_monitors,
            Component.IterationMonitors: my_start_iterations_monitors,
            Component.Registry: my_start_registry,
            Component.AttackQueue: my_start_attack_queue,
        }
    else:
        cmds_to_run = {
            Component.Fuzzer: start_others,
            Component.PeriodicMonitors: start_periodic_monitors,
            Component.IterationMonitors: start_iterations_monitors,
            Component.Registry: start_registry,
            Component.AttackQueue: start_attack_queue,
        }

    asyncio.run(cmds_to_run[component]())

    print("Finished!")


@app.command("instrument")
def instrumentation(option: str) -> None:
    if option == "apply":
        asyncio.run(apply_instrumentation())

    if option == "revert":
        asyncio.run(revert_instrumentation())

    if option == "check":
        asyncio.run(is_instrumented())


if __name__ == "__main__":
    app()

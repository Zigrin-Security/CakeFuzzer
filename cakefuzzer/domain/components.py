import asyncio
import json
import time
import urllib.parse
from dataclasses import dataclass
from pathlib import Path
from typing import Any, Dict, List, Tuple

import aiofiles
from progress.bar import Bar
from progress.spinner import Spinner

from cakefuzzer.attacks import SuperGlobals
from cakefuzzer.attacks.executor import AttackScenario, IterationResult
from cakefuzzer.domain.interfaces import IterationResultGet, QueueGet, QueuePut
from cakefuzzer.domain.scanners import (
    DnsMonitor,
    FileContentsMonitor,
    IterationResultsMonitor,
    MonitorsGet,
    ProcessListMonitor,
    Scanner,
    ScannerGet,
)
from cakefuzzer.domain.vulnerability import (
    VulnerabilitiesAdd,
    VulnerabilitiesGet,
    Vulnerability,
)


class CakeFuzzerProgressBar(Bar):
    suffix = "%(index)d/%(max)d - %(percent).1f%% - eta: %(etah)02d:%(etam)02d:%(etas)02d - elapsed: %(elapsed)d"  # noqa: E501

    @property
    def etah(self):
        return self.eta // 3600

    @property
    def etam(self):
        return (self.eta % 3600) // 60

    @property
    def etas(self):
        return self.eta % 60


@dataclass
class AttackQueue:
    concurrent_queues: int
    scenario_queue: QueueGet[AttackScenario]
    results_queue: QueuePut[IterationResult]
    results_db: QueuePut[IterationResult]

    async def start(self) -> None:
        total = self.scenario_queue.qsize()
        self.bar = CakeFuzzerProgressBar("Executing Attack Scenarios", max=total)

        # TODO: This could be done niced I think... something to think about
        await asyncio.gather(*[self.run() for _ in range(self.concurrent_queues)])

    async def run(self) -> None:
        while True:
            scenario = await self.scenario_queue.get()

            queue_size = self.scenario_queue.qsize()
            self.bar.max = queue_size + self.bar.index

            if scenario:
                results = await scenario.execute_all()

                # Store results for future processing and reference if vuln found
                await self.results_queue.put(results)
                await self.results_db.put(results)

                self.bar.next()

            else:
                self.bar.update()
                await asyncio.sleep(1)


@dataclass
class Monitoring:
    scenario_queue: QueuePut[AttackScenario]
    results_queue: QueueGet[IterationResult]
    monitors: MonitorsGet
    registry: VulnerabilitiesAdd

    async def start(self) -> None:
        queue_size = self.results_queue.qsize()
        self.bar = CakeFuzzerProgressBar("Scanning Iteration Results", max=queue_size)

        await asyncio.gather(
            self.update_bar(),
            self.continuous(),
        )

    async def update_bar(self) -> None:
        while True:
            await asyncio.sleep(5)

            queue_size = self.results_queue.qsize()
            self.bar.max = queue_size + self.bar.index

    async def continuous(self) -> None:
        """Run continously, whenever there's something to process, do it."""

        while True:
            time.time()

            # Check for iteration_result to process
            result = await self.results_queue.get()
            if result is None:
                self.bar.update()

                await asyncio.sleep(1)
                continue

            self.bar.next()

            result_monitor = await self.monitors.get(IterationResultsMonitor)

            await result_monitor.scan(
                scenario_queue=self.scenario_queue,
                registry=self.registry,
                result=result,
            )

            if result.scenario.config.oneParamPerPayload:
                await one_param_per_payload(result, self.scenario_queue)

            # Return control
            await asyncio.sleep(0)

    async def periodic(self) -> None:
        LOOP_TIME = 0.5

        spinner = Spinner(f"Scanning each {LOOP_TIME}s ")

        try:
            dns_monitor = await self.monitors.get(DnsMonitor)
            dns_monitor.start(
                scenario_queue=self.scenario_queue, registry=self.registry
            )

            while True:
                start_time = time.time()

                spinner.next()

                ps_monitor = await self.monitors.get(ProcessListMonitor)
                file_monitor = await self.monitors.get(FileContentsMonitor)

                asyncio.gather(
                    ps_monitor.scan(
                        scenario_queue=self.scenario_queue, registry=self.registry
                    ),
                    file_monitor.scan(
                        scenario_queue=self.scenario_queue, registry=self.registry
                    ),
                )

                await asyncio.sleep(max(0, LOOP_TIME - (time.time() - start_time)))

                elapsed = time.time() - start_time
                if elapsed > LOOP_TIME * 1.1:
                    print(f"periodic {elapsed:.4f}")

        finally:
            dns_monitor.stop()


async def one_param_per_payload(
    result: IterationResult, scenario_queue: QueuePut[AttackScenario]
) -> None:
    scenario = result.scenario

    if scenario.config.oneParamPerPayload:
        super_globals = list(
            (name, mf.alias) for name, mf in SuperGlobals.__fields__.items()
        )

        unfuzzable = scenario.unfuzzable_parameters

        new_attacks = []

        for sg_name, sg_alias in super_globals:
            for param_name in result.output.__getattribute__(sg_name):
                # Ignore because defined so by config
                if (sg_alias, param_name) in unfuzzable:
                    continue

                new_attack = AttackScenario(
                    framework_handler=scenario.framework_handler,
                    web_root=scenario.web_root,
                    webroot_file=scenario.webroot_file,
                    strategy_name=scenario.strategy_name,
                    payload=scenario.payload,
                    path=scenario.path,
                    injectable={sg_alias: param_name},
                    total_iterations=scenario.total_iterations,
                    payload_guid_phrase=scenario.payload_guid_phrase,
                )

                new_attacks.append(new_attack)

        if new_attacks:
            await scenario_queue.put(new_attacks)


@dataclass
class FullVulnerability:
    iteration_result: IterationResult
    scanner: Scanner
    vulnerability: Vulnerability
    vulnerability_location: Tuple[str]

    def unique_repr(self) -> Dict[str, Any]:
        return (
            (
                "strategy_name",
                self.iteration_result.scenario.strategy_name
                if self.iteration_result
                else None,
            ),
            ("vulnerability_location", json.dumps(self.vulnerability_location)),
            (
                "path",
                self.iteration_result.deduplicable_path
                if self.iteration_result
                else None,
            ),
        )

    def __hash__(self) -> int:
        return hash(self.unique_repr())

    def __eq__(self, __o: "FullVulnerability") -> bool:
        if not isinstance(__o, FullVulnerability):
            return False

        this_strategy_name = (
            self.iteration_result.scenario.strategy_name
            if self.iteration_result
            else None
        )
        that_stategy_name = (
            __o.iteration_result.scenario.strategy_name
            if __o.iteration_result
            else None
        )

        if this_strategy_name != that_stategy_name:
            return False

        if self.vulnerability_location != __o.vulnerability_location:
            return False

        # if self.iteration_result.output.path != __o.iteration_result.output.path:
        #     return False

        return True


@dataclass
class VulnerabilitiesRegistry:
    vulnerabilities: VulnerabilitiesGet
    iteration_results: IterationResultGet
    scanners: ScannerGet

    async def get_iteration_result(self, vuln: Vulnerability) -> IterationResult:
        if vuln.iteration_result_id:
            ir = await self.iteration_results.get(uid=vuln.iteration_result_id)

            if vuln.payload_guid is None or vuln.payload_guid in ir.output.PAYLOAD_GUIDs:
                return ir
            
            return await self.iteration_results.get_by_payload_guid(
                payload_guid=vuln.payload_guid
            )

        if vuln.payload_guid:
            return await self.iteration_results.get_by_payload_guid(
                payload_guid=vuln.payload_guid
            )

        print("Cannot join vulnerability with any iteration result:", vuln)

        return None

    async def list_all(self) -> List[FullVulnerability]:
        vulns = await self.vulnerabilities.list_all()

        full_vulns = []

        # Retrieve missing objects
        for vuln in vulns:
            iteration_result = await self.get_iteration_result(vuln)
            scanner = await self.scanners.get(uid=vuln.scanner_id)

            vulnerability_location = (
                VulnerabilitiesRegistry.find_vulnerability_location(
                    iteration_result, vuln.payload_guid
                )
            )

            full_vulns.append(
                FullVulnerability(
                    iteration_result=iteration_result,
                    scanner=scanner,
                    vulnerability=vuln,
                    vulnerability_location=vulnerability_location,
                )
            )

        return full_vulns

    @staticmethod
    def find_vulnerability_location(
        iteration_result: IterationResult, payload_guid: int
    ) -> Tuple[str]:
        if iteration_result is None:
            return {}

        output_json = iteration_result.output.dict(by_alias=True, exclude={"output"})
        output_json["path"] = urllib.parse.unquote_plus(output_json["path"])

        if payload_guid is None:
            payload = iteration_result.scenario.payload
        else:
            CAKEFUZZER_PAYLOAD_GUID = iteration_result.scenario.payload_guid_phrase
            payload = iteration_result.scenario.payload.replace(
                CAKEFUZZER_PAYLOAD_GUID, str(payload_guid)
            )
        # TODO: exactly used payload could be moved to IterationResult obj.
        #   eg as property or something -> Think about that

        location = VulnerabilitiesRegistry._get_json_location(output_json, payload)
        if location is None:
            return {}

        return location

    @staticmethod
    def _get_json_location(obj: Any, target: str):
        if isinstance(obj, dict):
            for key, value in obj.items():
                if target in key:
                    return {key: value}

                _loc = VulnerabilitiesRegistry._get_json_location(value, target)
                if _loc is not None:
                    return {key: _loc}

        if isinstance(obj, list):
            for o in obj:
                _loc = VulnerabilitiesRegistry._get_json_location(o, target)

                if _loc is not None:
                    return [_loc]

            return None

        if isinstance(obj, str):
            if target in obj:
                return obj

            return None

    async def list_unique(self) -> List[FullVulnerability]:
        return deduplicate_vulnerabilities(await self.list_all())

    async def save_to_file(self, filename: Path = Path("results.json")):
        vulns = await self.list_unique()

        dump_vulns = []

        for i, vuln in enumerate(vulns):
            if vuln.vulnerability.payload_guid is None:
                if vuln.iteration_result is None:
                    payload = None
                else:
                    payload = vuln.iteration_result.scenario.payload
            else:
                if vuln.iteration_result is None:
                    payload = None
                else:
                    CAKEFUZZER_PAYLOAD_GUID = (
                        vuln.iteration_result.scenario.payload_guid_phrase
                    )
                    payload = vuln.iteration_result.scenario.payload.replace(
                        CAKEFUZZER_PAYLOAD_GUID, str(vuln.vulnerability.payload_guid)
                    )

            if vuln.iteration_result is None:
                dump_vuln = {
                    "strategy_name": None,
                    "payload": payload,
                    "detection_result": vuln.vulnerability.detection_result,
                    "context_location": vuln.vulnerability.context_location,
                    "vulnerability_location": vuln.vulnerability_location,
                    "vulnerability_id": i,
                    "path": None,
                    "method": None,
                    "superglobal": {
                        "_GET": None,
                        "_POST": None,
                        "_REQUEST": None,
                        "_COOKIE": None,
                        "_FILES": None,
                        "_SERVER": None,
                    },
                }

            else:
                dump_vuln = {
                    "strategy_name": vuln.iteration_result.scenario.strategy_name,
                    "payload": payload,
                    "detection_result": vuln.vulnerability.detection_result,
                    "context_location": vuln.vulnerability.context_location,
                    "vulnerability_location": vuln.vulnerability_location,
                    "vulnerability_id": i,
                    "path": vuln.iteration_result.output.path,
                    "method": vuln.iteration_result.output.method,
                    "superglobal": {
                        "_GET": vuln.iteration_result.output.GET,
                        "_POST": vuln.iteration_result.output.POST,
                        "_REQUEST": vuln.iteration_result.output.REQUEST,
                        "_COOKIE": vuln.iteration_result.output.COOKIE,
                        "_FILES": vuln.iteration_result.output.FILES,
                        "_SERVER": vuln.iteration_result.output.SERVER,
                    },
                }

            dump_vulns.append(dump_vuln)

        async with aiofiles.open(filename, "w+") as f:
            await f.write(json.dumps(dump_vulns, indent=4))


def deduplicate_vulnerabilities(
    vulnerabilities: List[FullVulnerability],
) -> List[FullVulnerability]:
    unique = list(set(vulnerabilities))

    return unique

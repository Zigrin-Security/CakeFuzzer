import time

from cakefuzzer.attacks.executor import AttackScenario, IterationResult
from cakefuzzer.domain.interfaces import QueuePut
from cakefuzzer.domain.scanners import IterationResultScanner
from cakefuzzer.domain.vulnerability import VulnerabilitiesAdd
from cakefuzzer.scanners.utils import VulnerabilityBuilder


class ResultOutputScanner(IterationResultScanner):
    phrase: str
    payload_guid_phrase: str
    is_regex: bool = False

    async def scan(
        self,
        attack_queue: QueuePut[AttackScenario],
        registry: VulnerabilitiesAdd,
        result: IterationResult,
    ) -> None:
        vulnerability_builder = VulnerabilityBuilder(
            phrase=self.phrase,
            string=result.output.output,
            payload_guid_phrase=self.payload_guid_phrase,
            is_regex=self.is_regex,
        )
        vulnerabilities = vulnerability_builder.get_vulnerability_objects(
            timestamp=time.time(),
            scanner_id=hash(self),
            iteration_result_id=hash(result),  # We cannot get it now, have to wait...
        )

        for vulnerability in vulnerabilities:
            await registry.add(vulnerability)


class ResultErrorsScanner(IterationResultScanner):
    phrase: str
    payload_guid_phrase: str
    is_regex: bool = True

    async def scan(
        self,
        attack_queue: QueuePut[AttackScenario],
        registry: VulnerabilitiesAdd,
        result: IterationResult,
    ) -> None:
        vulnerability_builder = VulnerabilityBuilder(
            phrase=self.phrase,
            string=result.errors.string,
            payload_guid_phrase=self.payload_guid_phrase,
            is_regex=self.is_regex,
        )
        vulnerabilities = vulnerability_builder.get_vulnerability_objects(
            timestamp=time.time(),
            scanner_id=hash(self),
            iteration_result_id=hash(result),
        )
        for vulnerability in vulnerabilities:
            await registry.add(vulnerability)


class ContextResultOutputScanner(IterationResultScanner):
    phrase: str
    context_location: str
    payload_guid_phrase: str
    is_regex: bool = False

    async def scan(
        self,
        attack_queue: QueuePut[AttackScenario],
        registry: VulnerabilitiesAdd,
        result: IterationResult,
    ) -> None:
        
        vulnerability_builder = VulnerabilityBuilder(
            phrase=self.phrase,
            string=result.output.output,
            payload_guid_phrase=self.payload_guid_phrase,
            is_regex=self.is_regex,
        )
        vulnerabilities = vulnerability_builder.get_vulnerability_objects(
            context_location=self.context_location,
            timestamp=time.time(),
            scanner_id=hash(self),
            iteration_result_id=hash(result),  # We cannot get it now, have to wait...
        )

        for vulnerability in vulnerabilities:
            await registry.add(vulnerability)
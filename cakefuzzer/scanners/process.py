import time

from cakefuzzer.attacks.executor import AttackScenario
from cakefuzzer.domain.interfaces import QueuePut
from cakefuzzer.domain.scanners import ProcessListScanner
from cakefuzzer.domain.vulnerability import VulnerabilitiesAdd
from cakefuzzer.scanners.utils import VulnerabilityBuilder


class ProcessOutputScanner(ProcessListScanner):
    phrase: str
    payload_guid_phrase: str
    is_regex: bool = True

    async def scan(
        self,
        attack_queue: QueuePut[AttackScenario],
        registry: VulnerabilitiesAdd,
        ps_output: str,
    ) -> None:
        vulnerability_builder = VulnerabilityBuilder(
            phrase=self.phrase,
            string=ps_output,
            payload_guid_phrase=self.payload_guid_phrase,
            is_regex=self.is_regex,
        )
        vulnerabilities = vulnerability_builder.get_vulnerability_objects(
            timestamp=time.time(),
            scanner_id=hash(self),
            iteration_result_id=None,  # We cannot get it now, have to wait...
        )

        for vulnerability in vulnerabilities:
            print(
                "Found it!!!!!",
                vulnerability.detection_result,
                vulnerability.payload_guid,
            )
            await registry.add(vulnerability)

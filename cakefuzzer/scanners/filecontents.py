import time

from cakefuzzer.attacks.executor import AttackScenario
from cakefuzzer.domain.interfaces import QueuePut
from cakefuzzer.domain.scanners import FileContentsScanner
from cakefuzzer.domain.vulnerability import VulnerabilitiesAdd
from cakefuzzer.scanners.utils import VulnerabilityBuilder


class PhraseFileContentsScanner(FileContentsScanner):
    phrase: str
    payload_guid_phrase: str
    is_regex: bool = False

    async def scan(
        self,
        scenario_queue: QueuePut[AttackScenario],
        registry: VulnerabilitiesAdd,
        file_contents: str,
    ) -> None:
        # TODO: this should be turned off when found something it's interested in

        vulnerability_builder = VulnerabilityBuilder(
            phrase=self.phrase,
            string=file_contents,
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
                "Found in file????",
                vulnerability.detection_result,
                vulnerability.payload_guid,
            )
            await registry.add(vulnerability)

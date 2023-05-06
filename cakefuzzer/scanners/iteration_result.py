import time
import re
from bs4 import BeautifulSoup

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
        
        outcome = find_html_location(result.output.output, self.phrase)

        vulnerability_builder = VulnerabilityBuilder(
            phrase=self.phrase,
            string=result.output.output,
            payload_guid_phrase=self.payload_guid_phrase,
            is_regex=self.is_regex,
        )
        vulnerabilities = vulnerability_builder.get_vulnerability_objects(
            detection_location=outcome,
            timestamp=time.time(),
            scanner_id=hash(self),
            iteration_result_id=hash(result),  # We cannot get it now, have to wait...
        )

        for vulnerability in vulnerabilities:
            await registry.add(vulnerability)

def find_html_location(
        contents : str,
        phrase : str,
        ) -> None:
    
    soap = BeautifulSoup(contents, 'html.parser')
    # html sees "<_" as &lt; so it doesnt recognize it as a tag
    return soap.find_all(lambda tag: 
                         tag.name and re.findall(pattern=phrase, string=str(tag.name)) or
                         tag.string and re.findall(pattern=phrase, string=str(tag.string)) or
                         tag.attrs and all(re.findall(pattern=phrase, string=str(key)) for key in tag.attrs.keys()) or
                         tag.attrs and all(re.findall(pattern=phrase, string=str(value)) for value in tag.attrs.values())
                         )



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

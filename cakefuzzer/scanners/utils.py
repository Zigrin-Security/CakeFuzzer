import fnmatch
import os
import re
import time
from dataclasses import dataclass
from typing import Iterator, List, Match, Optional
from uuid import uuid4

from bs4 import BeautifulSoup

from cakefuzzer.domain.vulnerability import Vulnerability

REGEX_CACHE = {}


@dataclass
class VulnerabilityBuilder:
    phrase: str
    string: str
    payload_guid_phrase: str
    is_regex: bool = False

    def _find(self) -> Iterator[Match[str]]:
        if not self.is_regex:
            if self.phrase not in REGEX_CACHE:
                REGEX_CACHE[self.phrase] = re.escape(self.phrase)
            phrase = REGEX_CACHE[self.phrase]
        else:
            phrase = self.phrase  # If it's regex without Payload GUID

        if self.payload_guid_phrase in self.phrase:
            if self.phrase not in REGEX_CACHE:
                parts = self.phrase.split(self.payload_guid_phrase)
                # We have to assume that the rest of the phrase is correct regex.
                # Therefore no re.escape for the part 0 and 1
                regex = f"({parts[0]})(?P<CAKEFUZZER_PAYLOAD_GUID>[0-9]+)({parts[1]})"

                REGEX_CACHE[self.phrase] = re.compile(regex, flags=re.DOTALL)

            regex = REGEX_CACHE[self.phrase]
            return regex.finditer(self.string)

            # parts = self.phrase.split(self.payload_guid_phrase)
            # # We have to assume that the rest of the phrase is correct regex.
            # # Therefore no re.escape for the part 0 and 1
            # regex = f"({parts[0]})(?P<CAKEFUZZER_PAYLOAD_GUID>[0-9]+)({parts[1]})"
            # return re.finditer(
            #     regex, self.string, re.DOTALL
            # )  # this always returns an iterator, even if empty

        if match := re.search(phrase, self.string, re.DOTALL):
            return [match]
        return []

    def get_vulnerability_objects(
        self, context_location: Optional[str] = None, **kwargs
    ) -> List[Vulnerability]:
        start_time = time.time()

        vulnerabilities = []
        for match in self._find():
            detection_result = match.group(0).strip('"')
            payload_guid = (
                match.group("CAKEFUZZER_PAYLOAD_GUID")
                if "CAKEFUZZER_PAYLOAD_GUID" in match.groupdict().keys()
                else None
            )
            detection_location = []
            if context_location is not None:
                locations = find_html_location(self.string, detection_result)
                detection_location = fnmatch.filter(locations, context_location)

                # If the context location is not where it should be, skip it
                if detection_location == []:
                    continue

            vulnerabilities.append(
                Vulnerability(
                    detection_result=detection_result,
                    context_location=detection_location.__repr__().strip("[]"),
                    payload_guid=payload_guid,
                    **kwargs,
                )
            )

        elapsed = time.time() - start_time

        if elapsed > 5:
            print(f"Elapsed {self.phrase}:", time.time() - start_time)
            uid = uuid4()
            if not os.path.exists("longs"):
                os.makedirs("longs")
            with open(f"longs/{str(uid)}", "w+") as f:
                f.write(self.string)

        return vulnerabilities


def find_html_location(
    contents: str,
    phrase: str,
) -> List[str]:
    soup = BeautifulSoup(contents, "html.parser")
    tags = []
    for tag in soup.find_all():
        if phrase in tag.text:
            tags.append(f"{tag.name}.text")
        if phrase.lower() in tag.name:
            # !!! HTML sees "<_" as "&lt;" so it doesnt recognize it as a tag
            tags.append("tag")
        for attr, value in tag.attrs.items():
            if phrase in value:
                tags.append(f"{tag.name}.{attr}.value")
            if phrase.lower() in attr:
                tags.append(f"{tag.name}.attr")
    return tags

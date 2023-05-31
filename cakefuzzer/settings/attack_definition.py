from pathlib import Path
from typing import Dict, List, Optional

from pydantic import BaseModel


class ScannerDefinition(BaseModel):
    scanner_type: str
    phrase: str
    is_regex: bool = True
    extra: Optional[Dict[str, str]] = None


class AttackStrategyDefinition(BaseModel):
    strategy_name: str
    scenarios: List[str]
    scanners: List[ScannerDefinition]


def load_attack_definitions(path: Path) -> List[AttackStrategyDefinition]:
    defs = []

    for file in path.glob("*.json"):
        defs.append(AttackStrategyDefinition.parse_file(file))

    return defs

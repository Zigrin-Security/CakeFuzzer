import asyncio
import hashlib
import sys
from typing import Dict, List, Optional, Tuple

from pydantic import BaseModel, Field

from cakefuzzer.attacks import SuperGlobals


class SuperGlobalsConfig(BaseModel):
    SERVER: Dict[str, str] = Field(alias="_SERVER")


class SkipFuzzingKeysConfig(BaseModel):
    SERVER: List[str] = Field(alias="_SERVER")
    GET: List[str] = Field(alias="_GET")
    POST: List[str] = Field(alias="_POST")
    COOKIE: List[str] = Field(alias="_COOKIE")
    FILES: List[str] = Field(alias="_FILES")


class SingleExecutorOutput(SuperGlobals):
    first_http_line: Optional[str]
    method: str
    path: str
    headers: dict
    output: str

    PAYLOAD_GUIDs: List[str]
    exec_time: float


class SingleExecutorErrors(BaseModel):
    string: str


class SingleExecutorConfig(BaseModel):
    framework_handler: str
    web_root: str
    webroot_file: str
    strategy_name: str
    path: str
    super_globals: SuperGlobalsConfig
    payloads: List[str]
    global_targets: List[str]
    global_exclude: List[str]
    fuzz_skip_keys: SkipFuzzingKeysConfig
    oneParamPerPayload: bool
    iterations: int
    PAYLOAD_GUID_phrase: str
    injectable: Optional[Dict[str, str]] = None
    extra_app_info: Dict = None


class IterationResult(BaseModel):
    @property
    def deduplicable_path(self) -> str:
        scenario_path = self.scenario.path.split("/")
        path = []
        for index, value in enumerate(self.output.path.split("/")):
            if index < len(scenario_path) and scenario_path[index] == value:
                path.append(value)
            else:
                if path[-1] != "":
                    path.append("")
                break
        return "/".join(path)

    @property
    def iteration_id(self) -> int:
        return self.__hash__()

    def __hash__(self) -> int:
        return (
            int(
                hashlib.sha256(self.json(by_alias=True).encode("utf-8")).hexdigest(), 16
            )
            % 2**63
        )

    scenario: "AttackScenario"
    iteration: int
    output: SingleExecutorOutput
    errors: SingleExecutorErrors


async def exec_single_executor(
    config: SingleExecutorConfig,
) -> Tuple[SingleExecutorOutput, SingleExecutorErrors]:
    proc = await asyncio.create_subprocess_exec(
        *["php", "cakefuzzer/phpfiles/single_execution.php"],
        stdin=asyncio.subprocess.PIPE,
        stdout=asyncio.subprocess.PIPE,
        stderr=asyncio.subprocess.PIPE,
    )

    try:
        stdout, stderr = await asyncio.wait_for(
            proc.communicate(input=config.json(by_alias=True).encode("utf8")),
            timeout=10.0,
        )

    except TimeoutError:
        print("TimeoutError while waiting for execution to finish", file=sys.stderr)
        print(f"{'Config':-^80}", file=sys.stderr)
        print(config.json(by_alias=True), file=sys.stderr)
        print("=" * 80, file=sys.stderr)

    try:
        output = SingleExecutorOutput.parse_raw(stdout)  # TODO!!!
        errors = SingleExecutorErrors(string=stderr.decode())

    except Exception as e:
        print("Error while parsing executor response", file=sys.stderr)
        print(  # It should always be related to the last executed attack.
            f"Could not parse response from strategy: {config.strategy_name}, ",
            f"path: {config.path}, ",
            f"payload: {config.payloads}",
            file=sys.stderr,
        )
        print(f"{'Config':-^80}", file=sys.stderr)
        print(config.json(by_alias=True), file=sys.stderr)
        print(f"{'StdOut':-^80}", file=sys.stderr)
        print(stdout, file=sys.stderr)
        print(f"{'StdErr':-^80}", file=sys.stderr)
        print(stderr.decode("utf8"), file=sys.stderr)
        print(f"{'Exception':-^80}", file=sys.stderr)
        print(str(e))
        print(e)
        print("=" * 80, file=sys.stderr)

        raise

    return output, errors


class AttackScenario(BaseModel):
    strategy_name: str
    framework_handler: str
    web_root: str
    webroot_file: str
    path: str
    payload: str
    total_iterations: int
    payload_guid_phrase: str
    extra_app_info: Dict = None
    custom_config: Dict = None
    # TODO: is that even necessary once oneParamPerPayload goes away?
    injectable: Optional[Dict[str, str]] = None

    @property
    def scenario_id(self) -> int:
        return self.__hash__()

    def __hash__(self) -> int:
        return (
            int(
                hashlib.sha256(self.json(by_alias=True).encode("utf-8")).hexdigest(), 16
            )
            % 2**63
        )

    @property
    def config(self) -> SingleExecutorConfig:
        config = SingleExecutorConfig(
            framework_handler=self.framework_handler,
            web_root=self.web_root,
            webroot_file=self.webroot_file,
            strategy_name=self.strategy_name,
            # 'includes': self.getInstrumentation('App').getIncludesList()  # TODO?
            path=self.path,
            super_globals=SuperGlobalsConfig(
                _SERVER={
                    "HTTP_HOST": "127.0.0.1",
                    "HTTP_SEC_FETCH_SITE": "same-origin",
                }
            ),
            payloads=[self.payload],
            global_targets=[],  # TODO: implement
            global_exclude=[],  # TODO: Implement
            fuzz_skip_keys=SkipFuzzingKeysConfig(
                _SERVER=[
                    "HTTP_CONTENT_ENCODING",
                    "HTTP_X_HTTP_METHOD_OVERRIDE",
                    "HTTP_AUTHORIZATION",
                ],
                _GET=[],
                _POST=[],
                _COOKIE=[],
                _FILES=[],
            ),  # TODO: extract?
            oneParamPerPayload=False,  # TODO: Config
            iterations=self.total_iterations,
            injectable=self.injectable,
            PAYLOAD_GUID_phrase=self.payload_guid_phrase,
            extra_app_info=self.extra_app_info,
        )
        config = self.apply_custom_config(config)

        return config

    def apply_custom_config(self, config: SingleExecutorConfig):
        config = config.dict(by_alias=True)
        to_overwrite = (
            "super_globals",
            "global_targets",
            "global_exclude",
            "fuzz_skip_keys",
        )

        self.custom_config = {
            main_field: val
            for main_field, val in self.custom_config.items()
            if main_field in to_overwrite
        }
        # Update config based on the custom framework requirements passed by app_info
        for main_field in self.custom_config:
            for sub_field in self.custom_config[main_field]:
                if sub_field in config[main_field]:
                    # If the type doesn't match, just skip.
                    if type(config[main_field][sub_field]) != type(
                        self.custom_config[main_field][sub_field]
                    ):
                        print(
                            f"Custom & default config[{main_field}][{sub_field}] type mismatch. Default type: {type(config[main_field][sub_field])} != Custom type: {type(self.custom_config[main_field][sub_field])}. Skipping."
                        )
                        continue
                    if isinstance(config[main_field][sub_field], list):
                        config[main_field][sub_field].extend(
                            v
                            for v in self.custom_config[main_field][sub_field]
                            if v not in config[main_field][sub_field]
                        )
                        pass
                    elif isinstance(config[main_field][sub_field], dict):
                        for final_field in self.custom_config[main_field][sub_field]:
                            config[main_field][sub_field][
                                final_field
                            ] = self.custom_config[main_field][sub_field][final_field]
                else:
                    config[main_field][sub_field] = self.custom_config[main_field][
                        sub_field
                    ]
                pass

        config = SingleExecutorConfig.parse_obj(config)
        return config

    @property
    def unfuzzable_parameters(self) -> List[Tuple[str, str]]:
        config = self.config
        unfuzzable = [
            ("_SERVER", value) for value in config.super_globals.SERVER.keys()
        ]
        skip_keys = [("_SERVER", value) for value in config.fuzz_skip_keys.SERVER]

        return list(set(unfuzzable + skip_keys))

    async def execute_once(self, iteration: int) -> IterationResult:
        output, errors = await exec_single_executor(self.config)

        return IterationResult(
            scenario=self,
            iteration=iteration,
            output=output,
            errors=errors,
        )

    async def execute_all(self) -> List[IterationResult]:
        coros = [self.execute_once(i) for i in range(self.total_iterations)]
        results = await asyncio.gather(*coros, return_exceptions=True)
        return [
            result for result in results if isinstance(result, IterationResult)
        ]  # We sort of ignore all exceptions that happened here


IterationResult.update_forward_refs()

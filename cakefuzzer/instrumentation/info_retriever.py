"""
Wrapper for app_info.php
"""

import asyncio
import json
from pathlib import Path
from typing import Any, Dict, List, Union


class AppInfo:
    def __init__(self, webroot: Path):
        self.webroot: Path = webroot
        self.script_path = self._get_script_path()

    @staticmethod
    def _get_script_path() -> Path:
        """
        get path to script app_info.php.
        """
        return Path(__file__).parent.parent.absolute() / "phpfiles" / "app_info.php"

    @staticmethod
    def _convert_to_list(raw_output: str) -> Union[List[str], Dict[str, Any]]:
        try:
            return json.loads(raw_output)  # type: ignore
        except json.decoder.JSONDecodeError:
            return [
                i for i in raw_output.splitlines() if not i == ""
            ]  # omit empty lines

    async def _call_app_info(
        self, params: List[str]
    ) -> Union[List[str], Dict[str, Any]]:
        """
        make a call to the php script app_info.php.
        """
        command = ["php", str(self.script_path), str(self.webroot) + "/", *params]
        # output = subprocess.check_output(command).decode()
        proc = await asyncio.create_subprocess_exec(
            *command,
            stdin=asyncio.subprocess.PIPE,
            stdout=asyncio.subprocess.PIPE,
            stderr=asyncio.subprocess.PIPE,
        )

        output, error = await proc.communicate()
        output = self._convert_to_list(output.decode())
        if "error" in output and output["error"] is not None:
            print(f"[-] Error detected by app_info: {output['error']}")
            exit()
        return output

    @property
    async def paths(self) -> Dict[str, List[str]]:
        """
        Call '$ app_info.php get_paths'.
        """
        return await self._call_app_info(["get_paths"])  # type: ignore

    @property
    async def routes(self) -> List[str]:
        """
        Call '$ app_info.php get_routes'.
        """
        return await self._call_app_info(["get_routes"])  # type: ignore

    @property
    async def controllers_all_info(self) -> List[str]:
        """
        Call '$ app_info.php get_controllers_actions_arguments json'.
        """
        return await self._call_app_info(["get_controllers_actions_arguments", "json"])  # type: ignore # noqa: E501

    @property
    async def controllers(self) -> List[str]:
        """
        Call '$ app_info.php get_controllers raw'.
        """
        return await self._call_app_info(["get_controllers", "raw"])  # type: ignore

    @property
    async def actions(self) -> Dict[str, List[str]]:
        """
        Call '$ app_info.php get_actions json'.
        """
        return await self._call_app_info(["get_actions", "json"])  # type: ignore

    @property
    async def plugins(self) -> List[str]:
        """
        Call '$ app_info.php get_plugins raw'.
        """
        return await self._call_app_info(["get_plugins", "raw"])  # type: ignore

    @property
    async def cakephp_path(self) -> Path:
        """
        Call '$ app_info.php get_cakephp_path json'.
        """
        output = await self._call_app_info(["get_cakephp_info", "json"])
        return Path(output["cake_path"])

    @property
    async def log_paths(self) -> Path:
        """
        Call '$ app_info.php get_cakephp_path json'.
        """
        output = await self._call_app_info(["get_log_paths", "json"])
        return [Path(p) for p in output]

    @property
    async def cakephp_version(self) -> str:
        """
        Call '$ app_info.php get_cakephp_path json'.
        """
        output = await self._call_app_info(["get_cakephp_info", "json"])
        return output["cake_version"]

    @property
    async def app_dir(self) -> Path:
        """
        Call '$ app_info.php get_cakephp_path json'.
        """
        output = await self._call_app_info(["get_cakephp_info", "json"])
        return Path(output["app_dir"])

    @property
    async def users(self) -> List[str]:
        """
        Call '$ app_info.php get_users json'.
        """
        return await self._call_app_info(["get_users", "json"])  # type: ignore

    @property
    async def db_info(self) -> Dict[str, Any]:
        """
        Call '$ app_info.php db_info json'.
        """
        return await self._call_app_info(["get_db_info", "json"])  # type: ignore

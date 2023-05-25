import os
from pathlib import Path
from typing import List

from cakefuzzer.instrumentation import Instrumentation, apply, check, revert
from cakefuzzer.instrumentation.copy import CopyInstrumentation
from cakefuzzer.instrumentation.info_retriever import AppInfo
from cakefuzzer.instrumentation.patch import PatchInstrumentation
from cakefuzzer.settings import load_webroot_settings
from cakefuzzer.settings.instrumentation import InstrumentationSettings


class Instrumentator:
    def __init__(self, webroot_dir: Path) -> None:
        self.webroot_dir = webroot_dir

        self.settings: InstrumentationSettings = None

    async def _load_settings(self) -> None:
        app_info = AppInfo(self.webroot_dir)
        webroot_settings = load_webroot_settings()

        os.environ["FRAMEWORK_PATH"] = str(await app_info.framework_path)
        os.environ["APP_DIR"] = str(await app_info.app_dir)
        os.environ["WEBROOT_DIR"] = str(self.webroot_dir)
        os.environ["INSTRUMENTATION_INI"] = str(webroot_settings.instrumentation_ini)

        self.settings = InstrumentationSettings(
            _env_file=os.environ["INSTRUMENTATION_INI"]
        )

    async def _load_settings_patches(self) -> List[Instrumentation]:
        return self.settings.patches

    async def _load_settings_overrides(self) -> List[Instrumentation]:
        return await self.settings.file_overrides

    async def _load_framework_version_patches(self, major_version: int) -> None:
        app_info = AppInfo(self.webroot_dir)

        version_dir = (
            self.settings.patch_dir / await app_info.framework_name / str(major_version)
        )

        app_dir = version_dir / "APP_DIR"
        patches_framework_path = version_dir / "FRAMEWORK_PATH"
        webroot_dir = version_dir / "WEBROOT"

        patches = []

        framework_app_dir = await app_info.app_dir
        framework_path = await app_info.framework_path

        for file in app_dir.rglob("*.patch"):
            original = file.relative_to(app_dir)
            original = original.parent / original.stem

            patch = PatchInstrumentation(
                patch=file, original=framework_app_dir / original
            )
            patches.append(patch)

        for file in patches_framework_path.rglob("*.patch"):
            original = file.relative_to(patches_framework_path)
            original = original.parent / original.stem

            patch = PatchInstrumentation(patch=file, original=framework_path / original)
            patches.append(patch)

        for file in webroot_dir.rglob("*.patch"):
            original = file.relative_to(webroot_dir)
            original = original.parent / original.stem

            patch = PatchInstrumentation(
                patch=file, original=self.webroot_dir / original
            )
            patches.append(patch)

        # print("Patches", patches)

        return patches

    async def _load_framework_version_copies(self, major_version: int) -> None:
        app_info = AppInfo(self.webroot_dir)
        version_dir = (
            self.settings.patch_dir / await app_info.framework_name / str(major_version)
        )

        app_dir = version_dir / "APP_DIR"
        patches_framework_path = version_dir / "FRAMEWORK_PATH"
        webroot_dir = version_dir / "WEBROOT"

        copies = []

        framework_app_dir = await app_info.app_dir
        framework_path = await app_info.framework_path

        for file in app_dir.rglob("*.php"):
            original = file.relative_to(app_dir)

            copy = CopyInstrumentation(src=file, dst=framework_app_dir / original)
            copies.append(copy)

        for file in patches_framework_path.rglob("*.php"):
            original = file.relative_to(patches_framework_path)

            copy = CopyInstrumentation(src=file, dst=framework_path / original)
            copies.append(copy)

        for file in webroot_dir.rglob("*.php"):
            original = file.relative_to(webroot_dir)

            copy = CopyInstrumentation(src=file, dst=self.webroot_dir / original)
            copies.append(copy)

        return copies

    async def _load_instrumentations(self):
        await self._load_settings()

        settings_patches = await self._load_settings_patches()
        settings_overrides = await self._load_settings_overrides()
        settings_copies = self.settings.copies

        app_info = AppInfo(self.webroot_dir)
        version = await app_info.framework_version

        major_version = int(version.split(".")[0])

        framework_patches = await self._load_framework_version_patches(major_version)
        framework_copies = await self._load_framework_version_copies(major_version)

        return (
            settings_overrides,
            settings_patches + framework_patches,
            settings_copies + framework_copies,
        )

    async def apply(self) -> None:
        overrides, patches, copies = await self._load_instrumentations()

        _, unapplied = await check(*overrides)
        unapplied = await apply(*unapplied)
        print("Overrides Applied", len(unapplied))

        _, unapplied = await check(*patches)
        unapplied = await apply(*unapplied)
        print("Patches Applied", len(unapplied))

        _, unapplied = await check(*copies)
        unapplied = await apply(*unapplied)
        print("Copies Applied", len(unapplied))

    async def revert(self) -> None:
        overrides, patches, copies = await self._load_instrumentations()

        applied, _ = await check(*overrides)
        await revert(*applied)
        print("Overrides Reverted", len(applied))

        applied, _ = await check(*patches)
        await revert(*applied)
        print("Patches Reverted", len(applied))

        applied, _ = await check(*copies)
        await revert(*applied)
        print("Copies Reverted", len(applied))

    async def is_applied(self) -> None:
        overrides, patches, copies = await self._load_instrumentations()

        applied, unapplied = await check(*overrides)
        print("Applied / Unapplied")
        print(f"Overrides: {len(applied)}/{len(unapplied)}")

        applied, unapplied = await check(*patches)
        print(f"Patches: {len(applied)}/{len(unapplied)}")

        applied, unapplied = await check(*copies)
        print(f"Copies: {len(applied)}/{len(unapplied)}")

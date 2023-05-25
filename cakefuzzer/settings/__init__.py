import os

from cakefuzzer.instrumentation.info_retriever import AppInfo
from cakefuzzer.settings.instrumentation import InstrumentationSettings
from cakefuzzer.settings.webroot import WebrootSettings


def load_webroot_settings() -> WebrootSettings:
    return WebrootSettings()


async def load_instrumentation_settings() -> InstrumentationSettings:
    webroot = load_webroot_settings()
    app_info = AppInfo(webroot.webroot_dir)

    os.environ["FRAMEWORK_PATH"] = str(await app_info.cakephp_path)
    os.environ["APP_DIR"] = str(await app_info.app_dir)
    return InstrumentationSettings()

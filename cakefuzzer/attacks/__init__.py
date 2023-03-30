from typing import Any, Dict

from pydantic import BaseModel, Field


class SuperGlobals(BaseModel):
    GET: Dict[str, Any] = Field(alias="_GET")
    POST: Dict[str, Any] = Field(alias="_POST")
    REQUEST: Dict[str, Any] = Field(alias="_REQUEST")
    COOKIE: Dict[str, Any] = Field(alias="_COOKIE")
    FILES: Dict[str, Any] = Field(alias="_FILES")
    SERVER: Dict[str, Any] = Field(alias="_SERVER")

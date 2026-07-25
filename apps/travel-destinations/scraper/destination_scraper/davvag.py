from __future__ import annotations

import re
from difflib import SequenceMatcher
from typing import Any
from urllib.parse import urlparse

import requests


class DavvagApiError(RuntimeError):
    """A DAVVAG transport, authentication, or validation error."""


def _normal_name(value: str) -> str:
    return re.sub(r"[^a-z0-9]+", " ", value.casefold()).strip()


class DavvagClient:
    def __init__(
        self,
        base_url: str,
        timeout: float = 30,
        verify_tls: bool = True,
        session: requests.Session | None = None,
    ) -> None:
        parsed = urlparse(base_url)
        if parsed.scheme not in {"http", "https"} or not parsed.netloc:
            raise ValueError("DAVVAG base URL must be an HTTP or HTTPS URL.")
        self.base_url = base_url.rstrip("/")
        self.timeout = timeout
        self.verify_tls = verify_tls
        self.session = session or requests.Session()

    def close(self) -> None:
        self.session.close()

    def __enter__(self) -> "DavvagClient":
        return self

    def __exit__(self, *_: object) -> None:
        self.close()

    def _service_url(self, app: str, component: str, operation: str) -> str:
        return f"{self.base_url}/components/{app}/{component}/service/{operation}"

    @staticmethod
    def _error_message(payload: Any, fallback: str) -> str:
        if isinstance(payload, str) and payload.strip():
            return payload.strip()
        if isinstance(payload, dict):
            for key in ("message", "error", "ERROR"):
                if payload.get(key):
                    return str(payload[key])
        return fallback

    def _request(
        self,
        method: str,
        app: str,
        component: str,
        operation: str,
        **kwargs: Any,
    ) -> Any:
        try:
            response = self.session.request(
                method,
                self._service_url(app, component, operation),
                timeout=self.timeout,
                verify=self.verify_tls,
                **kwargs,
            )
        except requests.RequestException as exc:
            if operation == "login":
                raise DavvagApiError(
                    "DAVVAG login request failed. Check the base URL and server."
                ) from exc
            raise DavvagApiError(f"DAVVAG request failed: {exc}") from exc

        try:
            envelope = response.json()
        except ValueError as exc:
            raise DavvagApiError(
                f"DAVVAG returned HTTP {response.status_code} with non-JSON content."
            ) from exc
        if not isinstance(envelope, dict) or "success" not in envelope:
            raise DavvagApiError("DAVVAG returned an invalid response envelope.")
        if not envelope.get("success"):
            raise DavvagApiError(
                self._error_message(
                    envelope.get("result"),
                    f"DAVVAG operation {operation} failed with HTTP {response.status_code}.",
                )
            )
        return envelope.get("result")

    def login(self, email: str, password: str) -> dict[str, Any]:
        if not email or not password:
            raise ValueError("DAVVAG email and password are required.")
        domain = urlparse(self.base_url).hostname or "localhost"
        result = self._request(
            "GET",
            "userapp",
            "login-handler",
            "login",
            params={"email": email, "password": password, "domain": domain},
        )
        if not isinstance(result, dict) or not result.get("token"):
            raise DavvagApiError("DAVVAG login did not return an authentication token.")
        return result

    def capabilities(self) -> dict[str, Any]:
        result = self._request(
            "GET", "travel-destinations", "api", "Capabilities"
        )
        if not isinstance(result, dict):
            raise DavvagApiError("Capabilities returned an invalid object.")
        return result

    def categories(self) -> list[dict[str, Any]]:
        result = self._request(
            "GET", "travel-destinations", "api", "GetCategories"
        )
        return result if isinstance(result, list) else []

    def amenities(self) -> list[dict[str, Any]]:
        result = self._request(
            "GET", "travel-destinations", "api", "GetAmenities"
        )
        return result if isinstance(result, list) else []

    def save_draft(self, payload: dict[str, Any]) -> dict[str, Any]:
        result = self._request(
            "POST",
            "travel-destinations",
            "api",
            "SaveDestinationDraft",
            json=payload,
        )
        if not isinstance(result, dict) or not result.get("id"):
            raise DavvagApiError("Draft save did not return a destination ID.")
        return result

    def submit_destination(self, payload: dict[str, Any]) -> dict[str, Any]:
        result = self._request(
            "POST",
            "travel-destinations",
            "api",
            "SubmitDestination",
            json=payload,
        )
        if not isinstance(result, dict):
            raise DavvagApiError("Destination submission returned an invalid object.")
        return result

    def my_submissions(self, page_size: int = 100) -> list[dict[str, Any]]:
        result = self._request(
            "POST",
            "travel-destinations",
            "api",
            "GetMySubmissions",
            json={"page": 0, "pageSize": max(1, min(100, page_size))},
        )
        return result.get("items", []) if isinstance(result, dict) else []

    def public_search(self, keyword: str) -> list[dict[str, Any]]:
        result = self._request(
            "POST",
            "travel-destinations",
            "api",
            "SearchDestinations",
            json={"keyword": keyword, "sort": "name", "page": 0, "pageSize": 50},
        )
        return result.get("items", []) if isinstance(result, dict) else []

    def likely_duplicates(self, name: str) -> list[dict[str, Any]]:
        target = _normal_name(name)
        candidates = self.public_search(name) + self.my_submissions()
        duplicates: list[dict[str, Any]] = []
        seen_ids: set[int] = set()
        for item in candidates:
            candidate = _normal_name(str(item.get("name", "")))
            ratio = SequenceMatcher(None, target, candidate).ratio() if candidate else 0
            item_id = int(item.get("id") or 0)
            if item_id not in seen_ids and (candidate == target or ratio >= 0.92):
                seen_ids.add(item_id)
                copy = dict(item)
                copy["duplicate_similarity"] = round(ratio, 3)
                duplicates.append(copy)
        return duplicates

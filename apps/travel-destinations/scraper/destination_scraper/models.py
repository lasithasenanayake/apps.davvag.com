from __future__ import annotations

from dataclasses import asdict, dataclass, field
from typing import Any
from urllib.parse import urlparse


class ValidationError(ValueError):
    """Raised when researched data is unsafe or incomplete."""


def is_http_url(value: str) -> bool:
    try:
        parsed = urlparse(str(value).strip())
    except ValueError:
        return False
    return parsed.scheme in {"http", "https"} and bool(parsed.netloc)


def _text(value: Any, limit: int) -> str:
    return str(value or "").replace("\x00", "").strip()[:limit]


def _string_list(value: Any, limit: int = 50) -> list[str]:
    if not isinstance(value, list):
        return []
    output: list[str] = []
    seen: set[str] = set()
    for item in value[:limit]:
        text = _text(item, 1000)
        key = text.casefold()
        if text and key not in seen:
            seen.add(key)
            output.append(text)
    return output


def _optional_float(value: Any) -> float | None:
    if value is None or value == "":
        return None
    try:
        return float(value)
    except (TypeError, ValueError) as exc:
        raise ValidationError(f"Expected a number, received {value!r}.") from exc


@dataclass(slots=True)
class EvidenceSource:
    url: str
    title: str = ""
    claims: list[str] = field(default_factory=list)

    @classmethod
    def from_dict(cls, value: dict[str, Any]) -> "EvidenceSource":
        url = _text(value.get("url"), 2000)
        if not is_http_url(url):
            raise ValidationError(f"Invalid evidence URL: {url!r}")
        return cls(
            url=url,
            title=_text(value.get("title"), 300),
            claims=_string_list(value.get("claims"), 20),
        )


@dataclass(slots=True)
class DestinationResearch:
    name: str
    short_summary: str
    description_markdown: str
    primary_language: str
    tags: list[str]
    category_slugs: list[str]
    amenity_slugs: list[str]
    latitude: float | None
    longitude: float | None
    coordinate_accuracy: float | None
    province: str
    district: str
    nearest_town: str
    village: str
    location_description: str
    access_road_description: str
    public_transport_instructions: str
    parking_information: str
    distance_from_town_km: float | None
    road_condition: str
    requires_4wd: bool
    walking_distance_km: float | None
    stay_subtype: str
    price_range: str
    responsible_travel_markdown: str
    safety_warnings: str
    confidence: str
    ready_to_submit: bool
    research_warnings: list[str]
    sources: list[EvidenceSource]
    response_id: str = ""

    @classmethod
    def from_dict(cls, value: dict[str, Any]) -> "DestinationResearch":
        if not isinstance(value, dict):
            raise ValidationError("OpenAI returned a non-object destination.")
        latitude = _optional_float(value.get("latitude"))
        longitude = _optional_float(value.get("longitude"))
        if (latitude is None) != (longitude is None):
            raise ValidationError("Latitude and longitude must both be present or both be null.")
        if latitude is not None and not (-90 <= latitude <= 90):
            raise ValidationError("Latitude is outside -90 to 90.")
        if longitude is not None and not (-180 <= longitude <= 180):
            raise ValidationError("Longitude is outside -180 to 180.")

        confidence = _text(value.get("confidence"), 20).lower()
        if confidence not in {"high", "medium", "low"}:
            confidence = "low"

        sources: list[EvidenceSource] = []
        for item in value.get("sources", []) if isinstance(value.get("sources"), list) else []:
            if isinstance(item, dict):
                try:
                    sources.append(EvidenceSource.from_dict(item))
                except ValidationError:
                    continue

        return cls(
            name=_text(value.get("name"), 255),
            short_summary=_text(value.get("short_summary"), 255),
            description_markdown=_text(value.get("description_markdown"), 240000),
            primary_language=_text(value.get("primary_language"), 20) or "en",
            tags=_string_list(value.get("tags"), 30),
            category_slugs=_string_list(value.get("category_slugs"), 20),
            amenity_slugs=_string_list(value.get("amenity_slugs"), 50),
            latitude=latitude,
            longitude=longitude,
            coordinate_accuracy=_optional_float(value.get("coordinate_accuracy")),
            province=_text(value.get("province"), 120),
            district=_text(value.get("district"), 120),
            nearest_town=_text(value.get("nearest_town"), 180),
            village=_text(value.get("village"), 180),
            location_description=_text(value.get("location_description"), 2000),
            access_road_description=_text(value.get("access_road_description"), 2000),
            public_transport_instructions=_text(
                value.get("public_transport_instructions"), 2000
            ),
            parking_information=_text(value.get("parking_information"), 1000),
            distance_from_town_km=_optional_float(value.get("distance_from_town_km")),
            road_condition=_text(value.get("road_condition"), 500),
            requires_4wd=bool(value.get("requires_4wd")),
            walking_distance_km=_optional_float(value.get("walking_distance_km")),
            stay_subtype=_text(value.get("stay_subtype"), 50),
            price_range=_text(value.get("price_range"), 100),
            responsible_travel_markdown=_text(
                value.get("responsible_travel_markdown"), 5000
            ),
            safety_warnings=_text(value.get("safety_warnings"), 5000),
            confidence=confidence,
            ready_to_submit=bool(value.get("ready_to_submit")),
            research_warnings=_string_list(value.get("research_warnings"), 30),
            sources=sources,
        )

    def blockers(self, allow_low_confidence: bool = False) -> list[str]:
        blockers: list[str] = []
        if not self.ready_to_submit:
            blockers.append("The research model marked the record as not ready.")
        if not self.name:
            blockers.append("Destination name is missing.")
        if not self.short_summary:
            blockers.append("Short summary is missing.")
        if not self.description_markdown:
            blockers.append("Description is missing.")
        if not self.category_slugs:
            blockers.append("No supported category was identified.")
        if self.latitude is None or self.longitude is None:
            blockers.append("Verified coordinates are missing.")
        if len(self.sources) < 2:
            blockers.append("At least two source URLs are required.")
        if self.confidence == "low" and not allow_low_confidence:
            blockers.append("Research confidence is low.")
        return blockers

    def to_dict(self) -> dict[str, Any]:
        return asdict(self)


DESTINATION_JSON_SCHEMA: dict[str, Any] = {
    "type": "object",
    "additionalProperties": False,
    "properties": {
        "name": {"type": "string"},
        "short_summary": {"type": "string"},
        "description_markdown": {"type": "string"},
        "primary_language": {"type": "string"},
        "tags": {"type": "array", "items": {"type": "string"}},
        "category_slugs": {"type": "array", "items": {"type": "string"}},
        "amenity_slugs": {"type": "array", "items": {"type": "string"}},
        "latitude": {"type": ["number", "null"]},
        "longitude": {"type": ["number", "null"]},
        "coordinate_accuracy": {"type": ["number", "null"]},
        "province": {"type": "string"},
        "district": {"type": "string"},
        "nearest_town": {"type": "string"},
        "village": {"type": "string"},
        "location_description": {"type": "string"},
        "access_road_description": {"type": "string"},
        "public_transport_instructions": {"type": "string"},
        "parking_information": {"type": "string"},
        "distance_from_town_km": {"type": ["number", "null"]},
        "road_condition": {"type": "string"},
        "requires_4wd": {"type": "boolean"},
        "walking_distance_km": {"type": ["number", "null"]},
        "stay_subtype": {"type": "string"},
        "price_range": {"type": "string"},
        "responsible_travel_markdown": {"type": "string"},
        "safety_warnings": {"type": "string"},
        "confidence": {"type": "string", "enum": ["high", "medium", "low"]},
        "ready_to_submit": {"type": "boolean"},
        "research_warnings": {"type": "array", "items": {"type": "string"}},
        "sources": {
            "type": "array",
            "items": {
                "type": "object",
                "additionalProperties": False,
                "properties": {
                    "url": {"type": "string"},
                    "title": {"type": "string"},
                    "claims": {"type": "array", "items": {"type": "string"}},
                },
                "required": ["url", "title", "claims"],
            },
        },
    },
    "required": [
        "name",
        "short_summary",
        "description_markdown",
        "primary_language",
        "tags",
        "category_slugs",
        "amenity_slugs",
        "latitude",
        "longitude",
        "coordinate_accuracy",
        "province",
        "district",
        "nearest_town",
        "village",
        "location_description",
        "access_road_description",
        "public_transport_instructions",
        "parking_information",
        "distance_from_town_km",
        "road_condition",
        "requires_4wd",
        "walking_distance_km",
        "stay_subtype",
        "price_range",
        "responsible_travel_markdown",
        "safety_warnings",
        "confidence",
        "ready_to_submit",
        "research_warnings",
        "sources",
    ],
}

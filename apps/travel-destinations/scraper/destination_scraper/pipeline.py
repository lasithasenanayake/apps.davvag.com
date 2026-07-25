from __future__ import annotations

from dataclasses import asdict, dataclass
from typing import Any, Callable, Literal

from .davvag import DavvagApiError, DavvagClient
from .models import DestinationResearch, EvidenceSource, ValidationError
from .researcher import DestinationResearcher


RunMode = Literal["research", "draft", "submit"]
ProgressCallback = Callable[[str], None]


def _reference_map(items: list[dict[str, Any]]) -> dict[str, int]:
    output: dict[str, int] = {}
    for item in items:
        slug = str(item.get("slug", "")).strip().casefold()
        item_id = int(item.get("id") or 0)
        if slug and item_id > 0:
            output[slug] = item_id
    return output


def _markdown_title(value: str) -> str:
    return (
        value.replace("[", "")
        .replace("]", "")
        .replace("\r", " ")
        .replace("\n", " ")
        .strip()
    )


def _add_sources(description: str, sources: list[EvidenceSource]) -> str:
    if not sources:
        return description
    lines = ["", "", "## Research sources", ""]
    for source in sources[:20]:
        title = _markdown_title(source.title)
        lines.append(f"- {title + ': ' if title else ''}<{source.url}>")
    return (description.rstrip() + "\n".join(lines)).strip()


@dataclass(slots=True)
class PipelineResult:
    mode: RunMode
    research: dict[str, Any]
    payload: dict[str, Any] | None
    duplicates: list[dict[str, Any]]
    draft: dict[str, Any] | None
    submission: dict[str, Any] | None

    def to_dict(self) -> dict[str, Any]:
        return asdict(self)


class DestinationPipeline:
    def __init__(
        self,
        davvag: DavvagClient,
        researcher: DestinationResearcher,
        email: str,
        password: str,
        location_privacy: str = "approved_only",
        progress: ProgressCallback | None = None,
    ) -> None:
        if location_privacy not in {
            "exact_public",
            "approximate_public",
            "hidden_sensitive",
            "approved_only",
        }:
            raise ValueError("Unsupported destination location privacy.")
        self.davvag = davvag
        self.researcher = researcher
        self.email = email
        self.password = password
        self.location_privacy = location_privacy
        self.progress = progress or (lambda _message: None)

    def _report(self, message: str) -> None:
        safe_message = " ".join(
            "".join(character if character.isprintable() else " " for character in message)
            .split()
        )
        self.progress(safe_message)

    def _payload(
        self,
        research: DestinationResearch,
        categories: list[dict[str, Any]],
        amenities: list[dict[str, Any]],
    ) -> dict[str, Any]:
        category_map = _reference_map(categories)
        amenity_map = _reference_map(amenities)
        category_ids = [
            category_map[slug.casefold()]
            for slug in research.category_slugs
            if slug.casefold() in category_map
        ]
        amenity_ids = [
            amenity_map[slug.casefold()]
            for slug in research.amenity_slugs
            if slug.casefold() in amenity_map
        ]
        if not category_ids:
            raise ValidationError(
                "Research did not identify a category present in DAVVAG."
            )

        return {
            "id": None,
            "name": research.name,
            "short_summary": research.short_summary,
            "description_markdown": _add_sources(
                research.description_markdown, research.sources
            ),
            "primary_language": research.primary_language,
            "tags": ", ".join(research.tags),
            "category_ids": list(dict.fromkeys(category_ids)),
            "amenity_ids": list(dict.fromkeys(amenity_ids)),
            "latitude": research.latitude,
            "longitude": research.longitude,
            "coordinate_accuracy": max(0, research.coordinate_accuracy or 0),
            "location_privacy": self.location_privacy,
            "province": research.province,
            "district": research.district,
            "nearest_town": research.nearest_town,
            "village": research.village,
            "location_description": research.location_description,
            "access_road_description": research.access_road_description,
            "public_transport_instructions": research.public_transport_instructions,
            "parking_information": research.parking_information,
            "distance_from_town_km": max(0, research.distance_from_town_km or 0),
            "road_condition": research.road_condition,
            "requires_4wd": research.requires_4wd,
            "walking_distance_km": max(0, research.walking_distance_km or 0),
            "stay_subtype": research.stay_subtype,
            "price_range": research.price_range,
            "responsible_travel_markdown": research.responsible_travel_markdown,
            "safety_warnings": research.safety_warnings,
            "camping_info": {},
            "hiking_info": {},
            "stay_info": {},
            "village_info": {},
        }

    def run(
        self,
        destination_name: str,
        seed_urls: list[str] | None = None,
        mode: RunMode = "submit",
        force_duplicate: bool = False,
        allow_low_confidence: bool = False,
    ) -> PipelineResult:
        if mode not in {"research", "draft", "submit"}:
            raise ValueError("Mode must be research, draft, or submit.")

        self._report(
            f"Starting {mode} workflow for {destination_name.strip()!r}."
        )
        self._report("Logging in to DAVVAG...")
        self.davvag.login(self.email, self.password)
        self._report("Login successful. Checking the linked DAVVAG profile...")
        capabilities = self.davvag.capabilities()
        if not capabilities.get("authenticated") or not capabilities.get("profileId"):
            raise DavvagApiError(
                "The DAVVAG account is logged in but has no active linked profile."
            )
        self._report("Profile verified. Loading destination categories and amenities...")

        categories = self.davvag.categories()
        amenities = self.davvag.amenities()
        if not categories:
            raise DavvagApiError("DAVVAG has no active destination categories.")
        self._report(
            f"Loaded {len(categories)} categories and {len(amenities)} amenities."
        )

        self._report(
            "Asking OpenAI to search the web and prepare a sourced destination record..."
        )
        research = self.researcher.research(
            destination_name=destination_name,
            categories=categories,
            amenities=amenities,
            seed_urls=seed_urls or [],
        )
        self._report(
            f"Research complete: {len(research.sources)} verified sources, "
            f"confidence {research.confidence!r}."
        )
        for index, source in enumerate(research.sources, start=1):
            self._report(f"Verified source {index}: {source.url}")
        if research.research_warnings:
            self._report(
                f"Research returned {len(research.research_warnings)} warning(s); "
                "see research_warnings in the final JSON."
            )
        self._report("Building and validating the DAVVAG destination payload...")
        payload = self._payload(research, categories, amenities)
        self._report("Checking DAVVAG for possible duplicate destinations...")
        duplicates = self.davvag.likely_duplicates(research.name)
        if duplicates:
            self._report(f"Found {len(duplicates)} possible duplicate(s).")
        else:
            self._report("No likely duplicates found.")

        if mode != "research" and duplicates and not force_duplicate:
            raise ValidationError(
                "A likely duplicate destination already exists. Re-run with "
                "--force-duplicate only after reviewing the existing records."
            )

        if mode in {"draft", "submit"}:
            self._report("Running automatic-write safety checks...")
            blockers = research.blockers(allow_low_confidence=allow_low_confidence)
            if blockers:
                raise ValidationError(
                    "Automatic write blocked: " + " ".join(blockers)
                )
            self._report("Automatic-write safety checks passed.")

        if mode == "research":
            self._report("Research-only workflow complete; nothing was written to DAVVAG.")
            return PipelineResult(
                mode=mode,
                research=research.to_dict(),
                payload=payload,
                duplicates=duplicates,
                draft=None,
                submission=None,
            )

        self._report("Saving the destination as a DAVVAG draft...")
        draft = self.davvag.save_draft(payload)
        payload["id"] = int(draft["id"])
        self._report(f"Draft saved with destination ID {payload['id']}.")
        if mode == "draft":
            self._report("Draft-only workflow complete; the draft was not submitted.")
            return PipelineResult(
                mode=mode,
                research=research.to_dict(),
                payload=payload,
                duplicates=duplicates,
                draft=draft,
                submission=None,
            )

        self._report("Submitting the saved draft for administrator review...")
        submission = self.davvag.submit_destination(payload)
        if submission.get("status") != "Pending Review":
            raise DavvagApiError(
                f"Unexpected destination status: {submission.get('status')!r}"
            )
        self._report(
            f"Submission complete: destination {payload['id']} is Pending Review."
        )
        return PipelineResult(
            mode=mode,
            research=research.to_dict(),
            payload=payload,
            duplicates=duplicates,
            draft=draft,
            submission=submission,
        )

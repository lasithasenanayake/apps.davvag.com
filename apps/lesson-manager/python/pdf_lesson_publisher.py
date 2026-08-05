#!/usr/bin/env python3
"""Plan and publish lesson-sized PDF ranges to DAVVAG Lesson Manager.

The command is dry-run by default. It detects lesson-opening pages, writes a
reviewable JSON manifest, and preserves the source typography by embedding a
split PDF for each lesson. Apply mode also registers the source PDF and any
license-compatible embedded fonts in the tenant's reusable assets namespace.
"""

from __future__ import annotations

import argparse
import hashlib
import io
import json
import logging
import mimetypes
import os
import re
import sys
import time
from collections import Counter
from dataclasses import dataclass
from pathlib import Path
from typing import Any, Iterable, Optional
from urllib.parse import quote

try:
    import fitz
    import requests
    from fontTools.ttLib import TTFont
except ImportError as exc:  # pragma: no cover
    raise SystemExit(
        "Missing dependency. Run: python -m pip install -r requirements.txt"
    ) from exc

from lesson_publisher import (
    ApiError,
    DavvagLessonAPI,
    DEFAULT_BASE_URL,
    DEFAULT_ENV_FILE,
    PublisherError,
    build_session,
    console_json,
    load_login_config,
    resolve_subject,
)


LOGGER = logging.getLogger("pdf_lesson_publisher")
FORMAT_VERSION = 1
ASSET_NAMESPACE = "lesson_manager_assets"
SAFE_NAME_RE = re.compile(r"[^A-Za-z0-9._-]+")
NUMBER_RE = re.compile(r"^\s*0*(\d{1,3})\s*[.)'’:-]?\s*$")
PREFIX_RE = re.compile(r"^\s*0*(\d{1,3})\s*[.)'’:-]\s*(\S.*)$")
SINHALA_RE = re.compile(r"[\u0D80-\u0DFF]")


@dataclass(frozen=True)
class Heading:
    page: int
    number: Optional[int]
    detected_title: str
    size: float
    method: str


@dataclass(frozen=True)
class PlannedLesson:
    key: str
    chapter_number: int
    chapter_title: str
    order: int
    title: str
    detected_title: str
    page_start: int
    page_end: int


class PdfState:
    def __init__(self, path: Path):
        self.path = path.resolve()
        self.data: dict[str, Any] = {}
        if self.path.is_file():
            try:
                value = json.loads(self.path.read_text(encoding="utf-8"))
            except (OSError, ValueError) as exc:
                raise PublisherError(f"Cannot read state file {self.path}: {exc}") from exc
            if not isinstance(value, dict):
                raise PublisherError(f"State file must contain a JSON object: {self.path}")
            self.data = value

    def initialize(self, *, source_sha256: str, base_url: str, subject_id: int) -> None:
        identity = {
            "format_version": FORMAT_VERSION,
            "source_sha256": source_sha256,
            "base_url": base_url.rstrip("/"),
            "subject_id": subject_id,
        }
        for key, value in identity.items():
            if key in self.data and self.data[key] != value:
                raise PublisherError(
                    f"State file {key} mismatch: expected {value!r}, found {self.data[key]!r}"
                )
            self.data.setdefault(key, value)
        self.data.setdefault("assets", {})
        self.data.setdefault("lessons", {})
        self.data.setdefault("contents", {})

    def save(self) -> None:
        self.path.parent.mkdir(parents=True, exist_ok=True)
        temporary = self.path.with_name(self.path.name + ".tmp")
        with temporary.open("w", encoding="utf-8", newline="\n") as handle:
            json.dump(self.data, handle, ensure_ascii=False, indent=2, sort_keys=True)
            handle.write("\n")
            handle.flush()
            os.fsync(handle.fileno())
        os.replace(temporary, self.path)


def sha256_bytes(value: bytes) -> str:
    return hashlib.sha256(value).hexdigest()


def sha256_file(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def safe_name(value: str) -> str:
    return SAFE_NAME_RE.sub("_", Path(value).name).strip("._") or "asset"


def clean_subset_name(value: str) -> str:
    return re.sub(r"^[A-Z]{6}\+", "", value or "").strip() or "EmbeddedFont"


def looks_legacy_encoded(value: str) -> bool:
    text = value.strip()
    if not text or SINHALA_RE.search(text):
        return False
    suspicious = sum(character in "%^;=<>[]" for character in text)
    non_ascii_latin = any(ord(character) > 127 for character in text)
    return suspicious >= 1 or non_ascii_latin or bool(re.search(r"\b(?:f|l|j|w)[A-Za-z%]{3,}", text))


def visible_spans(page: fitz.Page) -> list[dict[str, Any]]:
    result: list[dict[str, Any]] = []
    for block in page.get_text("dict").get("blocks", []):
        for line in block.get("lines", []):
            for span in line.get("spans", []):
                text = " ".join(str(span.get("text", "")).split())
                if text:
                    result.append(
                        {
                            "text": text,
                            "size": float(span.get("size", 0)),
                            "font": str(span.get("font", "")),
                            "bbox": tuple(span.get("bbox", (0, 0, 0, 0))),
                        }
                    )
    return result


def body_font_size(document: fitz.Document) -> float:
    counts: Counter[float] = Counter()
    for page in document:
        for span in visible_spans(page):
            if len(span["text"]) >= 4 and span["size"] >= 6:
                counts[round(span["size"] * 2) / 2] += min(len(span["text"]), 80)
    return counts.most_common(1)[0][0] if counts else 12.0


def heading_title(top: list[dict[str, Any]], number_span: dict[str, Any], threshold: float) -> str:
    number_y = number_span["bbox"][1]
    title_spans = [
        span
        for span in top
        if span is not number_span
        and span["size"] >= threshold
        and not NUMBER_RE.match(span["text"])
        and span["bbox"][1] <= number_y + 130
    ]
    if not title_spans:
        return ""
    largest = max(span["size"] for span in title_spans)
    title_spans = [span for span in title_spans if span["size"] >= largest - 1.0]
    title_spans.sort(key=lambda span: (round(span["bbox"][1], 1), span["bbox"][0]))
    return " ".join(span["text"] for span in title_spans[:4]).strip()


def longest_number_sequence(candidates: list[Heading]) -> list[Heading]:
    numbered = [candidate for candidate in candidates if candidate.number is not None]
    best: list[Heading] = []
    for start, candidate in enumerate(numbered):
        chain = [candidate]
        expected = int(candidate.number or 0) + 1
        for following in numbered[start + 1 :]:
            if following.page > chain[-1].page and following.number == expected:
                chain.append(following)
                expected += 1
        if len(chain) > len(best):
            best = chain
    return best


def detect_headings(document: fitz.Document) -> tuple[list[Heading], str, list[str]]:
    body_size = body_font_size(document)
    threshold = max(17.5, body_size * 1.45)
    numbered: list[Heading] = []
    style_rows: list[Heading] = []
    for index, page in enumerate(document):
        top = [span for span in visible_spans(page) if span["bbox"][1] <= page.rect.height * 0.27]
        if not top:
            continue
        top.sort(key=lambda span: (span["bbox"][1], span["bbox"][0]))
        prefix_found = False
        for span in top:
            prefix = PREFIX_RE.match(span["text"])
            if prefix and span["size"] >= threshold:
                numbered.append(Heading(index + 1, int(prefix.group(1)), prefix.group(2), span["size"], "numbered-heading"))
                prefix_found = True
                break
        if not prefix_found:
            for span in top:
                pure = NUMBER_RE.match(span["text"])
                if not pure or span["size"] < max(16.0, body_size * 1.25):
                    continue
                title = heading_title(top, span, threshold)
                if not title:
                    continue
                raw_number = int(pure.group(1))
                numbers = [raw_number]
                if raw_number >= 100 and 0 < raw_number % 100 < 100:
                    numbers.append(raw_number % 100)
                for number in numbers:
                    numbered.append(Heading(index + 1, number, title, span["size"], "numbered-heading"))

        textual = [
            span for span in top
            if len(span["text"]) > 2 and not NUMBER_RE.match(span["text"])
        ]
        if textual:
            largest = max(textual, key=lambda span: span["size"])
            if largest["size"] >= threshold:
                same_line = [
                    span for span in textual
                    if abs(span["size"] - largest["size"]) <= 1.0
                    and abs(span["bbox"][1] - largest["bbox"][1]) <= 42
                ]
                same_line.sort(key=lambda span: (span["bbox"][1], span["bbox"][0]))
                style_rows.append(Heading(index + 1, None, " ".join(row["text"] for row in same_line[:3]), largest["size"], "heading-style"))

    sequence = longest_number_sequence(numbered)
    warnings: list[str] = []
    if len(sequence) >= 2:
        headings = sequence
        confidence = "high" if len(sequence) >= 3 else "medium"
    else:
        size_groups: dict[int, list[Heading]] = {}
        for candidate in style_rows:
            size_groups.setdefault(int(round(candidate.size / 2.0) * 2), []).append(candidate)
        repeated = [rows for rows in size_groups.values() if len(rows) >= 2]
        headings = max(repeated, key=len) if repeated else style_rows[:1]
        confidence = "medium" if len(headings) >= 3 else "low"
        warnings.append("No sequential numbered lesson headings were found; repeated heading typography was used.")
    headings = sorted({heading.page: heading for heading in headings}.values(), key=lambda heading: heading.page)
    if not headings:
        headings = [Heading(1, 1, "Whole document", 0, "whole-document")]
        confidence = "low"
        warnings.append("No headings were detected; the whole PDF is one lesson.")
    if headings[0].page > 1:
        warnings.append(f"Pages 1-{headings[0].page - 1} are treated as front matter and are not published as a lesson.")
    return headings, confidence, warnings


def display_title(heading: Heading, end_page: int, ordinal: int) -> tuple[str, Optional[str]]:
    detected = heading.detected_title.strip()
    if detected and not looks_legacy_encoded(detected):
        prefix = f"{heading.number}. " if heading.number is not None and not re.match(r"^\d", detected) else ""
        return (prefix + detected)[:255], None
    title = f"Lesson {heading.number or ordinal:02d} (PDF pages {heading.page}-{end_page})"
    return title, "The extracted title uses a legacy font encoding; replace this fallback title in the manifest before approval."


def detect_manifest(source: Path) -> dict[str, Any]:
    source = source.absolute()
    if not source.is_file() or source.suffix.casefold() != ".pdf":
        raise PublisherError(f"Source must be an existing PDF file: {source}")
    document = fitz.open(source)
    if document.needs_pass:
        raise PublisherError("Encrypted PDFs are not supported")
    headings, confidence, warnings = detect_headings(document)
    chapters = []
    for index, heading in enumerate(headings):
        end_page = headings[index + 1].page - 1 if index + 1 < len(headings) else document.page_count
        title, title_warning = display_title(heading, end_page, index + 1)
        if title_warning:
            warnings.append(f"Page {heading.page}: {title_warning}")
        chapter_number = heading.number or index + 1
        chapters.append(
            {
                "number": chapter_number,
                "title": title,
                "page_start": heading.page,
                "page_end": end_page,
                "lessons": [
                    {
                        "order": index + 1,
                        "title": title,
                        "detected_title": heading.detected_title,
                        "page_start": heading.page,
                        "page_end": end_page,
                    }
                ],
            }
        )
    result = {
        "format_version": FORMAT_VERSION,
        "source_file": source.name,
        "source_sha256": sha256_file(source),
        "page_count": document.page_count,
        "approved": False,
        "detection": {"confidence": confidence, "method": headings[0].method, "warnings": list(dict.fromkeys(warnings))},
        "chapters": chapters,
    }
    document.close()
    return result


def write_json(path: Path, value: dict[str, Any]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    temporary = path.with_name(path.name + ".tmp")
    with temporary.open("w", encoding="utf-8", newline="\n") as handle:
        json.dump(value, handle, ensure_ascii=False, indent=2)
        handle.write("\n")
    os.replace(temporary, path)


def read_manifest(path: Path, source: Path) -> dict[str, Any]:
    try:
        manifest = json.loads(path.read_text(encoding="utf-8-sig"))
    except (OSError, ValueError) as exc:
        raise PublisherError(f"Cannot read manifest {path}: {exc}") from exc
    if not isinstance(manifest, dict) or manifest.get("format_version") != FORMAT_VERSION:
        raise PublisherError("PDF lesson manifest format is invalid")
    if manifest.get("source_sha256") != sha256_file(source):
        raise PublisherError("Manifest does not match the source PDF SHA-256")
    document = fitz.open(source)
    actual_page_count = document.page_count
    if int(manifest.get("page_count") or 0) != actual_page_count:
        document.close()
        raise PublisherError("Manifest page count does not match the source PDF")
    document.close()
    flatten_manifest(manifest, actual_page_count)
    return manifest


def flatten_manifest(manifest: dict[str, Any], page_count: int) -> list[PlannedLesson]:
    result: list[PlannedLesson] = []
    previous_end = 0
    seen_orders: set[int] = set()
    for chapter_index, chapter in enumerate(manifest.get("chapters") or [], start=1):
        if not isinstance(chapter, dict):
            raise PublisherError("Every manifest chapter must be an object")
        chapter_number = int(chapter.get("number") or chapter_index)
        chapter_title = str(chapter.get("title") or f"Chapter {chapter_number}").strip()
        for lesson_index, lesson in enumerate(chapter.get("lessons") or [], start=1):
            if not isinstance(lesson, dict):
                raise PublisherError("Every manifest lesson must be an object")
            start = int(lesson.get("page_start") or 0)
            end = int(lesson.get("page_end") or 0)
            order = int(lesson.get("order") or len(result) + 1)
            title = str(lesson.get("title") or "").strip()
            if start < 1 or end < start or end > page_count:
                raise PublisherError(f"Invalid PDF page range {start}-{end} in manifest")
            if start <= previous_end:
                raise PublisherError("Manifest lesson page ranges must be ordered and non-overlapping")
            if order < 1 or order in seen_orders:
                raise PublisherError("Manifest lesson orders must be unique positive integers")
            if not title or len(title) > 255:
                raise PublisherError("Every manifest lesson needs a title of at most 255 characters")
            seen_orders.add(order)
            previous_end = end
            result.append(
                PlannedLesson(
                    key=f"chapter-{chapter_index}/lesson-{lesson_index}",
                    chapter_number=chapter_number,
                    chapter_title=chapter_title,
                    order=order,
                    title=title,
                    detected_title=str(lesson.get("detected_title") or ""),
                    page_start=start,
                    page_end=end,
                )
            )
    if not result:
        raise PublisherError("Manifest contains no lessons")
    return sorted(result, key=lambda lesson: lesson.order)


def split_pdf(document: fitz.Document, start: int, end: int) -> bytes:
    output = fitz.open()
    output.insert_pdf(document, from_page=start - 1, to_page=end - 1)
    value = output.tobytes(garbage=4, deflate=True)
    output.close()
    return value


def font_license(data: bytes) -> tuple[bool, str, str, int]:
    try:
        font = TTFont(io.BytesIO(data), lazy=True)
        family = ""
        if "name" in font:
            for record in font["name"].names:
                if record.nameID == 1:
                    try:
                        family = record.toUnicode().strip()
                    except Exception:
                        continue
                    if family:
                        break
        fs_type = int(getattr(font.get("OS/2"), "fsType", 0)) if "OS/2" in font else 0
        font.close()
    except Exception:
        return False, "", "unreadable-font-metadata", 400
    if fs_type & 0x0002:
        return False, family, "restricted-license", 400
    if fs_type & 0x0004 and not fs_type & 0x0008:
        return False, family, "preview-print-only", 400
    return True, family, "installable-or-editable", 400


def embedded_fonts(document: fitz.Document) -> tuple[list[dict[str, Any]], list[dict[str, str]]]:
    seen_xrefs: set[int] = set()
    seen_digests: set[str] = set()
    assets: list[dict[str, Any]] = []
    skipped: list[dict[str, str]] = []
    for page in document:
        for record in page.get_fonts(full=True):
            xref = int(record[0])
            if xref <= 0 or xref in seen_xrefs:
                continue
            seen_xrefs.add(xref)
            try:
                basename, extension, _font_type, data = document.extract_font(xref)
            except Exception as exc:
                skipped.append({"font": str(record[3]), "reason": f"extraction-failed: {exc}"})
                continue
            extension = str(extension).casefold()
            raw_font_name = str(basename or record[3])
            if re.match(r"^[A-Z]{6}\+", raw_font_name):
                skipped.append({"font": clean_subset_name(raw_font_name), "reason": "subset-font-not-reusable"})
                continue
            if not data or extension not in {"ttf", "otf", "woff", "woff2", "cff"}:
                skipped.append({"font": str(record[3]), "reason": "not-an-extractable-web-font"})
                continue
            digest = sha256_bytes(data)
            if digest in seen_digests:
                continue
            seen_digests.add(digest)
            reusable, family, reason, weight = font_license(data)
            font_name = clean_subset_name(raw_font_name)
            if not reusable:
                skipped.append({"font": font_name, "reason": reason})
                continue
            assets.append(
                {
                    "data": data,
                    "file_name": f"{safe_name(font_name)}-{digest[:10]}.{extension}",
                    "font_family": family or font_name,
                    "font_style": "normal",
                    "font_weight": weight,
                    "sha256": digest,
                }
            )
    unique_skipped = {
        (item["font"], item["reason"]): item
        for item in skipped
    }
    return assets, list(unique_skipped.values())


def reference_url(api: DavvagLessonAPI, reference: str) -> str:
    return f"{api.base_url}/{reference.lstrip('/')}"


def remote_matches(api: DavvagLessonAPI, reference: str, digest: str) -> bool:
    try:
        response = api.session.get(reference_url(api, reference), timeout=180)
        response.raise_for_status()
    except requests.RequestException:
        return False
    return sha256_bytes(response.content) == digest


def stage_asset(
    api: DavvagLessonAPI,
    data: bytes,
    file_name: str,
    asset_kind: str,
    source: Path,
    source_digest: str,
    state: PdfState,
    metadata: Optional[dict[str, Any]] = None,
) -> dict[str, Any]:
    digest = sha256_bytes(data)
    previous = state.data["assets"].get(digest)
    if isinstance(previous, dict) and previous.get("media_reference") and remote_matches(api, str(previous["media_reference"]), digest):
        return previous
    clean_name = safe_name(file_name)
    staged_name = f"lm-{digest[:24]}-{clean_name}"
    upload_url = f"{api.base_url}/components/dock/soss-uploader/service/upload_uncompressed/{ASSET_NAMESPACE}/{quote(staged_name, safe='')}"
    content_type = mimetypes.guess_type(clean_name)[0] or "application/octet-stream"
    last_error: Optional[Exception] = None
    for attempt in range(1, 4):
        try:
            response = api.session.post(upload_url, data=data, headers={"Content-Type": content_type}, timeout=300)
            response.raise_for_status()
            envelope = response.json()
            nested = envelope.get("result") if isinstance(envelope, dict) else None
            if not (isinstance(envelope, dict) and (envelope.get("sucess") is True or (envelope.get("success") is True and isinstance(nested, dict) and nested.get("sucess") is True))):
                raise ApiError(f"Uploader rejected {clean_name}: {envelope}")
            last_error = None
            break
        except (requests.RequestException, ValueError, ApiError) as exc:
            last_error = exc
            if attempt < 3:
                time.sleep(2 ** (attempt - 1))
    if last_error:
        raise ApiError(f"Upload failed for {clean_name}: {last_error}") from last_error
    reference = f"components/dock/soss-uploader/service/get/{ASSET_NAMESPACE}/{staged_name}"
    if not remote_matches(api, reference, digest):
        raise ApiError(f"Uploaded bytes failed SHA-256 verification: {clean_name}")
    payload = {
        "asset_kind": asset_kind,
        "file_name": Path(file_name).name,
        "media_reference": reference,
        "sha256": digest,
        "source_name": source.name,
        "source_sha256": source_digest,
        **(metadata or {}),
    }
    registered = api.call("RegisterReusableAsset", payload)
    if not isinstance(registered, dict) or int(registered.get("id") or 0) < 1:
        raise ApiError(f"RegisterReusableAsset returned no ID for {clean_name}")
    state.data["assets"][digest] = registered
    state.save()
    return registered


def find_by_id(rows: Iterable[dict[str, Any]], value: Any) -> Optional[dict[str, Any]]:
    return next((row for row in rows if str(row.get("id")) == str(value)), None)


def has_changes(existing: dict[str, Any], desired: dict[str, Any]) -> bool:
    return any(existing.get(key) != value for key, value in desired.items() if key != "id")


def apply_import(
    args: argparse.Namespace,
    api: DavvagLessonAPI,
    subject: dict[str, Any],
    manifest: dict[str, Any],
    lessons: list[PlannedLesson],
    state: PdfState,
) -> dict[str, Any]:
    source = args.source.resolve()
    source_digest = str(manifest["source_sha256"])
    document = fitz.open(source)
    existing = api.list_lessons(int(subject["id"]))
    order_start = int(state.data.get("order_start") or 0)
    if order_start < 1:
        order_start = max((int(row.get("lesson_order") or 0) for row in existing), default=0) + 1
        state.data["order_start"] = order_start
        state.save()
    summary = {"lessons_created": 0, "lessons_updated": 0, "contents_created": 0, "contents_updated": 0, "assets_registered": 0, "fonts_registered": 0, "fonts_skipped": []}

    before = len(state.data["assets"])
    original = stage_asset(api, source.read_bytes(), source.name, "pdf", source, source_digest, state)
    summary["assets_registered"] += int(len(state.data["assets"]) > before)
    summary["source_asset_id"] = int(original["id"])
    if not args.no_fonts:
        fonts, skipped = embedded_fonts(document)
        summary["fonts_skipped"] = skipped
        for font in fonts:
            before = len(state.data["assets"])
            stage_asset(
                api, font["data"], font["file_name"], "font", source, source_digest, state,
                {"font_family": font["font_family"], "font_style": font["font_style"], "font_weight": font["font_weight"]},
            )
            summary["fonts_registered"] += 1
            summary["assets_registered"] += int(len(state.data["assets"]) > before)

    for lesson in lessons:
        desired_order = order_start + lesson.order - 1
        lesson_state = state.data["lessons"].get(lesson.key, {})
        current = find_by_id(existing, lesson_state.get("lesson_id"))
        if current is None:
            current = next((row for row in existing if str(row.get("title")) == lesson.title and int(row.get("lesson_order") or 0) == desired_order), None)
        description = f"Imported from {source.name}, PDF pages {lesson.page_start}-{lesson.page_end}. Chapter {lesson.chapter_number}: {lesson.chapter_title}"
        desired = {
            "subject_id": int(subject["id"]),
            "course_id": int(subject["course_id"]),
            "title": lesson.title,
            "description": description,
            "lesson_order": desired_order,
            "passing_mark": 70,
            "status": "draft",
            "progression_enabled": True,
            "is_free": True,
            "required_credit_points": 0,
            "require_reading": True,
            "require_video": False,
            "require_quiz": False,
            "require_assignment": False,
            "require_teacher_approval": False,
        }
        payload = {**current, **desired, "id": current["id"]} if current else desired
        if current and not has_changes(current, payload):
            saved = current
        else:
            saved = api.call("SaveLesson", payload)
            if not isinstance(saved, dict) or int(saved.get("id") or 0) < 1:
                raise ApiError(f"SaveLesson returned no ID for {lesson.title}")
            summary["lessons_updated" if current else "lessons_created"] += 1
            existing = [row for row in existing if str(row.get("id")) != str(saved["id"])] + [saved]
        state.data["lessons"][lesson.key] = {"lesson_id": int(saved["id"])}
        state.save()

        pdf_data = split_pdf(document, lesson.page_start, lesson.page_end)
        split_name = f"{source.stem}-lesson-{lesson.order:03d}-pages-{lesson.page_start}-{lesson.page_end}.pdf"
        before = len(state.data["assets"])
        asset = stage_asset(api, pdf_data, split_name, "pdf", source, source_digest, state)
        summary["assets_registered"] += int(len(state.data["assets"]) > before)
        contents = api.list_content(int(saved["id"]))
        content_state = state.data["contents"].get(lesson.key, {})
        current_content = find_by_id(contents, content_state.get("content_id"))
        if current_content is None:
            current_content = next((row for row in contents if row.get("content_type") == "pdf_embed"), None)
        content_desired = {
            "lesson_id": int(saved["id"]),
            "content_type": "pdf_embed",
            "title": lesson.title,
            "body": f"<p>Textbook pages {lesson.page_start}-{lesson.page_end}. The original page design, diagrams, and embedded fonts are preserved in this PDF.</p>",
            "url": asset["media_reference"],
            "embed_url": asset["media_reference"],
            "file_name": split_name,
            "mime_type": "application/pdf",
            "sort_order": 1,
            "is_required": True,
            "status": "published",
        }
        content_payload = {**current_content, **content_desired, "id": current_content["id"]} if current_content else content_desired
        if current_content and not has_changes(current_content, content_payload):
            saved_content = current_content
        else:
            saved_content = api.call("SaveContent", content_payload)
            if not isinstance(saved_content, dict) or int(saved_content.get("id") or 0) < 1:
                raise ApiError(f"SaveContent returned no ID for {lesson.title}")
            summary["contents_updated" if current_content else "contents_created"] += 1
        state.data["contents"][lesson.key] = {"content_id": int(saved_content["id"]), "asset_id": int(asset["id"]), "sha256": sha256_bytes(pdf_data)}
        state.save()

    if args.publish:
        rows = api.list_lessons(int(subject["id"]))
        for lesson in lessons:
            row = find_by_id(rows, state.data["lessons"][lesson.key]["lesson_id"])
            if row is None:
                raise PublisherError(f"Imported lesson disappeared before publish: {lesson.title}")
            if str(row.get("status", "")).casefold() != "published":
                api.call("SaveLesson", {**row, "status": "published"})
    document.close()
    return summary


def default_manifest_path(source: Path) -> Path:
    key = sha256_bytes(str(source.absolute()).encode("utf-8"))[:12]
    return Path.cwd() / ".lesson-manager-pdf" / f"{safe_name(source.stem)}-{key}.lesson-plan.json"


def default_state_path(source: Path, subject_code: str) -> Path:
    code = safe_name(subject_code)
    key = sha256_bytes(str(source.absolute()).encode("utf-8"))[:12]
    return Path.cwd() / ".lesson-manager-pdf" / f"{safe_name(source.stem)}-{key}-state-{code}.json"


def parse_args(argv: Optional[list[str]] = None) -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Detect and publish lesson ranges from a PDF textbook")
    parser.add_argument("subject_code", help="Course Manager subject code")
    parser.add_argument("--source", type=Path, required=True, help="Source PDF textbook")
    parser.add_argument("--manifest", type=Path, help="Lesson plan JSON (default: beside the PDF)")
    parser.add_argument("--state", type=Path, help="Resume state JSON")
    parser.add_argument("--base-url", default=os.environ.get("DAVVAG_BASE_URL", DEFAULT_BASE_URL))
    parser.add_argument("--env-file", type=Path, default=DEFAULT_ENV_FILE)
    parser.add_argument("--refresh-manifest", action="store_true", help="Re-run detection and replace an unapproved manifest")
    parser.add_argument("--accept-detected-plan", action="store_true", help="Apply a plan without setting approved=true in the manifest")
    parser.add_argument("--no-fonts", action="store_true", help="Do not extract/register reusable embedded fonts")
    parser.add_argument("--apply", action="store_true")
    parser.add_argument("--publish", action="store_true")
    parser.add_argument("--allow-insecure-http", action="store_true")
    parser.add_argument("--insecure-tls", action="store_true")
    parser.add_argument("--verbose", action="store_true")
    args = parser.parse_args(argv)
    if args.publish and not args.apply:
        parser.error("--publish requires --apply")
    return args


def run(args: argparse.Namespace) -> dict[str, Any]:
    source = args.source.absolute()
    manifest_path = (args.manifest or default_manifest_path(source)).resolve()
    if args.refresh_manifest and manifest_path.exists():
        existing = read_manifest(manifest_path, source)
        if existing.get("approved") is True:
            raise PublisherError("Refusing to replace an approved manifest; set approved=false first")
    if args.refresh_manifest or not manifest_path.exists():
        write_json(manifest_path, detect_manifest(source))
        LOGGER.info("Wrote detected lesson plan: %s", manifest_path)
    manifest = read_manifest(manifest_path, source)
    lessons = flatten_manifest(manifest, int(manifest["page_count"]))
    result: dict[str, Any] = {
        "success": True,
        "mode": "publish" if args.publish else ("apply" if args.apply else "dry-run"),
        "source": str(source),
        "manifest": str(manifest_path),
        "manifest_approved": manifest.get("approved") is True,
        "detection": manifest.get("detection"),
        "lessons_planned": len(lessons),
        "lessons": [{"order": row.order, "title": row.title, "pages": [row.page_start, row.page_end], "chapter": row.chapter_number} for row in lessons],
    }
    if not args.apply:
        return result
    if manifest.get("approved") is not True and not args.accept_detected_plan:
        raise PublisherError(f"Review {manifest_path}, correct titles/page ranges, and set approved to true (or pass --accept-detected-plan)")
    session = build_session(args.base_url, allow_insecure_http=args.allow_insecure_http, insecure_tls=args.insecure_tls)
    api = DavvagLessonAPI(args.base_url, session)
    subject = resolve_subject(api.bootstrap(), args.subject_code)
    state = PdfState((args.state or default_state_path(source, args.subject_code)).resolve())
    state.initialize(source_sha256=str(manifest["source_sha256"]), base_url=api.base_url, subject_id=int(subject["id"]))
    state.save()
    result["subject_id"] = int(subject["id"])
    result["course_id"] = int(subject["course_id"])
    result["state"] = str(state.path)
    result.update(apply_import(args, api, subject, manifest, lessons, state))
    return result


def main(argv: Optional[list[str]] = None) -> int:
    effective = list(sys.argv[1:] if argv is None else argv)
    env_parser = argparse.ArgumentParser(add_help=False)
    env_parser.add_argument("--env-file", type=Path, default=DEFAULT_ENV_FILE)
    env_args, _ = env_parser.parse_known_args(effective)
    try:
        load_login_config(env_args.env_file)
        args = parse_args(effective)
        logging.basicConfig(level=logging.DEBUG if args.verbose else logging.INFO, format="%(levelname)s: %(message)s")
        result = run(args)
    except (PublisherError, ApiError, OSError, ValueError, fitz.FileDataError) as exc:
        LOGGER.error("%s", exc)
        print(console_json({"success": False, "error": str(exc)}))
        return 1
    print(console_json(result))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

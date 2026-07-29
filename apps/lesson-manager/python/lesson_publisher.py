#!/usr/bin/env python3
"""Publish a converted Markdown textbook to DAVVAG Lesson Manager.

The command is intentionally dry-run by default. Pass --apply to upload media
and create/update draft lessons. Pass --apply --publish to publish only after
the imported records pass verification.
"""

from __future__ import annotations

import argparse
import hashlib
import json
import logging
import mimetypes
import os
import re
import sys
import time
from dataclasses import dataclass, field
from pathlib import Path
from typing import Any, Iterable, Optional
from urllib.parse import quote, unquote, urlparse

try:
    import markdown as markdown_lib
    import requests
    from bs4 import BeautifulSoup
except ImportError as exc:  # pragma: no cover - exercised by CLI installations
    raise SystemExit(
        "Missing dependency. Run: python -m pip install -r requirements.txt"
    ) from exc


LOGGER = logging.getLogger("lesson_publisher")

DEFAULT_BASE_URL = "https://www.ephraimgen.com"
DEFAULT_SOURCE = Path(
    r"C:\projects\education_scraper\output\regression\katholika-g-11-S"
)
DEFAULT_ENV_FILE = Path(
    r"C:\xampp\htdocs\davvag-core\davvag-core\localhost\global\config\.env"
)
LOGIN_ENV_KEYS = {
    "DAVVAG_BASE_URL",
    "DAVVAG_EMAIL",
    "DAVVAG_PASSWORD",
    "DAVVAG_SECURITY_TOKEN",
}
FORMAT_VERSION = 1
MEDIA_SUFFIXES = {".png", ".jpg", ".jpeg", ".gif", ".webp"}
SAFE_NAME_RE = re.compile(r"[^A-Za-z0-9._-]+")
NUMBERED_NAME_RE = re.compile(r"^(\d+)-.+")
TRUE_VALUES = {True, 1, "1", "true", "yes", "on"}


def console_json(value: Any) -> str:
    """Serialize CLI output so it is safe on legacy Windows code pages."""
    return json.dumps(value, ensure_ascii=True)


class PublisherError(RuntimeError):
    """A user-actionable publisher error."""


class ApiError(PublisherError):
    """A DAVVAG API response or transport error."""


@dataclass
class ContentPlan:
    source_path: Path
    kind: str
    title: str
    required: bool
    sort_order: int

    def relative_key(self, source_root: Path) -> str:
        return self.source_path.resolve().relative_to(source_root).as_posix()


@dataclass
class LessonPlan:
    source_path: Path
    title: str
    description: str
    lesson_order: int
    contents: list[ContentPlan] = field(default_factory=list)

    def relative_key(self, source_root: Path) -> str:
        return self.source_path.resolve().relative_to(source_root).as_posix()


@dataclass
class ImportPlan:
    source_root: Path
    lessons: list[LessonPlan]
    media_files: list[Path]
    image_references: dict[Path, int]
    include_front_matter: bool

    @property
    def content_count(self) -> int:
        return sum(len(lesson.contents) for lesson in self.lessons)


@dataclass
class Summary:
    mode: str
    subject_code: str
    subject_id: Optional[int] = None
    course_id: Optional[int] = None
    lessons_planned: int = 0
    contents_planned: int = 0
    media_planned: int = 0
    lessons_created: int = 0
    lessons_updated: int = 0
    lessons_adopted: int = 0
    lessons_skipped: int = 0
    contents_created: int = 0
    contents_updated: int = 0
    contents_adopted: int = 0
    contents_skipped: int = 0
    uploads_created: int = 0
    uploads_verified: int = 0
    uploads_skipped: int = 0
    lessons_published: int = 0
    details: dict[str, Any] = field(default_factory=dict)

    def as_dict(self, success: bool, error: Optional[str] = None) -> dict[str, Any]:
        result = {"success": success, **self.__dict__}
        if error:
            result["error"] = error
        return result


class StateStore:
    """Atomic, secret-free resume state."""

    def __init__(self, path: Path):
        self.path = path.resolve()
        self.data: dict[str, Any] = {}
        if self.path.exists():
            try:
                self.data = json.loads(self.path.read_text(encoding="utf-8"))
            except (OSError, ValueError) as exc:
                raise PublisherError(f"Cannot read state file {self.path}: {exc}") from exc
            if not isinstance(self.data, dict):
                raise PublisherError(f"State file must contain a JSON object: {self.path}")

    @property
    def exists(self) -> bool:
        return self.path.exists()

    def validate_identity(
        self,
        base_url: str,
        source_root: Path,
        subject_code: str,
        subject_id: int,
        include_front_matter: bool,
    ) -> None:
        if not self.data:
            return
        expected = {
            "format_version": FORMAT_VERSION,
            "base_url": base_url.rstrip("/"),
            "source_root": source_root.resolve().as_posix(),
            "subject_code": subject_code,
            "subject_id": subject_id,
            "include_front_matter": include_front_matter,
        }
        for key, value in expected.items():
            if key in self.data and self.data[key] != value:
                raise PublisherError(
                    f"State file {key} mismatch: expected {value!r}, "
                    f"found {self.data[key]!r}"
                )

    def initialize(
        self,
        base_url: str,
        source_root: Path,
        subject_code: str,
        subject_id: int,
        order_start: int,
        include_front_matter: bool,
    ) -> None:
        self.data.setdefault("format_version", FORMAT_VERSION)
        self.data.setdefault("base_url", base_url.rstrip("/"))
        self.data.setdefault("source_root", source_root.resolve().as_posix())
        self.data.setdefault("subject_code", subject_code)
        self.data.setdefault("subject_id", subject_id)
        self.data.setdefault("order_start", order_start)
        self.data.setdefault("include_front_matter", include_front_matter)
        self.data.setdefault("lessons", {})
        self.data.setdefault("content", {})
        self.data.setdefault("uploads", {})

    def save(self) -> None:
        self.path.parent.mkdir(parents=True, exist_ok=True)
        temporary = self.path.with_name(self.path.name + ".tmp")
        try:
            with temporary.open("w", encoding="utf-8", newline="\n") as handle:
                json.dump(self.data, handle, ensure_ascii=False, indent=2, sort_keys=True)
                handle.write("\n")
                handle.flush()
                os.fsync(handle.fileno())
            os.replace(temporary, self.path)
        except OSError as exc:
            raise PublisherError(f"Cannot save state file {self.path}: {exc}") from exc


class DavvagLessonAPI:
    def __init__(self, base_url: str, session: requests.Session):
        self.base_url = base_url.rstrip("/")
        self.session = session

    def call(
        self,
        method: str,
        payload: Optional[dict[str, Any]] = None,
        *,
        retry_safe: bool = False,
    ) -> Any:
        url = f"{self.base_url}/components/lesson-manager/api/service/{method}"
        attempts = 3 if retry_safe else 1
        for attempt in range(1, attempts + 1):
            try:
                response = self.session.post(url, json=payload or {}, timeout=90)
            except requests.RequestException as exc:
                if attempt < attempts:
                    time.sleep(2 ** (attempt - 1))
                    continue
                raise ApiError(f"{method} request failed: {exc}") from exc

            if response.status_code in {429, 502, 503, 504} and attempt < attempts:
                time.sleep(2 ** (attempt - 1))
                continue

            try:
                envelope = response.json()
            except ValueError as exc:
                raise ApiError(
                    f"{method} returned non-JSON HTTP {response.status_code}: "
                    f"{response.text[:500]}"
                ) from exc

            if not isinstance(envelope, dict) or not envelope.get("success"):
                detail: Any = (
                    envelope.get("result", envelope)
                    if isinstance(envelope, dict)
                    else envelope
                )
                if isinstance(detail, dict):
                    detail = detail.get("message", detail)
                raise ApiError(
                    f"{method} failed with HTTP {response.status_code}: {detail}"
                )
            if not response.ok:
                raise ApiError(f"{method} failed with HTTP {response.status_code}")
            return envelope.get("result")
        raise ApiError(f"{method} failed")

    def bootstrap(self) -> dict[str, Any]:
        result = self.call("Bootstrap", {}, retry_safe=True)
        if not isinstance(result, dict):
            raise ApiError("Bootstrap returned an invalid result")
        return result

    def list_lessons(self, subject_id: int) -> list[dict[str, Any]]:
        result = self.call(
            "ListLessons", {"subject_id": subject_id}, retry_safe=True
        )
        return list(result or [])

    def list_content(self, lesson_id: int) -> list[dict[str, Any]]:
        result = self.call(
            "ListContent", {"lesson_id": lesson_id}, retry_safe=True
        )
        return list(result or [])


def parse_dotenv_value(raw: str) -> str:
    value = raw.strip()
    if not value:
        return ""
    if value.startswith("'"):
        if len(value) < 2 or not value.endswith("'"):
            raise PublisherError("Unterminated single-quoted .env value")
        return value[1:-1]
    if value.startswith('"'):
        if len(value) < 2 or not value.endswith('"'):
            raise PublisherError("Unterminated double-quoted .env value")
        try:
            decoded = json.loads(value)
        except json.JSONDecodeError:
            decoded = value[1:-1]
        return str(decoded)
    return re.split(r"\s+#", value, maxsplit=1)[0].strip()


def load_login_config(
    path: Path,
    environment: Optional[dict[str, str]] = None,
) -> set[str]:
    target = path.expanduser().resolve()
    if not target.is_file():
        raise PublisherError(f"Login configuration file does not exist: {target}")
    destination = os.environ if environment is None else environment
    loaded: set[str] = set()
    try:
        lines = target.read_text(encoding="utf-8-sig").splitlines()
    except (OSError, UnicodeError) as exc:
        raise PublisherError(f"Cannot read login configuration {target}: {exc}") from exc
    for line_number, raw_line in enumerate(lines, start=1):
        line = raw_line.strip()
        if not line or line.startswith("#"):
            continue
        if line.startswith("export "):
            line = line[7:].lstrip()
        if "=" not in line:
            continue
        key, raw_value = line.split("=", 1)
        key = key.strip()
        if key not in LOGIN_ENV_KEYS:
            continue
        if not re.fullmatch(r"[A-Za-z_][A-Za-z0-9_]*", key):
            raise PublisherError(
                f"Invalid configuration key on line {line_number} of {target}"
            )
        if key not in destination:
            destination[key] = parse_dotenv_value(raw_value)
            loaded.add(key)
    return loaded


def env_file_from_args(argv: Optional[list[str]] = None) -> Path:
    pre_parser = argparse.ArgumentParser(add_help=False)
    pre_parser.add_argument("--env-file", type=Path, default=DEFAULT_ENV_FILE)
    known, _ = pre_parser.parse_known_args(argv)
    return known.env_file


def normalize_subject_code(value: str) -> str:
    return value.strip().casefold()


def resolve_subject(bootstrap: dict[str, Any], subject_code: str) -> dict[str, Any]:
    subjects = bootstrap.get("subjects") or []
    wanted = normalize_subject_code(subject_code)
    matches = [
        subject
        for subject in subjects
        if normalize_subject_code(str(subject.get("code", ""))) == wanted
    ]
    if not matches:
        available = sorted(
            str(subject.get("code"))
            for subject in subjects
            if str(subject.get("code", "")).strip()
        )
        suffix = f" Manageable codes: {', '.join(available)}" if available else ""
        raise PublisherError(
            f"Subject code {subject_code!r} is not available to this user.{suffix}"
        )
    if len(matches) > 1:
        ids = ", ".join(str(subject.get("id")) for subject in matches)
        raise PublisherError(
            f"Subject code {subject_code!r} is ambiguous; matching IDs: {ids}"
        )
    subject = matches[0]
    if int(subject.get("id") or 0) < 1 or int(subject.get("course_id") or 0) < 1:
        raise PublisherError(
            f"Subject code {subject_code!r} is not assigned to a valid course"
        )
    return subject


def numbered_sort_key(path: Path) -> tuple[int, str]:
    match = NUMBERED_NAME_RE.match(path.name)
    if not match:
        return (sys.maxsize, path.name.casefold())
    return (int(match.group(1)), path.name.casefold())


def source_relative(path: Path, source_root: Path) -> str:
    try:
        return path.resolve().relative_to(source_root.resolve()).as_posix()
    except ValueError as exc:
        raise PublisherError(f"Source path escapes source root: {path}") from exc


def read_json_object(path: Path) -> dict[str, Any]:
    try:
        result = json.loads(path.read_text(encoding="utf-8-sig"))
    except (OSError, ValueError) as exc:
        raise PublisherError(f"Cannot read JSON file {path}: {exc}") from exc
    if not isinstance(result, dict):
        raise PublisherError(f"Expected a JSON object in {path}")
    return result


def validate_conversion_reports(source_root: Path) -> None:
    report_path = source_root / "conversion-report.json"
    manifest_path = source_root / "image-manifest.json"
    if not report_path.is_file() or not manifest_path.is_file():
        raise PublisherError(
            "conversion-report.json and image-manifest.json are required for preflight"
        )

    report = read_json_object(report_path)
    if report.get("complete") is not True:
        raise PublisherError("The conversion report is not marked complete")
    for key in ("errors", "warnings", "unresolved"):
        value = report.get(key) or []
        if value:
            raise PublisherError(f"Conversion report contains {len(value)} {key}")

    manifest = read_json_object(manifest_path)
    failures = manifest.get("failures") or []
    if failures:
        raise PublisherError(f"Image manifest contains {len(failures)} failures")


def markdown_soup(md_path: Path) -> BeautifulSoup:
    try:
        source = md_path.read_text(encoding="utf-8-sig")
    except (OSError, UnicodeError) as exc:
        raise PublisherError(f"Cannot read Markdown as UTF-8: {md_path}: {exc}") from exc
    html = markdown_lib.markdown(
        source,
        extensions=["extra", "sane_lists"],
        output_format="html5",
    )
    return BeautifulSoup(html, "html.parser")


def title_and_description(md_path: Path) -> tuple[str, str]:
    soup = markdown_soup(md_path)
    heading = soup.find("h1")
    title = (
        heading.get_text(" ", strip=True)
        if heading
        else md_path.stem.split("-", 1)[-1].replace("-", " ").strip()
    )
    paragraph = next(
        (
            node.get_text(" ", strip=True)
            for node in soup.find_all("p")
            if node.get_text(" ", strip=True)
        ),
        "",
    )
    description = paragraph[:1000]
    if not title:
        raise PublisherError(f"Markdown has no usable title: {md_path}")
    if len(title) > 255:
        raise PublisherError(f"Lesson title exceeds 255 characters: {md_path}")
    return title, description


def resolve_local_media(md_path: Path, raw_reference: str, source_root: Path) -> Path:
    parsed = urlparse(raw_reference)
    if parsed.scheme or parsed.netloc:
        raise PublisherError(f"Expected local media in {md_path}: {raw_reference}")
    target = (md_path.parent / unquote(parsed.path)).resolve()
    source_relative(target, source_root)
    if not target.is_file():
        raise PublisherError(f"Missing media referenced by {md_path}: {target}")
    return target


def collect_image_references(
    markdown_files: Iterable[Path], source_root: Path
) -> dict[Path, int]:
    references: dict[Path, int] = {}
    for md_path in markdown_files:
        soup = markdown_soup(md_path)
        for image in soup.find_all("img"):
            raw = str(image.get("src", "")).strip()
            parsed = urlparse(raw)
            if parsed.scheme:
                if parsed.scheme.casefold() != "https":
                    raise PublisherError(
                        f"External image must use HTTPS in {md_path}: {raw}"
                    )
                continue
            target = resolve_local_media(md_path, raw, source_root)
            references[target] = references.get(target, 0) + 1
    return references


def build_import_plan(source: Path, include_front_matter: bool) -> ImportPlan:
    source_root = source.resolve()
    if not source_root.is_dir():
        raise PublisherError(f"Source directory does not exist: {source_root}")
    validate_conversion_reports(source_root)

    chapter_dirs = sorted(
        (
            path
            for path in source_root.iterdir()
            if path.is_dir() and NUMBERED_NAME_RE.match(path.name)
        ),
        key=numbered_sort_key,
    )
    if not chapter_dirs:
        raise PublisherError("No numbered chapter directories were found")

    front_matter = source_root / "000-front-matter.md"
    if include_front_matter and not front_matter.is_file():
        raise PublisherError(f"Front matter is missing: {front_matter}")

    lessons: list[LessonPlan] = []
    lesson_order = 1
    for chapter in chapter_dirs:
        introduction = chapter / "000-chapter-introduction.md"
        if not introduction.is_file():
            raise PublisherError(f"Chapter introduction is missing: {introduction}")
        intro_title, _ = title_and_description(introduction)
        lesson_files = sorted(
            (
                path
                for path in chapter.glob("*.md")
                if path.name != introduction.name and NUMBERED_NAME_RE.match(path.name)
            ),
            key=numbered_sort_key,
        )
        if not lesson_files:
            raise PublisherError(f"Chapter has no lesson Markdown files: {chapter}")

        for chapter_index, lesson_file in enumerate(lesson_files):
            title, description = title_and_description(lesson_file)
            content_sources: list[tuple[Path, str, str, bool]] = []
            if not lessons and include_front_matter:
                front_title, _ = title_and_description(front_matter)
                content_sources.append(
                    (front_matter, "front_matter", front_title, False)
                )
            if chapter_index == 0:
                content_sources.append(
                    (introduction, "chapter_introduction", intro_title, True)
                )
            content_sources.append((lesson_file, "lesson", title, True))
            contents = [
                ContentPlan(path, kind, content_title, required, index)
                for index, (path, kind, content_title, required) in enumerate(
                    content_sources, start=1
                )
            ]
            lessons.append(
                LessonPlan(
                    source_path=lesson_file,
                    title=title,
                    description=description,
                    lesson_order=lesson_order,
                    contents=contents,
                )
            )
            lesson_order += 1

    titles = [lesson.title.casefold() for lesson in lessons]
    if len(titles) != len(set(titles)):
        raise PublisherError("Lesson titles are not unique within the import plan")

    media_dir = source_root / "media"
    if not media_dir.is_dir():
        raise PublisherError(f"Media directory is missing: {media_dir}")
    media_files = sorted(
        (
            path.resolve()
            for path in media_dir.iterdir()
            if path.is_file() and path.suffix.casefold() in MEDIA_SUFFIXES
        ),
        key=lambda path: path.name.casefold(),
    )
    if not media_files:
        raise PublisherError("No supported media files were found")

    content_markdown = [
        content.source_path for lesson in lessons for content in lesson.contents
    ]
    references = collect_image_references(content_markdown, source_root)
    media_set = set(media_files)
    missing_from_media = sorted(set(references) - media_set)
    if missing_from_media:
        raise PublisherError(
            f"{len(missing_from_media)} referenced images are outside the media inventory"
        )

    return ImportPlan(
        source_root=source_root,
        lessons=lessons,
        media_files=media_files,
        image_references=references,
        include_front_matter=include_front_matter,
    )


def make_tables_mobile(soup: BeautifulSoup) -> None:
    for table in list(soup.find_all("table")):
        rows = table.find_all("tr")
        if not rows:
            table.decompose()
            continue
        header_cells = rows[0].find_all(["th", "td"])
        headers = [cell.get_text(" ", strip=True) for cell in header_cells]
        body_rows = rows[1:] if table.find("th") else rows
        container = soup.new_tag("div")
        for row in body_rows:
            cells = row.find_all(["th", "td"])
            card = soup.new_tag("div")
            for index, cell in enumerate(cells):
                paragraph = soup.new_tag("p")
                if index < len(headers) and headers[index]:
                    label = soup.new_tag("strong")
                    label.string = headers[index] + ": "
                    paragraph.append(label)
                for child in list(cell.contents):
                    paragraph.append(child.extract())
                card.append(paragraph)
            card.append(soup.new_tag("hr"))
            container.append(card)
        table.replace_with(container)


def render_content_html(
    md_path: Path,
    source_root: Path,
    uploaded_images: dict[Path, str],
) -> tuple[str, str]:
    soup = markdown_soup(md_path)
    title_node = soup.find("h1")
    title = (
        title_node.get_text(" ", strip=True)
        if title_node
        else md_path.stem.split("-", 1)[-1].replace("-", " ")
    )
    if title_node:
        title_node.decompose()

    for heading in soup.find_all(["h5", "h6"]):
        heading.name = "h4"

    for image in soup.find_all("img"):
        raw = str(image.get("src", "")).strip()
        parsed = urlparse(raw)
        if parsed.scheme:
            if parsed.scheme.casefold() != "https":
                raise PublisherError(f"Image URL must use HTTPS in {md_path}: {raw}")
            continue
        source_image = resolve_local_media(md_path, raw, source_root)
        if source_image not in uploaded_images:
            raise PublisherError(f"Image was not uploaded: {source_image}")
        image["src"] = uploaded_images[source_image]

    for link in soup.find_all("a"):
        raw = str(link.get("href", "")).strip()
        if not raw or raw.startswith("#"):
            continue
        scheme = urlparse(raw).scheme.casefold()
        if scheme not in {"https", "mailto", "tel"}:
            raise PublisherError(f"Unsupported link in {md_path}: {raw}")

    make_tables_mobile(soup)
    body = str(soup).strip()
    if "../media/" in body or "..\\media\\" in body:
        raise PublisherError(f"Local media reference remained in {md_path}")
    return title.strip(), body


def stable_upload_name(path: Path, source_root: Path) -> str:
    relative = source_relative(path, source_root)
    digest = hashlib.sha256(relative.encode("utf-8")).hexdigest()[:16]
    basename = SAFE_NAME_RE.sub("_", path.name).strip("._") or "file"
    return f"lm-{digest}-{basename}"


def sha256_bytes(value: bytes) -> str:
    return hashlib.sha256(value).hexdigest()


def sha256_file(path: Path) -> str:
    return sha256_bytes(path.read_bytes())


def reference_url(api: DavvagLessonAPI, reference: str) -> str:
    return f"{api.base_url}/{reference.lstrip('/')}"


def verify_uploaded_bytes(
    api: DavvagLessonAPI, path: Path, reference: str
) -> bool:
    try:
        response = api.session.get(reference_url(api, reference), timeout=180)
        response.raise_for_status()
    except requests.RequestException:
        return False
    return sha256_bytes(response.content) == sha256_file(path)


def upload_file(
    api: DavvagLessonAPI,
    path: Path,
    source_root: Path,
    namespace: str,
    state: StateStore,
    summary: Summary,
) -> str:
    relative = source_relative(path, source_root)
    digest = sha256_file(path)
    previous = state.data.get("uploads", {}).get(relative)
    if (
        isinstance(previous, dict)
        and previous.get("sha256") == digest
        and previous.get("namespace") == namespace
        and previous.get("reference")
        and verify_uploaded_bytes(api, path, str(previous["reference"]))
    ):
        summary.uploads_skipped += 1
        summary.uploads_verified += 1
        return str(previous["reference"])

    name = stable_upload_name(path, source_root)
    upload_url = (
        f"{api.base_url}/components/dock/soss-uploader/service/"
        f"upload_uncompressed/{quote(namespace, safe='')}/{quote(name, safe='')}"
    )
    raw = path.read_bytes()
    content_type = mimetypes.guess_type(path.name)[0] or "application/octet-stream"
    last_error: Optional[Exception] = None
    for attempt in range(1, 4):
        try:
            response = api.session.post(
                upload_url,
                data=raw,
                headers={"Content-Type": content_type},
                timeout=180,
            )
            response.raise_for_status()
            result = response.json()
            upload_result = result.get("result") if isinstance(result, dict) else None
            success = bool(
                isinstance(result, dict)
                and (
                    result.get("sucess") is True
                    or (
                        result.get("success") is True
                        and isinstance(upload_result, dict)
                        and upload_result.get("sucess") is True
                    )
                )
            )
            if not success:
                raise ApiError(f"Upload returned failure for {relative}: {result}")
            last_error = None
            break
        except (requests.RequestException, ValueError, ApiError) as exc:
            last_error = exc
            if attempt < 3:
                time.sleep(2 ** (attempt - 1))
    if last_error:
        raise ApiError(f"Upload failed for {relative}: {last_error}") from last_error

    reference = (
        "components/dock/soss-uploader/service/get/"
        f"{namespace}/{name}"
    )
    if not verify_uploaded_bytes(api, path, reference):
        raise ApiError(f"Uploaded bytes failed SHA-256 verification: {relative}")

    state.data["uploads"][relative] = {
        "sha256": digest,
        "namespace": namespace,
        "reference": reference,
    }
    state.save()
    summary.uploads_created += 1
    summary.uploads_verified += 1
    return reference


def truthy(value: Any) -> bool:
    if isinstance(value, str):
        value = value.casefold()
    return value in TRUE_VALUES


def comparable(value: Any) -> Any:
    if isinstance(value, bool):
        return value
    if isinstance(value, str) and value.casefold() in {
        "true",
        "false",
        "1",
        "0",
        "yes",
        "no",
    }:
        return truthy(value)
    return value


def has_changes(existing: dict[str, Any], desired: dict[str, Any]) -> bool:
    return any(
        comparable(existing.get(key)) != comparable(value)
        for key, value in desired.items()
        if key != "id"
    )


def find_by_id(rows: Iterable[dict[str, Any]], row_id: Any) -> Optional[dict[str, Any]]:
    wanted = str(row_id)
    return next((row for row in rows if str(row.get("id")) == wanted), None)


def unique_title_match(
    rows: Iterable[dict[str, Any]], title: str
) -> Optional[dict[str, Any]]:
    matches = [
        row
        for row in rows
        if str(row.get("title", "")).strip().casefold() == title.strip().casefold()
    ]
    if len(matches) > 1:
        raise PublisherError(f"Multiple existing lessons have title {title!r}")
    return matches[0] if matches else None


def resolve_existing_lesson(
    lesson: LessonPlan,
    rows: list[dict[str, Any]],
    state: StateStore,
    source_root: Path,
) -> tuple[Optional[dict[str, Any]], bool]:
    key = lesson.relative_key(source_root)
    state_entry = state.data.get("lessons", {}).get(key, {})
    state_id = state_entry.get("lesson_id") if isinstance(state_entry, dict) else None
    if state_id:
        row = find_by_id(rows, state_id)
        if row is None:
            raise PublisherError(
                f"State lesson ID {state_id} no longer exists for {key}"
            )
        return row, False
    match = unique_title_match(rows, lesson.title)
    return match, match is not None


def choose_order_start(
    plan: ImportPlan,
    existing: list[dict[str, Any]],
    state: StateStore,
) -> int:
    if state.data.get("order_start"):
        return int(state.data["order_start"])
    adopted_ids: set[str] = set()
    for lesson in plan.lessons:
        match, _ = resolve_existing_lesson(lesson, existing, state, plan.source_root)
        if match:
            adopted_ids.add(str(match.get("id")))
    unrelated = [row for row in existing if str(row.get("id")) not in adopted_ids]
    if not unrelated:
        return 1
    return max(int(row.get("lesson_order") or 0) for row in unrelated) + 1


def lesson_payload(
    lesson: LessonPlan,
    subject_id: int,
    order_start: int,
    existing: Optional[dict[str, Any]],
) -> dict[str, Any]:
    status = (
        "published"
        if existing and str(existing.get("status", "")).casefold() == "published"
        else "draft"
    )
    desired: dict[str, Any] = {
        "subject_id": subject_id,
        "title": lesson.title,
        "description": lesson.description,
        "lesson_order": order_start + lesson.lesson_order - 1,
        "passing_mark": 70,
        "status": status,
        "progression_enabled": True,
        "is_free": True,
        "required_credit_points": 0,
        "require_reading": True,
        "require_video": False,
        "require_quiz": False,
        "require_assignment": False,
        "require_teacher_approval": False,
    }
    if existing:
        return {**existing, **desired, "id": existing["id"]}
    return desired


def save_lessons(
    api: DavvagLessonAPI,
    plan: ImportPlan,
    subject_id: int,
    order_start: int,
    state: StateStore,
    summary: Summary,
) -> dict[str, dict[str, Any]]:
    existing = api.list_lessons(subject_id)
    saved: dict[str, dict[str, Any]] = {}
    for lesson in plan.lessons:
        key = lesson.relative_key(plan.source_root)
        current, adopted = resolve_existing_lesson(
            lesson, existing, state, plan.source_root
        )
        desired = lesson_payload(lesson, subject_id, order_start, current)
        if current and not has_changes(current, desired):
            result = current
            summary.lessons_skipped += 1
        else:
            try:
                result = api.call("SaveLesson", desired)
            except ApiError:
                refreshed = api.list_lessons(subject_id)
                recovered = unique_title_match(refreshed, lesson.title)
                if current is None and recovered is not None:
                    result = recovered
                    summary.lessons_adopted += 1
                else:
                    raise
            if not isinstance(result, dict) or int(result.get("id") or 0) < 1:
                raise ApiError(f"SaveLesson returned no ID for {key}")
            if current:
                summary.lessons_updated += 1
            else:
                summary.lessons_created += 1
            existing = [row for row in existing if str(row.get("id")) != str(result["id"])]
            existing.append(result)
        if adopted:
            summary.lessons_adopted += 1
        state.data["lessons"][key] = {"lesson_id": int(result["id"])}
        state.save()
        saved[key] = result
        LOGGER.info("Lesson %s: %s", result["id"], lesson.title)
    return saved


def resolve_existing_content(
    content: ContentPlan,
    rows: list[dict[str, Any]],
    state: StateStore,
    source_root: Path,
    resource_reference: str,
) -> tuple[Optional[dict[str, Any]], bool]:
    key = content.relative_key(source_root)
    entry = state.data.get("content", {}).get(key, {})
    content_id = entry.get("content_id") if isinstance(entry, dict) else None
    if content_id:
        row = find_by_id(rows, content_id)
        if row is None:
            raise PublisherError(
                f"State content ID {content_id} no longer exists for {key}"
            )
        return row, False
    matches = [row for row in rows if str(row.get("url", "")) == resource_reference]
    if len(matches) > 1:
        raise PublisherError(f"Multiple content rows use {resource_reference}")
    return (matches[0], True) if matches else (None, False)


def save_content(
    api: DavvagLessonAPI,
    plan: ImportPlan,
    saved_lessons: dict[str, dict[str, Any]],
    uploaded_images: dict[Path, str],
    state: StateStore,
    summary: Summary,
) -> None:
    for lesson in plan.lessons:
        lesson_key = lesson.relative_key(plan.source_root)
        lesson_id = int(saved_lessons[lesson_key]["id"])
        rows = api.list_content(lesson_id)
        for content in lesson.contents:
            content_key = content.relative_key(plan.source_root)
            _, body = render_content_html(
                content.source_path, plan.source_root, uploaded_images
            )
            resource_reference = upload_file(
                api,
                content.source_path,
                plan.source_root,
                "lesson_content_resource",
                state,
                summary,
            )
            current, adopted = resolve_existing_content(
                content,
                rows,
                state,
                plan.source_root,
                resource_reference,
            )
            desired: dict[str, Any] = {
                "lesson_id": lesson_id,
                "content_type": "article",
                "title": content.title,
                "body": body,
                "url": resource_reference,
                "file_name": content.source_path.name,
                "mime_type": "text/markdown; charset=utf-8",
                "sort_order": content.sort_order,
                "is_required": content.required,
                "status": "published",
            }
            payload = {**current, **desired, "id": current["id"]} if current else desired
            if current and not has_changes(current, payload):
                result = current
                summary.contents_skipped += 1
            else:
                result = api.call("SaveContent", payload)
                if not isinstance(result, dict) or int(result.get("id") or 0) < 1:
                    raise ApiError(f"SaveContent returned no ID for {content_key}")
                if current:
                    summary.contents_updated += 1
                else:
                    summary.contents_created += 1
                rows = [row for row in rows if str(row.get("id")) != str(result["id"])]
                rows.append(result)
            if adopted:
                summary.contents_adopted += 1
            state.data["content"][content_key] = {
                "content_id": int(result["id"]),
                "lesson_id": lesson_id,
            }
            state.save()
            LOGGER.info("Content %s: %s", result["id"], content.title)


def verify_import(
    api: DavvagLessonAPI,
    plan: ImportPlan,
    subject_id: int,
    course_id: int,
    order_start: int,
    state: StateStore,
    *,
    verify_uploads: bool,
    require_published: bool = False,
) -> None:
    lessons = api.list_lessons(subject_id)
    for lesson in plan.lessons:
        lesson_key = lesson.relative_key(plan.source_root)
        state_lesson = state.data.get("lessons", {}).get(lesson_key, {})
        lesson_id = state_lesson.get("lesson_id")
        row = find_by_id(lessons, lesson_id)
        if row is None:
            raise PublisherError(f"Imported lesson is missing: {lesson_key}")
        expected_order = order_start + lesson.lesson_order - 1
        if int(row.get("subject_id") or 0) != subject_id:
            raise PublisherError(f"Lesson subject mismatch: {lesson_key}")
        if int(row.get("course_id") or 0) != course_id:
            raise PublisherError(f"Lesson course mismatch: {lesson_key}")
        if int(row.get("lesson_order") or 0) != expected_order:
            raise PublisherError(f"Lesson order mismatch: {lesson_key}")
        if require_published and str(row.get("status", "")).casefold() != "published":
            raise PublisherError(f"Lesson is not published: {lesson_key}")

        contents = api.list_content(int(lesson_id))
        for content in lesson.contents:
            content_key = content.relative_key(plan.source_root)
            state_content = state.data.get("content", {}).get(content_key, {})
            row_content = find_by_id(contents, state_content.get("content_id"))
            if row_content is None:
                raise PublisherError(f"Imported content is missing: {content_key}")
            if str(row_content.get("status", "")).casefold() != "published":
                raise PublisherError(f"Content is not published: {content_key}")
            body = str(row_content.get("body", ""))
            if "../media/" in body or "..\\media\\" in body:
                raise PublisherError(f"Local media remains in content: {content_key}")
            if not body.strip():
                raise PublisherError(f"Content body is empty: {content_key}")

    if verify_uploads:
        for relative, upload in state.data.get("uploads", {}).items():
            path = (plan.source_root / relative).resolve()
            source_relative(path, plan.source_root)
            if not path.is_file() or not verify_uploaded_bytes(
                api, path, str(upload.get("reference", ""))
            ):
                raise PublisherError(f"Upload verification failed: {relative}")


def publish_lessons(
    api: DavvagLessonAPI,
    plan: ImportPlan,
    subject_id: int,
    state: StateStore,
    summary: Summary,
) -> None:
    rows = api.list_lessons(subject_id)
    for lesson in plan.lessons:
        key = lesson.relative_key(plan.source_root)
        lesson_id = state.data["lessons"][key]["lesson_id"]
        row = find_by_id(rows, lesson_id)
        if row is None:
            raise PublisherError(f"Cannot publish missing lesson: {key}")
        if str(row.get("status", "")).casefold() == "published":
            continue
        updated = api.call("SaveLesson", {**row, "status": "published"})
        if not isinstance(updated, dict) or str(updated.get("status", "")).casefold() != "published":
            raise ApiError(f"Lesson did not publish: {key}")
        summary.lessons_published += 1
        rows = [item for item in rows if str(item.get("id")) != str(lesson_id)]
        rows.append(updated)


def build_session(
    base_url: str,
    *,
    allow_insecure_http: bool,
    insecure_tls: bool,
) -> requests.Session:
    parsed = urlparse(base_url)
    local_host = parsed.hostname in {"localhost", "127.0.0.1", "::1"}
    if parsed.scheme != "https" and not local_host and not allow_insecure_http:
        raise PublisherError(
            "Refusing non-HTTPS remote URL; pass --allow-insecure-http only if intentional"
        )

    session = requests.Session()
    session.verify = not insecure_tls
    session.headers.update(
        {"Accept": "application/json", "User-Agent": "davvag-lesson-publisher/1.0"}
    )
    if insecure_tls:
        LOGGER.warning("TLS certificate verification is disabled")

    token = os.environ.get("DAVVAG_SECURITY_TOKEN")
    if token:
        session.cookies.set("securityToken", token, path="/")
        return session

    email = os.environ.get("DAVVAG_EMAIL")
    password = os.environ.get("DAVVAG_PASSWORD")
    if not email or not password:
        raise PublisherError(
            "Set DAVVAG_SECURITY_TOKEN, or set DAVVAG_EMAIL and DAVVAG_PASSWORD"
        )
    if parsed.scheme != "https" and not local_host and not allow_insecure_http:
        raise PublisherError("Password login requires HTTPS")
    # Use the same public login service as the DAVVAG browser login form.  The
    # Dock auth handler is permission-protected for anonymous callers, so it
    # cannot be used to establish a new session.
    login_url = f"{base_url.rstrip('/')}/components/userapp/login-handler/service/login"
    try:
        response = session.get(
            login_url,
            params={
                "email": email,
                "password": password,
                "domain": parsed.hostname or "",
            },
            timeout=30,
        )
        envelope = response.json()
    except (requests.RequestException, ValueError) as exc:
        raise ApiError(f"DAVVAG login failed: {exc}") from exc
    if not response.ok or not isinstance(envelope, dict) or not envelope.get("success"):
        detail = envelope.get("result") if isinstance(envelope, dict) else envelope
        if isinstance(detail, dict):
            detail = detail.get("message") or detail.get("error") or "login rejected"
        raise ApiError(f"DAVVAG login failed: {detail}")
    result = envelope.get("result")
    if not isinstance(result, dict) or not result.get("token"):
        raise ApiError("DAVVAG login failed: response did not contain a security token")
    return session


def state_path_for(source: Path, subject_code: str, configured: Optional[Path]) -> Path:
    if configured:
        return configured
    safe_code = SAFE_NAME_RE.sub("_", subject_code).strip("._") or "subject"
    return source / f".lesson-import-state-{safe_code}.json"


def build_dry_run(
    plan: ImportPlan,
    subject: dict[str, Any],
    existing: list[dict[str, Any]],
    state: StateStore,
    order_start: int,
) -> dict[str, Any]:
    actions = {"create": 0, "adopt_or_update": 0}
    for lesson in plan.lessons:
        match, _ = resolve_existing_lesson(lesson, existing, state, plan.source_root)
        actions["adopt_or_update" if match else "create"] += 1
    return {
        "mode": "dry-run",
        "subject": {
            "id": int(subject["id"]),
            "code": subject.get("code"),
            "title": subject.get("title"),
            "course_id": int(subject["course_id"]),
        },
        "source": str(plan.source_root),
        "lesson_count": len(plan.lessons),
        "content_count": plan.content_count,
        "media_count": len(plan.media_files),
        "image_occurrences": sum(plan.image_references.values()),
        "order_start": order_start,
        "actions": actions,
        "lessons": [
            {
                "order": order_start + lesson.lesson_order - 1,
                "title": lesson.title,
                "source": lesson.relative_key(plan.source_root),
                "content_blocks": len(lesson.contents),
            }
            for lesson in plan.lessons
        ],
    }
def parse_args(argv: Optional[list[str]] = None) -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description="Publish a converted Markdown textbook to DAVVAG Lesson Manager"
    )
    parser.add_argument(
        "subject_code",
        nargs="?",
        help="Course Manager subject code (for example CATH_11S)",
    )
    parser.add_argument(
        "--subject-code",
        dest="subject_code_option",
        help="Alternative named form of the required subject code",
    )
    parser.add_argument(
        "--base-url",
        default=os.environ.get("DAVVAG_BASE_URL", DEFAULT_BASE_URL),
        help="DAVVAG base URL (default: DAVVAG_BASE_URL or %(default)s)",
    )
    parser.add_argument(
        "--env-file",
        type=Path,
        default=DEFAULT_ENV_FILE,
        help="Login configuration file (default: %(default)s)",
    )
    parser.add_argument(
        "--source",
        type=Path,
        default=DEFAULT_SOURCE,
        help="Converted textbook directory",
    )
    parser.add_argument("--state", type=Path, help="Resume state JSON path")
    parser.add_argument(
        "--include-front-matter",
        action="store_true",
        help="Attach 000-front-matter.md to the first lesson as optional content",
    )
    parser.add_argument(
        "--apply",
        action="store_true",
        help="Upload files and create/update lessons; otherwise perform a dry run",
    )
    parser.add_argument(
        "--publish",
        action="store_true",
        help="Publish verified lessons after applying the draft import",
    )
    parser.add_argument(
        "--verify-only",
        action="store_true",
        help="Verify an existing state/import without making changes",
    )
    parser.add_argument(
        "--allow-insecure-http",
        action="store_true",
        help="Allow a non-HTTPS remote base URL",
    )
    parser.add_argument(
        "--insecure-tls",
        action="store_true",
        help="Disable TLS certificate verification (development only)",
    )
    parser.add_argument("--verbose", action="store_true")
    args = parser.parse_args(argv)

    positional = (args.subject_code or "").strip()
    option = (args.subject_code_option or "").strip()
    if positional and option and normalize_subject_code(positional) != normalize_subject_code(option):
        parser.error("positional subject code and --subject-code do not match")
    args.subject_code = option or positional
    if not args.subject_code:
        parser.error("a subject code is required (positional or --subject-code)")
    if args.publish and not args.apply:
        parser.error("--publish requires --apply")
    if args.verify_only and (args.apply or args.publish):
        parser.error("--verify-only cannot be combined with --apply or --publish")
    return args


def run(args: argparse.Namespace, summary: Optional[Summary] = None) -> Summary:
    mode = "verify" if args.verify_only else ("publish" if args.publish else ("apply" if args.apply else "dry-run"))
    if summary is None:
        summary = Summary(mode=mode, subject_code=args.subject_code)
    else:
        summary.mode = mode
    plan = build_import_plan(args.source, args.include_front_matter)
    summary.lessons_planned = len(plan.lessons)
    summary.contents_planned = plan.content_count
    summary.media_planned = len(plan.media_files)

    session = build_session(
        args.base_url,
        allow_insecure_http=args.allow_insecure_http,
        insecure_tls=args.insecure_tls,
    )
    api = DavvagLessonAPI(args.base_url, session)
    bootstrap = api.bootstrap()
    subject = resolve_subject(bootstrap, args.subject_code)
    subject_id = int(subject["id"])
    course_id = int(subject["course_id"])
    summary.subject_id = subject_id
    summary.course_id = course_id
    LOGGER.info(
        "Resolved subject %s (%s), ID %s, course ID %s",
        subject.get("title", ""),
        subject.get("code", ""),
        subject_id,
        course_id,
    )

    state_path = state_path_for(plan.source_root, args.subject_code, args.state)
    state = StateStore(state_path)
    state.validate_identity(
        api.base_url,
        plan.source_root,
        str(subject.get("code", args.subject_code)),
        subject_id,
        plan.include_front_matter,
    )
    existing = api.list_lessons(subject_id)
    order_start = choose_order_start(plan, existing, state)

    if not args.apply and not args.verify_only:
        summary.details = build_dry_run(plan, subject, existing, state, order_start)
        return summary

    if args.verify_only:
        if not state.exists:
            raise PublisherError(f"Verify-only requires an existing state file: {state.path}")
        verify_import(
            api,
            plan,
            subject_id,
            course_id,
            order_start,
            state,
            verify_uploads=True,
        )
        return summary

    state.initialize(
        api.base_url,
        plan.source_root,
        str(subject.get("code", args.subject_code)),
        subject_id,
        order_start,
        plan.include_front_matter,
    )
    state.save()

    # Create the lesson records before uploading media. This makes schema and
    # authorization failures fail fast instead of leaving avoidable uploads.
    saved_lessons = save_lessons(
        api,
        plan,
        subject_id,
        order_start,
        state,
        summary,
    )

    uploaded_images: dict[Path, str] = {}
    for media_path in plan.media_files:
        uploaded_images[media_path] = upload_file(
            api,
            media_path,
            plan.source_root,
            "lesson_content_image",
            state,
            summary,
        )

    save_content(
        api,
        plan,
        saved_lessons,
        uploaded_images,
        state,
        summary,
    )
    verify_import(
        api,
        plan,
        subject_id,
        course_id,
        order_start,
        state,
        verify_uploads=False,
    )
    if args.publish:
        publish_lessons(api, plan, subject_id, state, summary)
        verify_import(
            api,
            plan,
            subject_id,
            course_id,
            order_start,
            state,
            verify_uploads=False,
            require_published=True,
        )
    return summary


def main(argv: Optional[list[str]] = None) -> int:
    effective_argv = list(sys.argv[1:] if argv is None else argv)
    try:
        load_login_config(env_file_from_args(effective_argv))
    except PublisherError as exc:
        LOGGER.error("%s", exc)
        print(
            console_json(
                {"success": False, "mode": "startup", "error": str(exc)},
            )
        )
        return 1
    args = parse_args(effective_argv)
    logging.basicConfig(
        level=logging.DEBUG if args.verbose else logging.INFO,
        format="%(levelname)s: %(message)s",
    )
    summary = Summary(mode="startup", subject_code=args.subject_code)
    try:
        summary = run(args, summary)
    except (PublisherError, OSError, ValueError) as exc:
        LOGGER.error("%s", exc)
        print(console_json(summary.as_dict(False, str(exc))))
        return 1
    print(console_json(summary.as_dict(True)))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

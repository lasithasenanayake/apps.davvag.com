# Python Textbook-to-Lesson Importer

This document describes the actual Lesson Manager service contract and a safe,
resumable Python workflow for importing the converted textbook at:

```text
C:\projects\education_scraper\output\regression\katholika-g-11-S
```

The importer should use the public component services. It must not insert rows
directly into SOSSData and must not copy files directly into `MEDIA_FOLDER`.

## 1. What the source contains

The source was inspected on 2026-07-29:

| Item | Count | Import treatment |
|---|---:|---|
| Real lesson Markdown files | 25 | Create one Lesson Manager lesson per file |
| Chapter introduction Markdown files | 5 | Add as the first content block of the first lesson in that chapter |
| `000-front-matter.md` | 1 | Optional content/resource on the first lesson; do not make it a required lesson |
| `index.md` | 1 | Import control/navigation only; do not publish it as lesson content |
| Media files (`.jpeg` and `.png`) | 130 | Upload once and replace all local Markdown image paths with DAVVAG references |
| Markdown image occurrences | 226 | All resolve to the 130 media files; repeated images reuse one uploaded URL |

The conversion report says the 140-page conversion completed with zero errors,
zero warnings, zero unresolved spans, and zero image failures. The report's
table of contents identifies 25 lessons, so chapter introductions should not be
turned into five extra lessons unless a curriculum owner explicitly requests a
30-lesson structure.

Recommended lesson order is the numeric directory prefix followed by the
numeric filename prefix. This produces lesson orders 1 through 25.

## 2. Service routes and response envelope

Base application URL examples:

```text
Local Apache folder: http://localhost/davvag-core
Production example:  https://www.ephraimgen.com
```

Configure the exact deployed base URL; do not append a hash route such as
`#/app/lesson-manager`.

All Lesson Manager methods used by the importer are JSON `POST` calls:

```text
POST {BASE_URL}/components/lesson-manager/api/service/{Method}
Content-Type: application/json
Cookie: securityToken=...
```

A successful service response is:

```json
{
  "success": true,
  "result": {}
}
```

An application failure normally has HTTP status 500 and this shape:

```json
{
  "success": false,
  "result": "Error message"
}
```

Framework permission failures can also return `success: false` with a result
object containing `message`. Always check both the HTTP status and `success`.

### Required role and ownership

The API requires:

- an authenticated active profile;
- role `teacher`, `staff`, `admin`, or `sysadmin`; and
- framework permission for each Lesson Manager API operation.

An administrator or staff member can manage every subject. A teacher can only
manage a subject when its `teacher_id` is empty or matches that teacher's
profile ID. Calling `Bootstrap` is the authoritative preflight check because it
only returns courses and subjects that the current user can manage.

## 3. Authentication

Prefer a pre-issued token supplied through an environment variable. Never put
credentials or tokens in source code, the state file, command-line arguments,
or logs.

The supplied Python publisher automatically reads login configuration from
`C:\xampp\htdocs\davvag-core\davvag-core\localhost\global\config\.env`.
It loads only `DAVVAG_BASE_URL`, `DAVVAG_EMAIL`, `DAVVAG_PASSWORD`, and the
optional `DAVVAG_SECURITY_TOKEN`; existing process environment variables have
priority. Use `--env-file` to select another file.

```python
import os
from urllib.parse import urlparse

import requests


def authenticated_session(base_url: str) -> requests.Session:
    session = requests.Session()
    session.headers.update({
        "Accept": "application/json",
        "User-Agent": "davvag-lesson-importer/1.0",
    })

    token = os.environ.get("DAVVAG_SECURITY_TOKEN")
    if token:
        session.cookies.set("securityToken", token, path="/")
        return session

    email = os.environ["DAVVAG_EMAIL"]
    password = os.environ["DAVVAG_PASSWORD"]
    response = session.get(
        f"{base_url.rstrip('/')}/components/userapp/login-handler/service/login",
        params={
            "email": email,
            "password": password,
            "domain": urlparse(base_url).hostname,
        },
        timeout=30,
    )
    response.raise_for_status()
    data = response.json()
    if not data.get("success"):
        raise RuntimeError(f"DAVVAG login failed: {data.get('result')}")
    return session
```

The fallback uses the same public `userapp/login-handler` service as the browser
login form and supplies the target hostname as `domain`. Do not use the Dock
`auth-handler` here: anonymous callers cannot access it, so it cannot establish
a session. The login sends credentials as HTTPS query parameters because that
is the current framework contract. Use it only over HTTPS. For production
automation, `DAVVAG_SECURITY_TOKEN` is preferable.

## 4. Minimal Python API client

```python
from typing import Any


class DavvagLessonAPI:
    def __init__(self, base_url: str, session):
        self.base_url = base_url.rstrip("/")
        self.session = session

    def call(self, method: str, payload: dict[str, Any] | None = None) -> Any:
        url = (
            f"{self.base_url}/components/lesson-manager/api/service/{method}"
        )
        response = self.session.post(url, json=payload or {}, timeout=90)
        try:
            envelope = response.json()
        except ValueError as exc:
            raise RuntimeError(
                f"{method} returned non-JSON HTTP {response.status_code}: "
                f"{response.text[:500]}"
            ) from exc

        if not response.ok or not envelope.get("success"):
            detail = envelope.get("result", envelope)
            if isinstance(detail, dict):
                detail = detail.get("message", detail)
            raise RuntimeError(
                f"{method} failed with HTTP {response.status_code}: {detail}"
            )
        return envelope.get("result")
```

## 5. Resolve and validate the subject

Every lesson must have `subject_id`. `SaveLesson` loads the subject and derives
`course_id` from it; a submitted `course_id` is overwritten. Do not guess the
course relationship locally.

```python
bootstrap = api.call("Bootstrap", {})

subjects = bootstrap["subjects"]
subject_code = configured_subject_code.strip().casefold()
matches = [
    subject
    for subject in subjects
    if str(subject.get("code", "")).strip().casefold() == subject_code
]
if len(matches) != 1:
    raise RuntimeError(
        "The subject code must match exactly one manageable Bootstrap subject. "
        "Check its code, teacher ownership, active profile, role, and permissions."
    )

subject = matches[0]
subject_id = int(subject["id"])
course_id = int(subject["course_id"])
if course_id < 1:
    raise RuntimeError("The selected subject is not assigned to a course.")
```

Require a subject code in the Python program and resolve it case-insensitively
against `Bootstrap`. The importer must fail when it matches zero or more than
one subject. Print the resolved subject ID, title/code, and course ID and require
them to pass preflight before writing anything.

## 6. APIs used by the importer

### `Bootstrap`

Request:

```json
{}
```

Returns `role`, `profile`, manageable `courses`, manageable `subjects`, class
grades, assignments, and profiles. Use it for authentication and subject
preflight.

### `ListLessons`

Request:

```json
{"subject_id": 123}
```

Optional filters are `id`, `course_id`, `subject_id`, `status`, and `search`.
Use this method for duplicate detection and post-import verification.

### `SaveLesson`

Create request:

```json
{
  "subject_id": 123,
  "title": "Lesson title",
  "description": "Short lesson description",
  "lesson_order": 1,
  "passing_mark": 70,
  "status": "draft",
  "progression_enabled": true,
  "is_free": true,
  "required_credit_points": 0,
  "require_reading": true,
  "require_video": false,
  "require_quiz": false,
  "require_assignment": false,
  "require_teacher_approval": false
}
```

Important behavior:

- `subject_id` and `title` are required.
- `course_id` is derived from the subject.
- `lesson_order` defaults to the next subject position if missing or less than
  one. The importer should still send the explicit global order.
- status defaults to `draft`; use draft during import.
- passing mark defaults to 70.
- a non-free lesson requires an integer `required_credit_points >= 1`.
- the server sets `created_by`, `created_at`, and `updated_at`.
- sending a published lesson can queue a lesson-published notification. Do not
  repeatedly save already-published lessons without a real change.

An update includes `id`. For safety, merge changes into the complete object
returned by `ListLessons` and submit the full object rather than assuming a
partial update.

### `ReorderLessons`

```json
{
  "lessons": [
    {"id": 501},
    {"id": 502},
    {"id": 503}
  ]
}
```

The request must contain every lesson in the subject exactly once, and all
must belong to that same subject. The server rewrites orders to 1..N based on
array order. Do not use it unless the importer intentionally owns the complete
subject ordering; otherwise it could move pre-existing lessons.

### `ListContent`

```json
{"lesson_id": 501}
```

Optional filters are `id`, `lesson_id`, `content_type`, `status`, and `search`.

### `SaveContent`

```json
{
  "lesson_id": 501,
  "content_type": "article",
  "title": "Lesson title",
  "body": "<p>Sanitized rich HTML...</p>",
  "url": "components/dock/soss-uploader/service/get/lesson_content_resource/lm-abcd-source.md",
  "file_name": "001-source.md",
  "mime_type": "text/markdown; charset=utf-8",
  "sort_order": 1,
  "is_required": true,
  "status": "published"
}
```

`lesson_id` and `title` are required. The URL must be HTTPS or an approved
DAVVAG uploader reference. The body is sanitized and is expected to be HTML,
not Markdown. Include `id` to update an existing content row.

The learner service currently retrieves content in ascending storage order and
does not explicitly sort by `sort_order`. Therefore create content blocks
sequentially in display order as well as setting `sort_order`; do not create a
single lesson's content blocks concurrently.

### Optional video import

The supplied textbook has no video files. If a future source contains videos,
upload local video bytes to namespace `lesson_video` and call `SaveVideo`:

```json
{
  "lesson_id": 501,
  "title": "Video title",
  "provider": "local",
  "video_url": "",
  "media_reference": "components/dock/soss-uploader/service/get/lesson_video/lm-abcd-video.mp4",
  "thumbnail_url": "",
  "duration_seconds": 0,
  "transcript": "",
  "caption_url": "",
  "sort_order": 1,
  "is_required": false,
  "status": "published"
}
```

`SaveVideo` requires a lesson, title, and either a supported URL or valid local
media reference.

## 7. Upload files correctly

The browser Studio uses these namespaces:

| Purpose | Namespace |
|---|---|
| Images embedded in rich lesson content | `lesson_content_image` |
| Downloadable source/resource files | `lesson_content_resource` |
| Local lesson videos | `lesson_video` |
| Assignment support files | `lesson_assignment_support` |

The upload service accepts the raw file bytes, not `multipart/form-data`:

```text
POST {BASE_URL}/components/dock/soss-uploader/service/upload_uncompressed/{namespace}/{safe_name}
```

The public reference saved into lesson content is:

```text
components/dock/soss-uploader/service/get/{namespace}/{safe_name}
```

The upload response intentionally contains the legacy misspelling `sucess`.
Check the HTTP status and this field, then download the file and compare its
SHA-256 digest. A GET alone is insufficient because the uploader can return a
fallback file when a name is missing.

```python
import hashlib
import mimetypes
import re
from pathlib import Path
from urllib.parse import quote


SAFE_NAME = re.compile(r"[^A-Za-z0-9._-]+")


def stable_upload_name(path: Path, source_root: Path) -> str:
    relative = path.resolve().relative_to(source_root.resolve()).as_posix()
    digest = hashlib.sha256(relative.encode("utf-8")).hexdigest()[:16]
    basename = SAFE_NAME.sub("_", path.name).strip("._") or "file"
    return f"lm-{digest}-{basename}"


def upload_file(api: DavvagLessonAPI, path: Path, source_root: Path,
                namespace: str) -> str:
    raw = path.read_bytes()
    name = stable_upload_name(path, source_root)
    upload_url = (
        f"{api.base_url}/components/dock/soss-uploader/service/"
        f"upload_uncompressed/{quote(namespace, safe='')}/{quote(name, safe='')}"
    )
    content_type = mimetypes.guess_type(path.name)[0] or "application/octet-stream"
    response = api.session.post(
        upload_url,
        data=raw,
        headers={"Content-Type": content_type},
        timeout=180,
    )
    response.raise_for_status()
    try:
        result = response.json()
    except ValueError as exc:
        raise RuntimeError(f"Upload returned non-JSON for {path}") from exc
    if result.get("sucess") is not True:
        raise RuntimeError(f"Upload failed for {path}: {result}")

    reference = (
        "components/dock/soss-uploader/service/get/"
        f"{namespace}/{name}"
    )
    verification = api.session.get(
        f"{api.base_url}/{reference}", timeout=180
    )
    verification.raise_for_status()
    if hashlib.sha256(verification.content).digest() != hashlib.sha256(raw).digest():
        raise RuntimeError(f"Uploaded bytes do not match {path}")
    return reference
```

Use deterministic names. Re-uploading the same source path then overwrites the
same destination instead of leaving timestamp-named orphan files. The uploader
does not provide a delete API or media metadata row.

An image is not attached to a lesson merely because it was uploaded. Its
returned reference must appear in an `<img src="...">` inside the `body` passed
to `SaveContent`. Likewise, an uploaded Markdown source is associated with a
content row through that row's `url`, `file_name`, and `mime_type`.

## 8. Convert Markdown to compatible HTML

Install the importer dependencies in its virtual environment:

```text
python -m pip install requests Markdown beautifulsoup4
```

Do not send raw Markdown to `SaveContent`. The learner view inserts `body` as
HTML and will display raw Markdown syntax instead of rendering it.

The server permits these rich-text tags:

```text
p br h1 h2 h3 h4 strong b em i u s strike ol ul li a img
blockquote pre code hr span div
```

It removes scripts, styles, event handlers, inline `style`, iframes, forms,
SVG, and unsupported tags. It accepts `href` and `src` values only when they are
HTTP(S), `mailto:`, `tel:`, an anchor, or an approved uploader reference.

Two source features need preprocessing:

1. The textbook contains Markdown tables, but `table`, `tr`, `th`, and `td`
   are not in the current server allowlist. Convert tables to mobile-friendly
   `<div>/<p>` groups before saving, or the table tags will be stripped and the
   cells will run together.
2. The source contains level-five headings. Normalize `h5` and `h6` to `h4`
   because only `h1` through `h4` survive sanitization.

Recommended transformation sequence:

```python
from pathlib import Path
from urllib.parse import unquote, urlparse

import markdown
from bs4 import BeautifulSoup


def local_path(md_path: Path, raw_reference: str, source_root: Path) -> Path:
    parsed = urlparse(raw_reference)
    if parsed.scheme or parsed.netloc:
        raise ValueError(f"Expected a local media path, got {raw_reference}")
    target = (md_path.parent / unquote(parsed.path)).resolve()
    target.relative_to(source_root.resolve())  # rejects path traversal
    if not target.is_file():
        raise FileNotFoundError(target)
    return target


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


def markdown_to_lesson_html(
    md_path: Path,
    source_root: Path,
    uploaded_images: dict[Path, str],
) -> tuple[str, str]:
    source = md_path.read_text(encoding="utf-8-sig")
    html = markdown.markdown(
        source,
        extensions=["extra", "sane_lists"],
        output_format="html5",
    )
    soup = BeautifulSoup(html, "html.parser")

    title_node = soup.find("h1")
    title = (
        title_node.get_text(" ", strip=True)
        if title_node
        else md_path.stem.split("-", 1)[-1].replace("-", " ")
    )
    if title_node:
        title_node.decompose()  # Lesson header already displays the title

    for heading in soup.find_all(["h5", "h6"]):
        heading.name = "h4"

    for image in soup.find_all("img"):
        source_image = local_path(md_path, image.get("src", ""), source_root)
        try:
            image["src"] = uploaded_images[source_image]
        except KeyError as exc:
            raise RuntimeError(f"Image was not uploaded: {source_image}") from exc

    make_tables_mobile(soup)
    return title.strip(), str(soup).strip()
```

Also enforce that every external Markdown link or image is HTTPS. The current
dataset's non-image links occur only in `index.md`, which is intentionally not
published.

## 9. Recommended mapping for this textbook

Build a plan before any API write:

1. Ignore `index.md` as content.
2. Sort the five numeric chapter directories.
3. In each directory, read `000-chapter-introduction.md` separately.
4. Sort files `001-*.md`, `002-*.md`, and so on.
5. Create one lesson for each of those files, yielding exactly 25 lessons.
6. Attach the chapter introduction as the first article on the chapter's first
   lesson. Attach the actual lesson article after it.
7. Optionally attach `000-front-matter.md` as a non-required first article and
   downloadable Markdown resource on lesson 1.
8. Upload each Markdown source to `lesson_content_resource` and set its article
   row's `url`; this preserves the original source while `body` supplies the
   rendered learner view.
9. Upload each unique referenced image to `lesson_content_image`, and reuse the
   same returned reference for duplicate occurrences.

Example content order on the first lesson of a chapter:

| `sort_order` | Content | Required |
|---:|---|---|
| 1 | Textbook front matter, only on lesson 1 if enabled | false |
| 2 | Chapter introduction | true |
| 3 | Actual lesson Markdown | true |

For other first lessons omit the front matter and use orders 1 and 2. For the
remaining lessons use order 1 for the actual lesson article.

Lesson descriptions should be plain text derived from the first meaningful
paragraph and truncated well below the schema limit of 5,000 characters. Lesson
titles are limited to 255 characters.

## 10. End-to-end import algorithm

Use three phases.

### Phase A: preflight and plan

1. Validate `conversion-report.json`: `complete` must be true; `errors`,
   `warnings`, and `unresolved` must be empty.
2. Validate `image-manifest.json`: `failures` must be empty.
3. Resolve every local image path and reject anything outside the source root.
4. Confirm all expected files are UTF-8/UTF-8-SIG readable.
5. Build the 25-lesson plan and verify unique orders and non-empty H1 titles.
6. Call `Bootstrap` and resolve exactly one manageable `subject_id`.
7. Call `ListLessons` for that subject and report collisions before writing.
8. Print a dry-run summary: subject, course, lesson count, content count, unique
   media count, and total bytes.

Dry-run should be the default. Require an explicit `--apply` to write.

### Phase B: upload and save drafts

1. Upload the 130 unique images. This work may be concurrent with a small,
   bounded pool such as four workers.
2. Upload each Markdown source used by a content row to
   `lesson_content_resource`.
3. Create or update all 25 lessons with `status: draft`.
4. For each lesson, sequentially create/update its content blocks in display
   order.
5. Re-list lessons and content and verify IDs, subject/course relationships,
   orders, titles, statuses, and uploader references.
6. GET every unique uploaded reference and compare SHA-256.

### Phase C: publish only after validation

Publishing must be a separate explicit command such as `--publish`.

1. Validate all draft lessons and content again.
2. Change each lesson from `draft` to `published` using its complete stored
   object plus the new status.
3. Do not re-save lessons whose status is already `published`.
4. Call `ListLessons({"subject_id": ...})` and confirm exactly the intended
   lessons are published.
5. Open the learner view and visually check Sinhala text, tables, images,
   chapter introductions, mobile layout, and lesson progression.

## 11. Idempotency and resume state

The service has no idempotency key or source-path field. A robust importer must
maintain a local UTF-8 JSON state file, for example:

```json
{
  "format_version": 1,
  "base_url": "https://www.example.com",
  "subject_code": "CATH_11S",
  "subject_id": 123,
  "source_root": "C:/projects/education_scraper/output/regression/katholika-g-11-S",
  "lessons": {
    "001-.../001-....md": {"lesson_id": 501, "content_id": 901}
  },
  "content": {
    "001-.../000-chapter-introduction.md": {"content_id": 900}
  },
  "uploads": {
    "media/p003-img001.png": {
      "sha256": "...",
      "reference": "components/dock/soss-uploader/service/get/lesson_content_image/lm-...-p003-img001.png"
    }
  }
}
```

Do not store tokens or passwords in this file. Write it atomically after each
successful API operation (`temporary file -> flush/fsync -> replace`).

Resume rules:

- If a state lesson ID still exists under the configured subject, update that
  record.
- If state is missing, only adopt a server lesson when subject ID and title
  match uniquely. Otherwise fail and require manual mapping.
- Use state content IDs to update content. A title match alone is not a safe
  content identity.
- If source bytes and stored SHA-256 are unchanged, verify the remote upload and
  skip uploading.
- Never create a second record merely because a previous request timed out.
  Re-list records first; the server may have committed before the connection
  failed.
- Add exponential backoff only for network failures, HTTP 429, and HTTP 5xx
  responses that are not normal validation errors. Limit attempts.

## 12. Validation checklist

Before reporting success, assert all of the following:

- `Bootstrap` returns the selected subject and its course.
- There are exactly 25 intended lesson records in state.
- Every imported lesson has the selected `subject_id` and derived `course_id`.
- Lesson orders are unique and cover 1..25 within the import plan.
- Every lesson has at least one published article content row.
- The five chapter introductions are attached exactly once.
- Every content body contains HTML rather than raw Markdown syntax.
- No body contains local `../media/...` paths.
- All 226 image occurrences resolve to the 130 verified uploader references.
- The optional front matter is non-required and `index.md` was not published.
- Draft mode created no published lessons until the publish phase.
- No lesson requires video, quiz, assignment, or teacher approval unless those
  assets were actually created.
- Sinhala titles and paragraphs round-trip as UTF-8 without mojibake.
- Table content remains readable after the mobile table conversion.

## 13. Suggested command-line interface

```text
python lesson_publisher.py \
  --base-url https://www.example.com \
  --source "C:\projects\education_scraper\output\regression\katholika-g-11-S" \
  --subject-code CATH_11S \
  --state lesson-import-state.json

# Apply uploads and create/update drafts
python lesson_publisher.py ... --apply

# Publish only after review
python lesson_publisher.py ... --apply --publish
```

Recommended switches:

- subject code required, either positional or through `--subject-code`
- `--state` required for apply/resume
- `--apply` required for mutations
- `--publish` separate opt-in
- `--include-front-matter` optional
- `--max-upload-workers 4`
- `--verify-only` checks remote state without writing

Exit non-zero on any missing file, ambiguous subject/record, API failure,
digest mismatch, invalid source path, or validation failure. Print a final
machine-readable summary containing created, updated, skipped, uploaded,
verified, and failed counts.

## 14. Source files that define this contract

These instructions were derived from the current code, principally:

```text
apps/lesson-manager/services/api/component.json
apps/lesson-manager/services/api/service.php
apps/lesson-manager/components/studio/script.js
apps/lesson-manager/components/learn/partial.html
schemas/lesson_manager_lesson.json
schemas/lesson_manager_content.json
schemas/lesson_manager_video.json
schemas/course_manager_subject.json
apps/dock/shell/soss-uploader/service.php
apps/davvag-tools/services/davvag-file-uploader/script.js
```

If any of these service or schema versions change, re-check authentication,
the response envelope, uploader behavior, allowed HTML tags, sort behavior,
and required fields before running the importer against production.

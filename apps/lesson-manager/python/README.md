# Lesson Manager Python Publisher

`lesson_publisher.py` imports a converted Markdown textbook into Lesson Manager.
The subject is selected by its Course Manager **subject code**, not by a numeric
ID. The API validates that the signed-in teacher or administrator can manage the
matching subject and derives the course from it.

## Installation

On this machine, `python.exe` resolves to the Microsoft Store alias. The
included `run_publisher.cmd` bypasses that alias and automatically uses:

```text
C:\projects\education_scraper\.venv\Scripts\python.exe
```

Its required packages are already installed. To use a different interpreter,
set `LESSON_PUBLISHER_PYTHON` to its full path. A new local environment can be
created with the working Windows Python launcher if needed:

```powershell
cd C:\xampp\htdocs\davvag-core\davvag-core\localhost\apps\lesson-manager\python
py -3 -m venv .venv
.\.venv\Scripts\python.exe -m pip install -r requirements.txt
```

The publisher automatically reads login configuration from:

```text
C:\xampp\htdocs\davvag-core\davvag-core\localhost\global\config\.env
```

It loads only `DAVVAG_BASE_URL`, `DAVVAG_EMAIL`, `DAVVAG_PASSWORD`, and the
optional `DAVVAG_SECURITY_TOKEN`. Other settings and secrets in the file are
ignored. Existing operating-system environment variables take priority over
the file. Values are never copied into the import state or printed to logs.

Pass `--env-file C:\path\to\another.env` to use another configuration file.
Password login must use HTTPS except for localhost development.

## Commands

The current textbook source is the default, so only a subject code is needed
for preflight:

```powershell
# Dry run: no uploads or database writes
.\run_publisher.cmd CATH_11S

# The named form is also accepted
.\run_publisher.cmd --subject-code CATH_11S

# Upload all media and create/update draft lessons
.\run_publisher.cmd CATH_11S --apply

# Publish after the draft import passes verification
.\run_publisher.cmd CATH_11S --apply --publish

# Verify a completed import without changing it
.\run_publisher.cmd CATH_11S --verify-only
```

Use a different source or server when required:

```powershell
.\run_publisher.cmd SUBJECT-CODE `
  --base-url https://school.example.com `
  --source C:\path\to\converted-textbook `
  --apply
```

The command is dry-run by default. `--publish` cannot be used without
`--apply`. The optional `--include-front-matter` switch attaches
`000-front-matter.md` to the first lesson as non-required content.

## Resume state

The publisher writes an atomic state file under the textbook source directory:

```text
.lesson-import-state-{subject-code}.json
```

It stores Lesson Manager IDs, content IDs, upload references, and source hashes.
It never stores a password or security token. Pass `--state PATH` to choose a
different location. Keep the state file for safe retries and verification.

If an apply run stops after uploading files, rerun the same command without
deleting the state file. Verified uploads are reused. A server error containing
`NULLDEFAULT` means the target server still has the older `phpmysql`
`mysqlConnector.php`; deploy the corrected shared adapter before retrying.

## Import behavior

- Resolves an exact, case-insensitive Course Manager subject code through
  `Bootstrap`; zero or multiple matches stop the import.
- Validates the conversion and image reports before contacting write APIs.
- Creates one lesson per numbered lesson Markdown file.
- Attaches a chapter introduction to that chapter's first lesson when the
  optional `000-chapter-introduction.md` file is present.
- Accepts chapter-only conversions where `000-chapter.md` is the lesson.
- Creates or validates draft lesson records before beginning media uploads.
- Uploads all supported files in `media` to `lesson_content_image`.
- Uploads source Markdown to `lesson_content_resource`.
- Converts Markdown to sanitized-compatible HTML and rewrites embedded images.
- Converts tables to mobile-friendly blocks and changes H5/H6 headings to H4.
- Creates drafts first and publishes only after verification.
- Uses deterministic upload names and verifies downloaded bytes with SHA-256.
- Appends after unrelated existing lessons rather than overwriting their order.

See [../api_instruction.md](../api_instruction.md) for the underlying service
contract and design details.

## PDF textbook publisher

`pdf_lesson_publisher.py` imports a textbook directly from PDF while keeping
the original page design. It detects numbered lesson-opening pages first and
falls back to repeated heading typography when a PDF has no bookmarks. The
sample Sinhala textbooks use legacy font encodings, so a detected title may be
unreadable even though the rendered page is correct. Such titles are replaced
with an explicit page-range fallback in the generated manifest.

Start with a dry run. Quote the complete Windows path because it may contain
spaces or `&`:

```powershell
.\run_pdf_publisher.cmd CHRISTIANITY_G10_S `
  --source "C:\path\to\Christianity G10 Sinhala new.pdf"
```

The first run writes a JSON lesson plan under `.lesson-manager-pdf` in the
current working directory. Review its chapter/lesson page ranges and replace
any fallback titles, then set `"approved": true`. Pass that plan explicitly if
you run the command from another directory:

```powershell
.\run_pdf_publisher.cmd CHRISTIANITY_G10_S `
  --source "C:\path\to\Christianity G10 Sinhala new.pdf" `
  --manifest "C:\path\to\reviewed.lesson-plan.json" `
  --apply

# Publish only after the draft import and asset verification succeed
.\run_pdf_publisher.cmd CHRISTIANITY_G10_S `
  --source "C:\path\to\Christianity G10 Sinhala new.pdf" `
  --manifest "C:\path\to\reviewed.lesson-plan.json" `
  --apply --publish
```

Apply mode performs these operations:

- Splits the source into one page-faithful PDF per manifest lesson.
- Uploads the original and split PDFs to the tenant-managed
  `lesson_manager_assets` area and verifies every upload by SHA-256.
- Extracts full embedded TTF/OTF/WOFF/WOFF2 fonts once, deduplicates them by
  hash, and registers only fonts whose embedding flags allow reusable use.
  PDF-only subset/CID fonts remain inside the split PDF and are reported as
  non-reusable instead of filling the assets area with partial font copies.
- Records skipped or restricted fonts in the JSON result. `--no-fonts`
  disables font extraction.
- Creates a `pdf_embed` material so learners can read the split PDF in Lesson
  Manager and can also open it in a separate tab.
- Uses an atomic resume state so reruns reuse verified records and assets.

The manifest SHA-256 and page count must still match the source. Apply mode
will not use an unapproved plan unless `--accept-detected-plan` is supplied
explicitly. `--refresh-manifest` reruns detection but refuses to overwrite an
approved plan.

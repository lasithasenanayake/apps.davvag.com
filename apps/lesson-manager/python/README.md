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

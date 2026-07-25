# DAVVAG destination scraper

This Python package researches a named destination with the OpenAI Responses API
and hosted web search, validates the evidence, logs in to DAVVAG, saves the required
draft, and submits that draft for moderation.

The normal command performs a real DAVVAG submission. Use `--research-only` while
configuring or evaluating the system.

## Requirements

- Python 3.10 or newer
- An OpenAI API key with billing/access for the selected model and web search
- Apache and MySQL running for the DAVVAG installation
- A DAVVAG `web_user` account with a linked tenant profile
- Travel Destinations permissions and categories installed

An API key is for the OpenAI API; it is separate from a ChatGPT subscription.
The code uses the Responses API, the `web_search` tool, and strict JSON Schema output.
The default model is `gpt-5.6`, but `OPENAI_MODEL` or `--model` can override it.

Official references:

- <https://developers.openai.com/api/docs/guides/tools-web-search>
- <https://developers.openai.com/api/docs/guides/structured-outputs>
- <https://developers.openai.com/api/docs/guides/latest-model>
- <https://github.com/openai/openai-python>

## Install

Open PowerShell in this `scraper` directory:

```powershell
uv sync --python 3.11
```

This repository includes `uv.lock`, so `uv` installs the validated dependency set.
If `uv` is not available, use a normal virtual environment instead:

```powershell
py -3 -m venv .venv
.\.venv\Scripts\Activate.ps1
python -m pip install --upgrade pip
python -m pip install -e .
```

If `py -3` is unavailable, install a current Python release and reopen PowerShell.

## Configure secrets

Set secrets in the process environment, or copy `.env.example` to an ignored `.env`
inside this directory and fill it locally. The CLI loads that file when it is run from
this directory. Do not add real values to `.env.example` or source control.

```powershell
$env:OPENAI_API_KEY = "sk-..."
$env:DAVVAG_BASE_URL = "http://localhost/davvag-core"
$env:DAVVAG_EMAIL = "traveler@example.com"
$env:DAVVAG_PASSWORD = "replace-with-the-password"
```

Optional configuration:

```powershell
$env:OPENAI_MODEL = "gpt-5.6"
$env:DESTINATION_COUNTRY = "Sri Lanka"
$env:DESTINATION_REASONING_EFFORT = "medium"
$env:DESTINATION_LOCATION_PRIVACY = "approved_only"
```

`approved_only` is intentionally the default coordinate privacy for automatically
researched places. An administrator can verify and change visibility during moderation.

## Research first

Supply a clear place name. Seed URLs are opened first, and hosted web search looks for
additional sources.

```powershell
uv run python -m destination_scraper `
  "Bambarakanda Falls, Sri Lanka" `
  --url "https://example.gov.lk/official-page" `
  --url "https://example.org/corroborating-page" `
  --research-only `
  --output ".\output\bambarakanda-research.json"
```

The package treats page content as untrusted, ignores instructions embedded in pages,
requires source URLs to appear in the web-search trace, and will report potential
duplicates without writing in research-only mode.

## Writing style

Generated descriptions are structured as practical, inspiring website posts rather than
raw research notes. They use concise Markdown sections, a supported-facts table, clear
access and difficulty explanations, destination-specific equipment guidance, current
safety context, and responsible-travel advice. Unsupported sections are omitted.

The style was adapted from the Pekoe Trail example in `RESEARCH_INSTRUCTIONS.md`.
Christian language is added only when the researched route or event is explicitly
branded **Walk for Christ**; it is never added to an ordinary destination.

## Automatically submit

Remove `--research-only`. The default mode performs both DAVVAG write operations:
`SaveDestinationDraft`, followed by `SubmitDestination`.

```powershell
uv run python -m destination_scraper `
  "Bambarakanda Falls, Sri Lanka" `
  --url "https://example.gov.lk/official-page" `
  --url "https://example.org/corroborating-page" `
  --output ".\output\bambarakanda-submission.json"
```

A successful result contains:

```json
{
  "mode": "submit",
  "submission": {
    "id": 123,
    "status": "Pending Review"
  }
}
```

The destination is not immediately public. It enters the administrator moderation
queue as `Pending Review`.

## Console progress

The command reports each major step to the console while it works:

```text
[process] Logging in to DAVVAG...
[process] Loaded 6 categories and 12 amenities.
[process] Asking OpenAI to search the web and prepare a sourced destination record...
[process] Research complete: 4 verified sources, confidence 'high'.
[process] Verified source 1: https://example.gov.lk/official-page
[process] Verified source 2: https://example.org/corroborating-page
[process] Checking DAVVAG for possible duplicate destinations...
[process] Saving the destination as a DAVVAG draft...
[process] Submitting the saved draft for administrator review...
[process] Submission complete: destination 123 is Pending Review.
```

Progress is written to standard error, while the final structured JSON remains on
standard output. This keeps output redirection and automation reliable. Add `--quiet`
to suppress progress messages. Credentials and API keys are never printed.

## Save only a draft

```powershell
uv run python -m destination_scraper "Destination name, Sri Lanka" --draft-only
```

## Automatic-write safeguards

Before saving anything, the package requires:

- An authenticated DAVVAG account with a linked profile
- A category slug returned by the live DAVVAG API
- Non-empty name, summary, and description
- Valid latitude and longitude
- At least two source URLs confirmed in the OpenAI web-search trace
- Better than low research confidence, unless explicitly overridden
- No likely name duplicate among published destinations or the account's submissions

If a duplicate is expected and has been manually reviewed, `--force-duplicate` bypasses
only the duplicate block. `--allow-low-confidence` bypasses only the confidence block;
it does not bypass missing coordinates, sources, categories, or required text.

If draft creation succeeds but final submission fails, the draft remains in DAVVAG so
it can be inspected or corrected.

## Use as a library

```python
import os

from destination_scraper import (
    DavvagClient,
    DestinationPipeline,
    DestinationResearcher,
)

davvag = DavvagClient(os.environ["DAVVAG_BASE_URL"])
researcher = DestinationResearcher(
    api_key=os.environ["OPENAI_API_KEY"],
    model=os.getenv("OPENAI_MODEL", "gpt-5.6"),
    country="Sri Lanka",
)
pipeline = DestinationPipeline(
    davvag=davvag,
    researcher=researcher,
    email=os.environ["DAVVAG_EMAIL"],
    password=os.environ["DAVVAG_PASSWORD"],
)

try:
    result = pipeline.run(
        destination_name="Bambarakanda Falls, Sri Lanka",
        seed_urls=[
            "https://example.gov.lk/official-page",
            "https://example.org/corroborating-page",
        ],
        mode="submit",
    )
    print(result.submission)
finally:
    davvag.close()
```

## Exit codes

- `0`: completed requested mode
- `1`: research, validation, duplicate, DAVVAG, or submission failure
- `2`: missing configuration or credentials

## Tests

After installing the package:

```powershell
uv run python -m unittest discover -s tests -v
```

The tests are offline and do not call OpenAI or DAVVAG.

from __future__ import annotations

import argparse
import getpass
import json
import os
import sys
from pathlib import Path
from typing import Sequence

from dotenv import load_dotenv

from .davvag import DavvagApiError, DavvagClient
from .models import ValidationError
from .pipeline import DestinationPipeline
from .researcher import DestinationResearcher, ResearchError


def _parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(
        prog="davvag-destination-scraper",
        description=(
            "Research a destination with OpenAI web search and automatically submit "
            "it to DAVVAG for moderation."
        ),
    )
    parser.add_argument("destination", help="Destination name and optional region.")
    parser.add_argument(
        "--url",
        action="append",
        default=[],
        help="Seed URL to inspect first. Repeat this option for multiple URLs.",
    )
    mode = parser.add_mutually_exclusive_group()
    mode.add_argument(
        "--research-only",
        action="store_true",
        help="Research and validate without writing to DAVVAG.",
    )
    mode.add_argument(
        "--draft-only",
        action="store_true",
        help="Save a DAVVAG draft but do not submit it for review.",
    )
    parser.add_argument(
        "--base-url",
        default=os.getenv("DAVVAG_BASE_URL", "http://localhost/davvag-core"),
        help="DAVVAG base URL (default: DAVVAG_BASE_URL or localhost).",
    )
    parser.add_argument(
        "--email",
        default=os.getenv("DAVVAG_EMAIL", ""),
        help="DAVVAG email (prefer DAVVAG_EMAIL for unattended runs).",
    )
    parser.add_argument(
        "--model",
        default=os.getenv("OPENAI_MODEL", "gpt-5.6"),
        help="OpenAI model (default: OPENAI_MODEL or gpt-5.6).",
    )
    parser.add_argument(
        "--country",
        default=os.getenv("DESTINATION_COUNTRY", "Sri Lanka"),
        help="Country or region used to disambiguate the destination.",
    )
    parser.add_argument(
        "--reasoning-effort",
        choices=["none", "low", "medium", "high", "xhigh", "max"],
        default=os.getenv("DESTINATION_REASONING_EFFORT", "medium"),
    )
    parser.add_argument(
        "--location-privacy",
        choices=[
            "exact_public",
            "approximate_public",
            "hidden_sensitive",
            "approved_only",
        ],
        default=os.getenv("DESTINATION_LOCATION_PRIVACY", "approved_only"),
        help="DAVVAG coordinate visibility; approved_only is the safe default.",
    )
    parser.add_argument(
        "--force-duplicate",
        action="store_true",
        help="Continue after a likely duplicate is found.",
    )
    parser.add_argument(
        "--allow-low-confidence",
        action="store_true",
        help="Permit a write when model confidence is low; other checks still apply.",
    )
    parser.add_argument(
        "--output",
        type=Path,
        help="Also write the sanitized result JSON to this file.",
    )
    parser.add_argument(
        "--insecure",
        action="store_true",
        help="Disable DAVVAG TLS certificate verification (local development only).",
    )
    parser.add_argument(
        "--quiet",
        action="store_true",
        help="Suppress progress messages; the final JSON is still printed.",
    )
    return parser


def main(argv: Sequence[str] | None = None) -> int:
    load_dotenv(dotenv_path=Path.cwd() / ".env", override=False)
    args = _parser().parse_args(argv)
    api_key = os.getenv("OPENAI_API_KEY", "").strip()
    if not api_key:
        print("error: OPENAI_API_KEY is not set.", file=sys.stderr)
        return 2
    email = args.email.strip()
    if not email:
        print("error: DAVVAG_EMAIL or --email is required.", file=sys.stderr)
        return 2
    password = os.getenv("DAVVAG_PASSWORD", "")
    if not password and sys.stdin.isatty():
        password = getpass.getpass("DAVVAG password: ")
    if not password:
        print("error: DAVVAG_PASSWORD is required for unattended runs.", file=sys.stderr)
        return 2

    run_mode = (
        "research"
        if args.research_only
        else "draft"
        if args.draft_only
        else "submit"
    )

    client = DavvagClient(
        args.base_url,
        verify_tls=not args.insecure,
    )

    def report(message: str) -> None:
        if not args.quiet:
            print(f"[process] {message}", file=sys.stderr, flush=True)

    try:
        report(f"Using OpenAI model {args.model!r}.")
        if args.url:
            report(f"Received {len(args.url)} seed URL(s) to inspect first.")
        researcher = DestinationResearcher(
            api_key=api_key,
            model=args.model,
            reasoning_effort=args.reasoning_effort,
            country=args.country,
        )
        pipeline = DestinationPipeline(
            davvag=client,
            researcher=researcher,
            email=email,
            password=password,
            location_privacy=args.location_privacy,
            progress=report,
        )
        result = pipeline.run(
            destination_name=args.destination,
            seed_urls=args.url,
            mode=run_mode,
            force_duplicate=args.force_duplicate,
            allow_low_confidence=args.allow_low_confidence,
        )
        output = json.dumps(result.to_dict(), ensure_ascii=False, indent=2)
        print(output)
        if args.output:
            report(f"Writing sanitized result JSON to {args.output}...")
            args.output.parent.mkdir(parents=True, exist_ok=True)
            args.output.write_text(output + "\n", encoding="utf-8")
            report("Result JSON saved.")
        return 0
    except (DavvagApiError, ResearchError, ValidationError, ValueError) as exc:
        report("Workflow stopped because an error occurred.")
        print(f"error: {exc}", file=sys.stderr)
        return 1
    finally:
        client.close()

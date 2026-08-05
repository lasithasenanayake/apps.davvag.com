import json
import sys
import tempfile
import unittest
from pathlib import Path

import fitz

sys.path.insert(0, str(Path(__file__).parent))

from pdf_lesson_publisher import (
    PublisherError,
    detect_headings,
    detect_manifest,
    flatten_manifest,
    looks_legacy_encoded,
    split_pdf,
)


def synthetic_book(path: Path) -> None:
    document = fitz.open()
    for index in range(8):
        page = document.new_page(width=595, height=842)
        if index in (2, 5):
            number = 1 if index == 2 else 2
            page.insert_text((60, 80), f"{number:02d}", fontsize=48)
            page.insert_text((160, 90), f"Chapter {number}", fontsize=24)
        page.insert_text((60, 220), "This is ordinary body text for the lesson page.", fontsize=11)
    document.save(path)
    document.close()


class PdfPublisherTests(unittest.TestCase):
    def test_detects_sequential_numbered_opening_pages(self):
        with tempfile.TemporaryDirectory() as folder:
            source = Path(folder) / "book.pdf"
            synthetic_book(source)
            document = fitz.open(source)
            headings, confidence, warnings = detect_headings(document)
            self.assertEqual([heading.page for heading in headings], [3, 6])
            self.assertEqual([heading.number for heading in headings], [1, 2])
            self.assertIn(confidence, {"medium", "high"})
            self.assertTrue(any("front matter" in warning for warning in warnings))
            document.close()

    def test_manifest_ranges_and_split_pdf(self):
        with tempfile.TemporaryDirectory() as folder:
            source = Path(folder) / "book.pdf"
            synthetic_book(source)
            manifest = detect_manifest(source)
            lessons = flatten_manifest(manifest, 8)
            self.assertEqual([(row.page_start, row.page_end) for row in lessons], [(3, 5), (6, 8)])
            document = fitz.open(source)
            split = fitz.open(stream=split_pdf(document, 3, 5), filetype="pdf")
            self.assertEqual(split.page_count, 3)
            split.close()
            document.close()

    def test_manifest_rejects_overlapping_ranges(self):
        manifest = {
            "chapters": [
                {"number": 1, "title": "One", "lessons": [{"order": 1, "title": "One", "page_start": 1, "page_end": 3}]},
                {"number": 2, "title": "Two", "lessons": [{"order": 2, "title": "Two", "page_start": 3, "page_end": 4}]},
            ]
        }
        with self.assertRaises(PublisherError):
            flatten_manifest(manifest, 4)

    def test_legacy_sinhala_encoding_is_flagged(self):
        self.assertTrue(looks_legacy_encoded("iudchg kj wre;la"))
        self.assertFalse(looks_legacy_encoded("Introduction to geometry"))
        self.assertFalse(looks_legacy_encoded("ජීව පටක"))

    def test_real_sample_matches_printed_contents_when_available(self):
        sample = Path(__file__).parents[1] / "tests" / "samples" / "Christianity G10 Sinhala new.pdf"
        if not sample.is_file():
            self.skipTest("sample PDF is unavailable")
        manifest = detect_manifest(sample)
        lessons = flatten_manifest(manifest, int(manifest["page_count"]))
        self.assertEqual(len(lessons), 27)
        self.assertEqual((lessons[0].page_start, lessons[0].page_end), (11, 13))
        self.assertEqual((lessons[-1].page_start, lessons[-1].page_end), (137, 142))


if __name__ == "__main__":
    unittest.main()

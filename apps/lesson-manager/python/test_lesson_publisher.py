import json
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

import lesson_publisher as publisher


class SourceFixture:
    def __init__(self, root: Path):
        self.root = root
        (root / "media").mkdir(parents=True)
        (root / "media" / "picture.png").write_bytes(b"fixture-image")
        (root / "conversion-report.json").write_text(
            json.dumps(
                {
                    "complete": True,
                    "errors": [],
                    "warnings": [],
                    "unresolved": [],
                }
            ),
            encoding="utf-8",
        )
        (root / "image-manifest.json").write_text(
            json.dumps({"failures": []}), encoding="utf-8"
        )
        (root / "000-front-matter.md").write_text(
            "# Front matter\n\nBook information.", encoding="utf-8"
        )
        (root / "index.md").write_text("# Index", encoding="utf-8")
        chapter = root / "001-chapter"
        chapter.mkdir()
        (chapter / "000-chapter-introduction.md").write_text(
            "# Chapter One\n\nChapter introduction.", encoding="utf-8"
        )
        (chapter / "001-first.md").write_text(
            "# First Lesson\n\nA useful first paragraph.\n\n"
            "![Picture](../media/picture.png)\n\n"
            "##### Small heading\n\n"
            "| Name | Value |\n| --- | --- |\n| One | Two |\n",
            encoding="utf-8",
        )
        (chapter / "002-second.md").write_text(
            "# Second Lesson\n\nSecond description.", encoding="utf-8"
        )


class SubjectResolutionTests(unittest.TestCase):
    def test_resolves_code_case_insensitively(self):
        bootstrap = {
            "subjects": [
                {"id": 7, "code": "KATHOLIKA-G11", "course_id": 3}
            ]
        }
        subject = publisher.resolve_subject(bootstrap, "katholika-g11")
        self.assertEqual(subject["id"], 7)

    def test_rejects_duplicate_subject_codes(self):
        bootstrap = {
            "subjects": [
                {"id": 7, "code": "REL", "course_id": 3},
                {"id": 8, "code": "rel", "course_id": 4},
            ]
        }
        with self.assertRaisesRegex(publisher.PublisherError, "ambiguous"):
            publisher.resolve_subject(bootstrap, "REL")


class PlanAndRenderingTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.root = Path(self.temporary.name)
        SourceFixture(self.root)

    def tearDown(self):
        self.temporary.cleanup()

    def test_plan_maps_real_lessons_and_chapter_intro(self):
        plan = publisher.build_import_plan(self.root, include_front_matter=False)
        self.assertEqual(len(plan.lessons), 2)
        self.assertEqual(plan.content_count, 3)
        self.assertEqual(len(plan.media_files), 1)
        self.assertEqual(sum(plan.image_references.values()), 1)
        self.assertEqual(
            [content.kind for content in plan.lessons[0].contents],
            ["chapter_introduction", "lesson"],
        )
        self.assertEqual(
            [content.kind for content in plan.lessons[1].contents], ["lesson"]
        )

    def test_front_matter_is_optional_first_content(self):
        plan = publisher.build_import_plan(self.root, include_front_matter=True)
        self.assertEqual(plan.content_count, 4)
        first = plan.lessons[0].contents[0]
        self.assertEqual(first.kind, "front_matter")
        self.assertFalse(first.required)

    def test_render_rewrites_images_headings_and_tables(self):
        plan = publisher.build_import_plan(self.root, include_front_matter=False)
        lesson_path = plan.lessons[0].source_path
        image_path = (self.root / "media" / "picture.png").resolve()
        title, body = publisher.render_content_html(
            lesson_path,
            self.root,
            {
                image_path: (
                    "components/dock/soss-uploader/service/get/"
                    "lesson_content_image/lm-picture.png"
                )
            },
        )
        self.assertEqual(title, "First Lesson")
        self.assertNotIn("<h1", body)
        self.assertNotIn("<h5", body)
        self.assertIn("<h4>Small heading</h4>", body)
        self.assertNotIn("<table", body)
        self.assertIn("<strong>Name: </strong>", body)
        self.assertIn("lesson_content_image/lm-picture.png", body)
        self.assertNotIn("../media", body)


class StateAndOrderingTests(unittest.TestCase):
    def test_state_round_trip_and_identity_guard(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            path = root / "state.json"
            state = publisher.StateStore(path)
            state.initialize(
                "https://school.example",
                root,
                "REL",
                9,
                4,
                False,
            )
            state.data["lessons"]["001.md"] = {"lesson_id": 42}
            state.save()

            loaded = publisher.StateStore(path)
            self.assertEqual(loaded.data["lessons"]["001.md"]["lesson_id"], 42)
            with self.assertRaisesRegex(publisher.PublisherError, "subject_id"):
                loaded.validate_identity(
                    "https://school.example", root, "REL", 10, False
                )

    def test_import_appends_after_unrelated_existing_lessons(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            SourceFixture(root)
            plan = publisher.build_import_plan(root, include_front_matter=False)
            state = publisher.StateStore(root / "state.json")
            existing = [
                {"id": 88, "title": "Another Course Lesson", "lesson_order": 6}
            ]
            self.assertEqual(
                publisher.choose_order_start(plan, existing, state), 7
            )


class ArgumentTests(unittest.TestCase):
    def test_accepts_positional_subject_code(self):
        args = publisher.parse_args(["REL-G11"])
        self.assertEqual(args.subject_code, "REL-G11")
        self.assertFalse(args.apply)

    def test_accepts_named_subject_code(self):
        args = publisher.parse_args(["--subject-code", "REL-G11"])
        self.assertEqual(args.subject_code, "REL-G11")

    def test_publish_requires_apply(self):
        with self.assertRaises(SystemExit):
            publisher.parse_args(["REL-G11", "--publish"])

    def test_console_json_is_safe_on_windows_code_pages(self):
        value = {"title": "කතෝලික පාඩම"}
        rendered = publisher.console_json(value)
        rendered.encode("cp1252")
        self.assertEqual(json.loads(rendered), value)


class EnvironmentFileTests(unittest.TestCase):
    def test_loads_only_login_keys_without_overriding_environment(self):
        with tempfile.TemporaryDirectory() as temporary:
            path = Path(temporary) / ".env"
            path.write_text(
                "OPENAI_API_KEY=must-not-load\n"
                "DAVVAG_BASE_URL=https://school.example\n"
                "DAVVAG_EMAIL=file@example.com\n"
                "DAVVAG_PASSWORD='value with # and ='\n",
                encoding="utf-8",
            )
            environment = {"DAVVAG_EMAIL": "override@example.com"}
            loaded = publisher.load_login_config(path, environment)
            self.assertEqual(environment["DAVVAG_EMAIL"], "override@example.com")
            self.assertEqual(environment["DAVVAG_BASE_URL"], "https://school.example")
            self.assertEqual(environment["DAVVAG_PASSWORD"], "value with # and =")
            self.assertNotIn("OPENAI_API_KEY", environment)
            self.assertEqual(loaded, {"DAVVAG_BASE_URL", "DAVVAG_PASSWORD"})

    def test_custom_env_file_argument(self):
        path = publisher.env_file_from_args(["REL", "--env-file", "login.env"])
        self.assertEqual(path, Path("login.env"))


class LoginTests(unittest.TestCase):
    class FakeResponse:
        ok = True

        def __init__(self, envelope):
            self.envelope = envelope

        def json(self):
            return self.envelope

    class FakeSession:
        def __init__(self, envelope):
            self.verify = True
            self.headers = {}
            self.cookies = self
            self.envelope = envelope
            self.request = None

        def set(self, *args, **kwargs):
            pass

        def get(self, url, **kwargs):
            self.request = (url, kwargs)
            return LoginTests.FakeResponse(self.envelope)

    def test_uses_public_userapp_login_with_domain(self):
        session = self.FakeSession(
            {"success": True, "result": {"token": "secret", "profile": {"id": 1}}}
        )
        environment = {
            "DAVVAG_EMAIL": "teacher@example.com",
            "DAVVAG_PASSWORD": "do-not-log-this",
        }
        with patch.dict(publisher.os.environ, environment, clear=True), patch.object(
            publisher.requests, "Session", return_value=session
        ):
            result = publisher.build_session(
                "https://school.example", allow_insecure_http=False, insecure_tls=False
            )

        self.assertIs(result, session)
        url, kwargs = session.request
        self.assertEqual(
            url,
            "https://school.example/components/userapp/login-handler/service/login",
        )
        self.assertEqual(kwargs["params"]["domain"], "school.example")
        self.assertEqual(kwargs["params"]["email"], "teacher@example.com")

    def test_reports_framework_message_without_exposing_password(self):
        session = self.FakeSession(
            {"success": False, "result": {"message": "Login rejected"}}
        )
        environment = {
            "DAVVAG_EMAIL": "teacher@example.com",
            "DAVVAG_PASSWORD": "do-not-log-this",
        }
        with patch.dict(publisher.os.environ, environment, clear=True), patch.object(
            publisher.requests, "Session", return_value=session
        ):
            with self.assertRaisesRegex(publisher.ApiError, "Login rejected") as raised:
                publisher.build_session(
                    "https://school.example",
                    allow_insecure_http=False,
                    insecure_tls=False,
                )
        self.assertNotIn("do-not-log-this", str(raised.exception))


if __name__ == "__main__":
    unittest.main()

import json
import unittest

import requests

from destination_scraper.davvag import DavvagApiError, DavvagClient
from destination_scraper.models import DestinationResearch, ValidationError
from destination_scraper.pipeline import DestinationPipeline
from destination_scraper.researcher import (
    RESEARCH_INSTRUCTIONS,
    DestinationResearcher,
)


SOURCE_ONE = "https://tourism.example.test/place"
SOURCE_TWO = "https://parks.example.test/place"


def research_data(**overrides):
    value = {
        "name": "Example Falls",
        "short_summary": "A documented waterfall reached from Example Town.",
        "description_markdown": "## About\n\nA documented destination.",
        "primary_language": "en",
        "tags": ["waterfall", "nature"],
        "category_slugs": ["waterfall"],
        "amenity_slugs": ["parking"],
        "latitude": 7.1,
        "longitude": 80.2,
        "coordinate_accuracy": 25,
        "province": "Central Province",
        "district": "Example District",
        "nearest_town": "Example Town",
        "village": "",
        "location_description": "Near Example Town.",
        "access_road_description": "Use the signed access road.",
        "public_transport_instructions": "",
        "parking_information": "Parking is documented at the entrance.",
        "distance_from_town_km": 4,
        "road_condition": "Check current conditions.",
        "requires_4wd": False,
        "walking_distance_km": 1,
        "stay_subtype": "",
        "price_range": "",
        "responsible_travel_markdown": "Carry out waste.",
        "safety_warnings": "Verify weather and access before travel.",
        "confidence": "high",
        "ready_to_submit": True,
        "research_warnings": [],
        "sources": [
            {"url": SOURCE_ONE, "title": "Tourism page", "claims": ["Location"]},
            {"url": SOURCE_TWO, "title": "Park page", "claims": ["Access"]},
        ],
    }
    value.update(overrides)
    return value


class FakeResponse:
    def __init__(self, payload, status_code=200):
        self.payload = payload
        self.status_code = status_code

    def json(self):
        return self.payload


class FakeSession:
    def __init__(self, responses=None, error=None):
        self.responses = list(responses or [])
        self.error = error
        self.calls = []
        self.closed = False

    def request(self, method, url, **kwargs):
        self.calls.append((method, url, kwargs))
        if self.error:
            raise self.error
        return self.responses.pop(0)

    def close(self):
        self.closed = True


class FakeOpenAIResponse:
    id = "resp_test"

    def __init__(self, data):
        self.output_text = json.dumps(data)

    def model_dump(self, mode="json"):
        return {
            "output": [
                {
                    "type": "web_search_call",
                    "action": {
                        "sources": [
                            {"url": SOURCE_ONE, "title": "Tourism page"},
                            {"url": SOURCE_TWO, "title": "Park page"},
                        ]
                    },
                }
            ]
        }


class FakeResponses:
    def __init__(self, data):
        self.data = data
        self.kwargs = None

    def create(self, **kwargs):
        self.kwargs = kwargs
        return FakeOpenAIResponse(self.data)


class FakeOpenAI:
    def __init__(self, data):
        self.responses = FakeResponses(data)


class FakeDavvag:
    def __init__(self):
        self.saved_payload = None
        self.submitted_payload = None

    def login(self, email, password):
        return {"token": "test-token"}

    def capabilities(self):
        return {"authenticated": True, "profileId": 10}

    def categories(self):
        return [{"id": 4, "slug": "waterfall", "name": "Waterfall"}]

    def amenities(self):
        return [{"id": 8, "slug": "parking", "name": "Parking"}]

    def likely_duplicates(self, name):
        return []

    def save_draft(self, payload):
        self.saved_payload = dict(payload)
        return {"id": 99, "status": "Draft"}

    def submit_destination(self, payload):
        self.submitted_payload = dict(payload)
        return {"id": 99, "name": payload["name"], "status": "Pending Review"}


class FakeResearcher:
    def research(self, **kwargs):
        return DestinationResearch.from_dict(research_data())


class ModelTests(unittest.TestCase):
    def test_valid_research_has_no_blockers(self):
        research = DestinationResearch.from_dict(research_data())
        self.assertEqual([], research.blockers())

    def test_coordinates_must_be_valid(self):
        with self.assertRaises(ValidationError):
            DestinationResearch.from_dict(research_data(latitude=91))

    def test_two_sources_are_required(self):
        research = DestinationResearch.from_dict(
            research_data(sources=[research_data()["sources"][0]])
        )
        self.assertIn("At least two source URLs are required.", research.blockers())

    def test_summary_is_limited_to_editorial_length(self):
        research = DestinationResearch.from_dict(
            research_data(short_summary="x" * 400)
        )
        self.assertEqual(255, len(research.short_summary))

    def test_editorial_style_is_part_of_research_prompt(self):
        self.assertIn("Editorial writing style:", RESEARCH_INSTRUCTIONS)
        self.assertIn("Visitor Overview", RESEARCH_INSTRUCTIONS)
        self.assertIn("explicitly branded \"Walk for Christ\"", RESEARCH_INSTRUCTIONS)


class DavvagClientTests(unittest.TestCase):
    def test_login_uses_cookie_session_and_expected_route(self):
        session = FakeSession(
            [FakeResponse({"success": True, "result": {"token": "abc"}})]
        )
        client = DavvagClient("http://localhost/davvag-core", session=session)
        result = client.login("person@example.test", "secret")
        self.assertEqual("abc", result["token"])
        method, url, kwargs = session.calls[0]
        self.assertEqual("GET", method)
        self.assertTrue(url.endswith("/components/userapp/login-handler/service/login"))
        self.assertEqual("localhost", kwargs["params"]["domain"])

    def test_login_transport_error_does_not_expose_password(self):
        session = FakeSession(
            error=requests.ConnectionError("url contained password=super-secret")
        )
        client = DavvagClient("http://localhost", session=session)
        with self.assertRaises(DavvagApiError) as caught:
            client.login("person@example.test", "super-secret")
        self.assertNotIn("super-secret", str(caught.exception))


class ResearcherTests(unittest.TestCase):
    def test_web_search_and_structured_output_are_enabled(self):
        fake = FakeOpenAI(research_data())
        researcher = DestinationResearcher(api_key="", client=fake)
        result = researcher.research(
            "Example Falls",
            categories=[{"id": 4, "slug": "waterfall", "name": "Waterfall"}],
            amenities=[{"id": 8, "slug": "parking", "name": "Parking"}],
        )
        self.assertEqual(2, len(result.sources))
        self.assertEqual("resp_test", result.response_id)
        request = fake.responses.kwargs
        self.assertEqual("web_search", request["tools"][0]["type"])
        self.assertEqual(
            "json_schema", request["text"]["format"]["type"]
        )
        self.assertFalse(request["store"])


class PipelineTests(unittest.TestCase):
    def test_submit_mode_saves_draft_then_submits_same_id(self):
        davvag = FakeDavvag()
        progress = []
        pipeline = DestinationPipeline(
            davvag=davvag,
            researcher=FakeResearcher(),
            email="person@example.test",
            password="secret",
            progress=progress.append,
        )
        result = pipeline.run("Example Falls")
        self.assertEqual("Draft", result.draft["status"])
        self.assertEqual("Pending Review", result.submission["status"])
        self.assertIsNone(davvag.saved_payload["id"])
        self.assertEqual(99, davvag.submitted_payload["id"])
        self.assertEqual([4], davvag.submitted_payload["category_ids"])
        self.assertEqual([8], davvag.submitted_payload["amenity_ids"])
        self.assertIn("## Research sources", davvag.submitted_payload["description_markdown"])
        self.assertTrue(any("Logging in to DAVVAG" in item for item in progress))
        self.assertTrue(any("search the web" in item for item in progress))
        self.assertTrue(any(SOURCE_ONE in item for item in progress))
        self.assertTrue(any(SOURCE_TWO in item for item in progress))
        self.assertTrue(any("Draft saved" in item for item in progress))
        self.assertTrue(any("Pending Review" in item for item in progress))
        self.assertFalse(any("secret" in item for item in progress))


if __name__ == "__main__":
    unittest.main()

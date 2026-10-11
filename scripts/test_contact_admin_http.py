"""Negative HTTP checks against local DDEV; never sends an authorized email."""
import json
from urllib.error import HTTPError
from urllib.parse import urlencode
from urllib.request import Request, urlopen

BASE = "http://127.0.0.1:80"
MAILPIT = "http://127.0.0.1:8025"


def request(path, data=None):
    try:
        with urlopen(Request(BASE + path, data=data), timeout=20) as response:
            return response.status, dict(response.headers), json.load(response)
    except HTTPError as error:
        return error.code, dict(error.headers), json.load(error)


def messages():
    with urlopen(MAILPIT + "/api/v1/messages", timeout=10) as response:
        return json.load(response)["total"]


before = messages()
endpoint = "/js/public_html/cgi-bin/submit.php"
status, _, _ = request(endpoint)
assert status == 405
fields = dict(fullName="GRASP Security Negative Test", email="test@example.com", message="Must not send.")
for extra in ({}, {"g-recaptcha-response[]": "array"}, {"g-recaptcha-response": "x" * 16385}):
    status, _, _ = request(endpoint, urlencode(fields | extra).encode())
    assert status == 422, status
status, _, _ = request(endpoint, urlencode(fields | {"email[]": "array", "email": ""}).encode())
assert status == 422
status, headers, public = request("/api/contact_admin_config.php")
assert status in (200, 503)
assert headers.get("Cache-Control") == "no-store"
if status == 200:
    assert set(public) == {"siteKey"}
else:
    assert set(public) == {"message"}
status, _, _ = request("/api/contact_admin_config.php", b"")
assert status == 405
assert messages() == before, "Rejected submissions must not create mail"
print("8 negative/config HTTP checks passed; Mailpit message count unchanged.")

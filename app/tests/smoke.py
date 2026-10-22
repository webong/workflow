"""Exercise the built workflowd image over HTTP with real PostgreSQL state."""

import json
import time
from urllib.error import HTTPError, URLError
from urllib.request import Request, urlopen
from uuid import uuid4


base = "http://workflowd:8080"
for attempt in range(60):
    try:
        with urlopen(f"{base}/healthz", timeout=2) as response:
            assert response.status == 204
        break
    except (URLError, TimeoutError):
        if attempt == 59:
            raise
        time.sleep(1)


def rpc(method, params, token="ci-only-workflow-token"):
    request = Request(f"{base}/rpc", data=json.dumps({
        "jsonrpc": "2.0", "id": 1, "method": method, "params": params,
    }).encode(), headers={"Content-Type": "application/json", "Authorization": f"Bearer {token}"})
    with urlopen(request, timeout=10) as response:
        result = json.load(response)
    assert result["jsonrpc"] == "2.0" and result["id"] == 1, result
    assert "error" not in result, result
    return result["result"]["state"]


identity = {"flow_key": "demo_approval", "run_id": str(uuid4()), "subject": {"type": "order", "id": "ci-order"}}
try:
    rpc("flow.start", identity, token="wrong-token")
    raise AssertionError("Unauthorized request was accepted")
except HTTPError as error:
    assert error.code == 401

assert rpc("flow.get", identity) is None
started = rpc("flow.start", identity)
assert started["status"] == "running" and started["steps"]["approve"]["attempts"] == 1
assert rpc("flow.get", identity) == started
assert rpc("flow.start", identity) == started
assert rpc("flow.resume", identity)["steps"]["approve"]["attempts"] == 1

completion = {
    **identity, "step_id": "approve", "attempt": 1, "idempotency_key": "ci-approved",
    "result": {"status": "completed", "message": "Approved by CI"},
}
finished = rpc("flow.complete", completion)
assert finished["status"] == "completed"
assert rpc("flow.complete", completion) == finished
assert rpc("flow.get", identity) == finished
assert rpc("flow.resume", identity) == finished

other = {**identity, "run_id": str(uuid4())}
rpc("flow.start", other)
assert rpc("flow.cancel", other)["status"] == "cancelled"
assert rpc("flow.get", identity) == finished
print("PASS: workflowd HTTP/auth/start/get/deferred/resume/completion/deduplication/cancellation with PostgreSQL")

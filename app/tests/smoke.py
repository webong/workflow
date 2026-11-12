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

# Queue authority: a separate CLI worker, not the HTTP request, advances these runs.
def waiting(identity):
    deadline = time.monotonic() + 35
    while time.monotonic() < deadline:
        state = rpc("flow.get", identity)
        assert state["metadata"]["execution_driver"] == "queue", state
        assert state["run"]["id"] == identity["run_id"], state
        assert "_queue_storage_id" not in state["metadata"], state
        assert "_rpc_authority" not in state["metadata"], state
        metadata = state["steps"]["approve"]["metadata"]
        if isinstance(metadata, dict) and metadata.get("deferred"):
            return state
        time.sleep(0.2)
    raise AssertionError("Queue worker did not reach deferred approval")


queued = {**identity, "run_id": str(uuid4())}
other_subject = {**queued, "subject": {"type": "order", "id": "ci-other-order"}}
first = rpc("flow.start", {**queued, "driver": "queue"})
second = rpc("flow.start", {**other_subject, "driver": "queue"})
assert first["metadata"]["execution_id"] != second["metadata"]["execution_id"]
waiting(queued)
waiting(other_subject)
assert rpc("flow.start", queued)["steps"]["approve"]["attempts"] == 1
assert rpc("flow.resume", queued)["steps"]["approve"]["attempts"] == 1
complete_queue = {
    **queued, "step_id": "approve", "attempt": 1, "idempotency_key": "queued-approved",
    "result": {"status": "completed", "output": {"approved": True}},
}
finished_queue = rpc("flow.complete", complete_queue)
assert finished_queue["status"] == "completed", finished_queue
assert rpc("flow.complete", complete_queue) == finished_queue
assert rpc("flow.get", queued) == finished_queue
assert rpc("flow.get", other_subject)["steps"]["approve"]["metadata"]["deferred"]
assert rpc("flow.cancel", other_subject)["status"] == "cancelled"
assert rpc("flow.cancel", other_subject)["status"] == "cancelled"
assert rpc("flow.get", queued) == finished_queue
print("PASS: queue-backed RPC/CLI worker authority, scoped IDs, deferred callback/deduplication and cancellation")

"""Compare the elephc artifacts to the PHP 8.3 oracle, then probe the C ABI.

The shared oracle is generated once by mod/native/tests/export-cases.php, so the
TypePHP and elephc modules are checked against byte-identical expectations.
"""

import copy
import ctypes
import json
import os
from pathlib import Path
import subprocess
import sys

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "examples"))
from python_client import WorkflowNative

EXECUTABLE = os.environ.get("WORKFLOW_NATIVE_BIN", "/build/workflow-native")
ORACLE = Path(os.environ.get("WORKFLOW_ORACLE", "/build/php83-cases.json"))


def normalize(value):
    """Ignore generated clocks; they come from each execution's own timestamp."""
    result = copy.deepcopy(value)
    steps = result.get("state", {}).get("steps", {})
    for step in steps.values() if isinstance(steps, dict) else steps:
        for key in ("updated_at", "next_retry_at"):
            if step.get(key) is not None:
                step[key] = "<clock>"
    return result


oracle = json.loads(ORACLE.read_text())
assert oracle["php"].startswith("8.3."), "The oracle must come from PHP 8.3"
workflow = WorkflowNative()
results = {}
try:
    for case in oracle["cases"]:
        request = case["request"].encode("utf-8")
        executable = subprocess.run([EXECUTABLE], input=request, capture_output=True, check=True)
        binary = json.loads(executable.stdout)
        library = workflow.raw(request)
        for name, actual in (("executable", binary), ("shared library", library)):
            assert normalize(actual) == normalize(case["response"]), (case["name"], name, actual, case["response"])
        results[case["name"]] = library

    assert results["advance"]["work"] == [{"flow_key": "approval", "run_id": "run-1", "step_id": "approve", "attempt": 1}]
    assert results["deferred-is-not-reissued"]["work"] == []
    assert results["dependent-work"]["work"][0]["step_id"] == "notify"
    assert results["finish"]["state"]["status"] == "completed"
    assert results["retry-attempt"]["work"][0]["attempt"] == 2
    assert results["exhausted-no-retry"]["work"] == []
    assert results["backoff-no-early-work"]["work"] == []
    for name in ("conflicting-event", "wrong-run_id", "wrong-flow_key", "wrong-step_id", "wrong-attempt", "cycle", "stale-attempt", "pinned-definition"):
        assert results[name]["ok"] is False, name

    # Recoverable boundary: bad pointers are refused and do not poison the runtime.
    output = ctypes.c_void_p(123)
    size = ctypes.c_size_t(123)
    call = workflow.library.workflow_native_call
    assert call(None, 5, ctypes.byref(output), ctypes.byref(size)) != 0
    assert output.value is None and size.value == 0
    assert call(b"{}", 2, None, ctypes.byref(size)) != 0
    assert call(b"{}", 2, ctypes.byref(output), None) != 0

    # A NULL pointer with zero length is an empty request, not an ABI error, and
    # an oversized request is rejected by the protocol rather than the boundary.
    assert workflow.raw(b"")["ok"] is False
    assert workflow.raw(b"x" * 1048577)["error"]["code"] == "invalid_request"

    for _ in range(100):
        assert workflow.raw(b"{")["ok"] is False
        assert workflow.call("start", run_id="repeat", definition={"key": "empty"})["state"]["status"] == "completed"
finally:
    workflow.close()

print(
    f"PASS: {len(oracle['cases'])} PHP {oracle['php']} parity cases across executable "
    "and shared library; C ABI argument checks"
)

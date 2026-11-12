"""Compare the Manticore artifact to the PHP 8.3 oracle.

The shared oracle is generated once by mod/native/tests/export_cases.php, so the
Manticore module is checked against byte-identical expectations as TypePHP and
elephc.
"""

import copy
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
        for name, actual in (("executable", binary), ("subprocess client", library)):
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

    # Every call must produce a well-formed response, even for malformed input.
    for _ in range(100):
        assert workflow.raw(b"{")["ok"] is False
        assert workflow.call("start", run_id="repeat", definition={"key": "empty"})["state"]["status"] == "completed"
finally:
    workflow.close()

print(f"PASS: {len(oracle['cases'])} PHP {oracle['php']} parity cases across executable and subprocess client")

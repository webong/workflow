"""Compare compiled artifacts to the PHP 8.3 oracle, then probe the C ABI."""

import ctypes
import copy
import json
from pathlib import Path
import subprocess
import sys
import threading
import time

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "examples"))
from python_client import WorkflowNative


def normalize(value):
    result = copy.deepcopy(value)
    steps = result.get("state", {}).get("steps", {})
    for step in steps.values() if isinstance(steps, dict) else steps:
        for key in ("updated_at", "next_retry_at"):
            if step.get(key) is not None:
                step[key] = "<clock>"
    return result


oracle = json.loads(Path("/build/php83-cases.json").read_text())
assert oracle["php"].startswith("8.3."), "The oracle must come from PHP 8.3"
workflow = WorkflowNative()
results = {}
try:
    for case in oracle["cases"]:
        request = case["request"].encode("utf-8")
        executable = subprocess.run(["/build/workflow-native"], input=request, capture_output=True, check=True)
        binary = json.loads(executable.stdout)
        library = workflow.raw(request)
        for name, actual in (("executable", binary), ("shared library", library)):
            assert normalize(actual) == normalize(case["response"]), (case["name"], name, actual, case["response"])
            if case["name"] == "backoff-deadline":
                assert time.time() + 3500 < actual["state"]["steps"]["send"]["next_retry_at"] < time.time() + 3700
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

    # An invalid call must not poison a long-lived runtime or leak output buffers.
    for _ in range(100):
        assert workflow.raw(b"{")["ok"] is False
        assert workflow.call("start", run_id="repeat", definition={"key": "empty"})["state"]["status"] == "completed"

    output = ctypes.c_void_p(123)
    size = ctypes.c_size_t(123)
    call = workflow.library.workflow_native_call
    assert call(None, 0, ctypes.byref(output), ctypes.byref(size)) == 1
    assert output.value is None and size.value == 0
    assert call(b"{}", 1048577, ctypes.byref(output), ctypes.byref(size)) == 1
    assert call(b"{}", 2, None, ctypes.byref(size)) == 1
    assert workflow.library.workflow_native_init() == 0
    cross_thread = []

    def wrong_thread():
        cross_thread.append(call(b"{}", 2, ctypes.byref(output), ctypes.byref(size)))
        cross_thread.append(workflow.library.workflow_native_shutdown())

    thread = threading.Thread(target=wrong_thread)
    thread.start()
    thread.join()
    assert cross_thread == [3, 3]
finally:
    workflow.close()

assert workflow.library.workflow_native_init() == 2
assert workflow.library.workflow_native_call(b"{}", 2, ctypes.byref(output), ctypes.byref(size)) == 2
print(f"PASS: {len(oracle['cases'])} PHP {oracle['php']} parity cases across executable and shared library; C ABI lifecycle/buffer/thread checks")

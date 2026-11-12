"""Experimental subprocess client for the Manticore-built executable.

Each call spawns a fresh process: write one JSON request to stdin, read one
JSON response from stdout. No shared library, no thread affinity, no C ABI.
"""

import json
import os
import subprocess


DEFAULT_BINARY = os.environ.get("WORKFLOW_NATIVE_BIN", "/build/workflow-native")


class WorkflowNative:
    def __init__(self, binary=DEFAULT_BINARY):
        self.binary = binary

    def raw(self, request):
        if isinstance(request, str):
            request = request.encode("utf-8")
        try:
            completed = subprocess.run(
                [self.binary],
                input=request,
                capture_output=True,
                timeout=10,
                check=True,
            )
        except subprocess.CalledProcessError as error:
            raise RuntimeError(f"WorkFlow native process failed: {error}") from error
        except FileNotFoundError as error:
            raise RuntimeError(f"WorkFlow native binary not found: {self.binary}") from error
        try:
            return json.loads(completed.stdout)
        except json.JSONDecodeError as error:
            raise RuntimeError(f"Invalid JSON from WorkFlow native: {completed.stdout!r}") from error

    def call(self, operation, **params):
        return self.raw(json.dumps({"protocol": 1, "operation": operation, **params}).encode("utf-8"))

    def close(self):
        # No persistent runtime; nothing to shut down.
        pass


if __name__ == "__main__":
    workflow = WorkflowNative()
    try:
        started = workflow.call("start", run_id="approval-1", definition={
            "key": "order-approval", "steps": [{"id": "approve", "label": "Approve order"}],
        })
        if not started["ok"]:
            raise RuntimeError(started["error"]["message"])
        waiting = workflow.call("advance", state=started["state"])
        if not waiting["ok"]:
            raise RuntimeError(waiting["error"]["message"])
        # Save waiting["state"] and waiting["work"] atomically. This
        # in-memory example simulates the host's approval operation.
        finished = workflow.call("complete", state=waiting["state"], completion={
            **waiting["work"][0], "idempotency_key": "approval-event-1",
            "result": {"status": "completed", "message": "Approved from Python"},
        })
        if not finished["ok"]:
            raise RuntimeError(finished["error"]["message"])
        print(json.dumps(finished, indent=2))
    finally:
        workflow.close()

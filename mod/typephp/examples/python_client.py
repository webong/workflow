"""Experimental ctypes client. Create, call and close on one OS thread."""

import ctypes
import json


class WorkflowNative:
    def __init__(self, path="/build/libworkflow_native.so"):
        self.library = ctypes.CDLL(path)
        self.library.workflow_native_abi_version.restype = ctypes.c_uint
        self.library.workflow_native_init.restype = ctypes.c_int
        self.library.workflow_native_shutdown.restype = ctypes.c_int
        self.library.workflow_native_call.argtypes = [
            ctypes.c_char_p, ctypes.c_size_t,
            ctypes.POINTER(ctypes.c_void_p), ctypes.POINTER(ctypes.c_size_t),
        ]
        self.library.workflow_native_call.restype = ctypes.c_int
        self.library.workflow_native_free.argtypes = [ctypes.c_void_p]
        self.library.workflow_native_free.restype = None
        if self.library.workflow_native_abi_version() != 1:
            raise RuntimeError("Unsupported WorkFlow native ABI")
        if self.library.workflow_native_init() != 0:
            raise RuntimeError("Cannot initialize WorkFlow native runtime")

    def raw(self, request):
        output = ctypes.c_void_p()
        size = ctypes.c_size_t()
        status = self.library.workflow_native_call(request, len(request), ctypes.byref(output), ctypes.byref(size))
        if status != 0:
            raise RuntimeError(f"WorkFlow native call failed: {status}")
        try:
            return json.loads(ctypes.string_at(output, size.value))
        finally:
            self.library.workflow_native_free(output)

    def call(self, operation, **params):
        return self.raw(json.dumps({"protocol": 1, "operation": operation, **params}).encode("utf-8"))

    def close(self):
        if self.library.workflow_native_shutdown() != 0:
            raise RuntimeError("Cannot shut down WorkFlow native runtime")


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

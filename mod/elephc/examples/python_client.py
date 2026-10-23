"""Experimental ctypes client for the elephc-built shared library.

Load, initialize, call, and shut down on one OS thread. The elephc ABI is
single-threaded and exposes no per-host runtime context, so do not create a
client per web request or share one with a thread pool.
"""

import ctypes
import json
import os


# Only the success status is needed by value; every other status is treated as a
# non-zero failure and reported through elephc_last_error(). The generated
# include/libworkflow_native.h (ELEPHC_ABI_VERSION) is authoritative.
ELEPHC_STATUS_OK = 0
ELEPHC_ABI_VERSION = int(os.environ.get("ELEPHC_ABI_VERSION", "3"))

DEFAULT_LIBRARY = os.environ.get("WORKFLOW_NATIVE_LIB", "/build/libworkflow_native.so")


class WorkflowNative:
    def __init__(self, path=DEFAULT_LIBRARY):
        self.library = ctypes.CDLL(path)
        library = self.library
        library.elephc_abi_version.restype = ctypes.c_uint32
        library.elephc_init.restype = ctypes.c_int32
        library.elephc_shutdown.restype = None
        library.elephc_last_error.restype = ctypes.c_char_p
        library.elephc_free.argtypes = [ctypes.c_void_p]
        library.elephc_free.restype = None
        library.workflow_native_call.argtypes = [
            ctypes.c_char_p, ctypes.c_size_t,
            ctypes.POINTER(ctypes.c_void_p), ctypes.POINTER(ctypes.c_size_t),
        ]
        library.workflow_native_call.restype = ctypes.c_int32

        version = library.elephc_abi_version()
        if version != ELEPHC_ABI_VERSION:
            raise RuntimeError(
                f"Unsupported elephc ABI version {version}; expected {ELEPHC_ABI_VERSION}. "
                "Check ELEPHC_ABI_VERSION in include/libworkflow_native.h."
            )
        status = library.elephc_init()
        if status != ELEPHC_STATUS_OK:
            raise RuntimeError(f"Cannot initialize elephc runtime: {self.diagnostic(status)}")

    def diagnostic(self, status):
        message = self.library.elephc_last_error()
        detail = message.decode("utf-8", "replace") if message else "no diagnostic"
        return f"status {status} ({detail})"

    def raw(self, request):
        output = ctypes.c_void_p()
        size = ctypes.c_size_t()
        status = self.library.workflow_native_call(
            request, len(request), ctypes.byref(output), ctypes.byref(size)
        )
        if status != ELEPHC_STATUS_OK:
            raise RuntimeError(f"WorkFlow native call failed: {self.diagnostic(status)}")
        try:
            return json.loads(ctypes.string_at(output, size.value))
        finally:
            self.library.elephc_free(output)

    def call(self, operation, **params):
        return self.raw(json.dumps({"protocol": 1, "operation": operation, **params}).encode("utf-8"))

    def close(self):
        # elephc_shutdown() returns void; shutdown is terminal for this process.
        self.library.elephc_shutdown()


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

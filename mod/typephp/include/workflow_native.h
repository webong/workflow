#ifndef WORKFLOW_NATIVE_H
#define WORKFLOW_NATIVE_H

#include <stddef.h>

#if defined(_WIN32) && defined(WORKFLOW_NATIVE_BUILD)
#define WORKFLOW_NATIVE_API __declspec(dllexport)
#elif defined(_WIN32)
#define WORKFLOW_NATIVE_API __declspec(dllimport)
#else
#define WORKFLOW_NATIVE_API __attribute__((visibility("default")))
#endif

#ifdef __cplusplus
extern "C" {
#endif

/* Experimental ABI v1. One runtime, one owning OS thread, per process.
 * Do not load alongside another embedded PHP runtime. Shutdown is terminal.
 * All pointers must address valid caller-owned memory for their stated size.
 */
enum workflow_native_status {
    WORKFLOW_NATIVE_OK = 0,
    WORKFLOW_NATIVE_INVALID_ARGUMENT = 1,
    WORKFLOW_NATIVE_INVALID_STATE = 2,
    WORKFLOW_NATIVE_WRONG_THREAD = 3,
    WORKFLOW_NATIVE_RUNTIME_ERROR = 4,
    WORKFLOW_NATIVE_ALLOCATION_ERROR = 5
};

WORKFLOW_NATIVE_API unsigned int workflow_native_abi_version(void);
WORKFLOW_NATIVE_API int workflow_native_init(void);

/* At most 1 MiB of UTF-8 JSON. On success, output is a length-delimited JSON
 * buffer (also NUL-terminated), even for domain errors ("ok": false).
 * On failure, output is NULL and output_size is zero. Free each successful
 * output exactly once using workflow_native_free, never the caller's allocator.
 */
WORKFLOW_NATIVE_API int workflow_native_call(const char *input, size_t input_size, char **output, size_t *output_size);
WORKFLOW_NATIVE_API void workflow_native_free(void *output);
WORKFLOW_NATIVE_API int workflow_native_shutdown(void);

#ifdef __cplusplus
}
#endif

#endif

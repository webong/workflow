/* Experimental C consumer of the elephc-built shared library.
 *
 * Build the library first (sh mod/elephc/build.sh), then:
 *
 *   cc -I mod/elephc/include mod/elephc/examples/c_host.c \
 *      -L mod/elephc/build -lworkflow_native -o /tmp/c_host
 *   DYLD_LIBRARY_PATH=mod/elephc/build /tmp/c_host   # Linux: LD_LIBRARY_PATH
 *
 * Include the compiler-generated header rather than copying the declarations.
 * The ABI is single-threaded: initialize, call, and shut down on one OS thread.
 */
#include "libworkflow_native.h"

#include <stdint.h>
#include <stdio.h>
#include <stddef.h>

static const char *diagnostic(void)
{
    const char *message = elephc_last_error();
    return message != NULL ? message : "no diagnostic";
}

int main(void)
{
    static const char request[] =
        "{\"protocol\":1,\"operation\":\"start\",\"run_id\":\"c-1\","
        "\"definition\":{\"key\":\"approval\",\"steps\":[{\"id\":\"approve\"}]}}";

    if (elephc_init() != ELEPHC_STATUS_OK) {
        fprintf(stderr, "elephc_init failed: %s\n", diagnostic());
        return 1;
    }

    char *output = NULL;
    size_t output_len = 0;
    int32_t status = workflow_native_call(
        request, sizeof(request) - 1, &output, &output_len);

    if (status != ELEPHC_STATUS_OK || output == NULL) {
        fprintf(stderr, "workflow_native_call failed: status %d, %s\n",
                (int)status, diagnostic());
        elephc_shutdown();
        return 2;
    }

    fwrite(output, 1, output_len, stdout);
    fputc('\n', stdout);
    elephc_free(output);
    elephc_shutdown();
    return 0;
}

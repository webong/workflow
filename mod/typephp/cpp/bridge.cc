#define WORKFLOW_NATIVE_BUILD
#include "../include/workflow_native.h"
#include <phpx.h>
#include <typephp_runtime.h>
extern "C" {
#include <sapi/embed/php_embed.h>
}
#include <cstdlib>
#include <cstring>
#include <mutex>
#include <thread>

// TypePHP derives the internal module name from library.yml's output basename.
TYPEPHP_RUNTIME_INIT_FUNCTION(libworkflow_native);
TYPEPHP_RUNTIME_SHUTDOWN_FUNCTION(libworkflow_native);
extern php::Str php_workflow_native_dispatch(php::Str request);

namespace {
enum class RuntimeState { fresh, ready, closed };
RuntimeState runtime_state = RuntimeState::fresh;
std::thread::id owner;
std::mutex runtime_mutex;

int check_owner()
{
    if (runtime_state != RuntimeState::ready) {
        return WORKFLOW_NATIVE_INVALID_STATE;
    }
    return owner == std::this_thread::get_id() ? WORKFLOW_NATIVE_OK : WORKFLOW_NATIVE_WRONG_THREAD;
}
}

extern "C" unsigned int workflow_native_abi_version(void)
{
    return 1;
}

extern "C" int workflow_native_init(void)
{
    try {
        std::lock_guard<std::mutex> lock(runtime_mutex);
        if (runtime_state == RuntimeState::ready) {
            return check_owner();
        }
        if (runtime_state == RuntimeState::closed) {
            return WORKFLOW_NATIVE_INVALID_STATE;
        }
        runtime_state = RuntimeState::closed;
        static char name[] = "workflow_native";
        static char *argv[] = {name, nullptr};
        // The coordination core needs no host ini settings or PHP extensions.
        php_embed_module.php_ini_ignore = 1;
        php_embed_module.php_ini_ignore_cwd = 1;
        if (TYPEPHP_RUNTIME_INIT(libworkflow_native)(1, argv) != 0) {
            return WORKFLOW_NATIVE_RUNTIME_ERROR;
        }
        owner = std::this_thread::get_id();
        runtime_state = RuntimeState::ready;
        return WORKFLOW_NATIVE_OK;
    } catch (...) {
        return WORKFLOW_NATIVE_RUNTIME_ERROR;
    }
}

extern "C" int workflow_native_call(const char *input, size_t input_size, char **output, size_t *output_size)
{
    if (output != nullptr) {
        *output = nullptr;
    }
    if (output_size != nullptr) {
        *output_size = 0;
    }
    if (input == nullptr || output == nullptr || output_size == nullptr || input_size > 1048576) {
        return WORKFLOW_NATIVE_INVALID_ARGUMENT;
    }
    try {
        std::lock_guard<std::mutex> lock(runtime_mutex);
        const int status = check_owner();
        if (status != WORKFLOW_NATIVE_OK) {
            return status;
        }
        try {
            const php::Str result = php_workflow_native_dispatch(php::Str(input, input_size));
            gc_collect_cycles();
            char *buffer = static_cast<char *>(std::malloc(result.length() + 1));
            if (buffer == nullptr) {
                return WORKFLOW_NATIVE_ALLOCATION_ERROR;
            }
            std::memcpy(buffer, result.data(), result.length());
            buffer[result.length()] = '\0';
            *output = buffer;
            *output_size = result.length();
        } catch (zend_object *) {
            zend_clear_exception();
            return WORKFLOW_NATIVE_RUNTIME_ERROR;
        }
        return WORKFLOW_NATIVE_OK;
    } catch (...) {
        return WORKFLOW_NATIVE_RUNTIME_ERROR;
    }
}

extern "C" void workflow_native_free(void *output)
{
    std::free(output);
}

extern "C" int workflow_native_shutdown(void)
{
    try {
        std::lock_guard<std::mutex> lock(runtime_mutex);
        const int status = check_owner();
        if (status != WORKFLOW_NATIVE_OK) {
            return status;
        }
        runtime_state = RuntimeState::closed;
        TYPEPHP_RUNTIME_SHUTDOWN(libworkflow_native)();
        return WORKFLOW_NATIVE_OK;
    } catch (...) {
        return WORKFLOW_NATIVE_RUNTIME_ERROR;
    }
}

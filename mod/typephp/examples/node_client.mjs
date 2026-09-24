/** Experimental executable client. The caller owns persistence and work delivery. */
import { execFile } from 'node:child_process';
import { pathToFileURL } from 'node:url';

export function call(operation, params = {}, binary = '/build/workflow-native') {
    return new Promise((resolve, reject) => {
        const input = JSON.stringify({ ...params, protocol: 1, operation });
        if (Buffer.byteLength(input, 'utf8') > 1048576) {
            reject(new RangeError('WorkFlow request exceeds 1 MiB.'));
            return;
        }

        // A trusted executable path, no shell, and bounded process time/output.
        const child = execFile(binary, [], {
            encoding: 'utf8', timeout: 10000, maxBuffer: 4 * 1024 * 1024,
        }, (error, stdout) => {
            if (error) {
                reject(new Error('WorkFlow native process failed.', { cause: error }));
                return;
            }
            try {
                const response = JSON.parse(stdout);
                if (response.ok !== true) {
                    const failure = new Error(response.error?.message ?? 'Invalid WorkFlow response.');
                    failure.code = response.error?.code ?? 'invalid_response';
                    throw failure;
                }
                resolve(response);
            } catch (error) {
                reject(error);
            }
        });
        child.stdin.on('error', reject);
        child.stdin.end(input);
    });
}

export async function approvalExample() {
    const started = await call('start', {
        run_id: 'approval-1',
        definition: {
            key: 'order-approval',
            steps: [{ id: 'approve', label: 'Approve order' }],
        },
    });
    const waiting = await call('advance', { state: started.state });

    // Save waiting.state and waiting.work atomically before performing work.
    // This in-memory example simulates the host's approval operation.
    return call('complete', {
        state: waiting.state,
        completion: {
            ...waiting.work[0],
            idempotency_key: 'approval-event-1',
            result: { status: 'completed', message: 'Approved from Node' },
        },
    });
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
    try {
        console.log(JSON.stringify(await approvalExample(), null, 2));
    } catch (error) {
        console.error(error.message);
        process.exitCode = 1;
    }
}

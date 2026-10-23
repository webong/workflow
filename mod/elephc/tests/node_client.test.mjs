import assert from 'node:assert/strict';
import { test } from 'node:test';
import { approvalExample, call } from '../examples/node_client.mjs';

const BIN = process.env.WORKFLOW_NATIVE_BIN ?? '/build/workflow-native';

const start = () => call('start', {
    run_id: 'node-test',
    definition: { key: 'approval', steps: [{ id: 'approve' }] },
}, BIN);

test('Node completes an approval through the compiled executable', async () => {
    const result = await approvalExample(BIN);
    assert.equal(result.state.status, 'completed');
    assert.equal(result.state.steps.approve.message, 'Approved from Node');
});

test('persisted state survives fresh processes without reissuing work', async () => {
    const started = await start();
    const waiting = await call('advance', { state: started.state }, BIN);
    assert.equal(waiting.work.length, 1);
    assert.deepEqual((await call('advance', { state: waiting.state }, BIN)).work, []);

    const completion = {
        ...waiting.work[0], idempotency_key: 'node-event',
        result: { status: 'completed' },
    };
    const finished = await call('complete', { state: waiting.state, completion }, BIN);
    assert.deepEqual(await call('complete', { state: finished.state, completion }, BIN), finished);
});

test('domain errors reject even when the executable exits successfully', async () => {
    await assert.rejects(call('start', { run_id: 'missing-definition' }, BIN), { code: 'invalid_request' });
    const waiting = await call('advance', { state: (await start()).state }, BIN);
    await assert.rejects(call('complete', {
        state: waiting.state,
        completion: {
            ...waiting.work[0], attempt: 2, idempotency_key: 'wrong-attempt',
            result: { status: 'completed' },
        },
    }, BIN), { code: 'invalid_request' });
});

test('cancelled state issues no more work', async () => {
    const cancelled = await call('cancel', { state: (await start()).state }, BIN);
    assert.equal(cancelled.state.status, 'cancelled');
    assert.deepEqual((await call('advance', { state: cancelled.state }, BIN)).work, []);
});

test('missing executables reject without an unhandled pipe error', async () => {
    await assert.rejects(call('start', {}, '/build/no-such-workflow-executable'));
});

test('oversized UTF-8 input is rejected before spawning', async () => {
    await assert.rejects(call('start', { run_id: 'é'.repeat(524288) }, BIN), RangeError);
});

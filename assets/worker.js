/*
|--------------------------------------------------------------------------
| Battery Stress Timer - Web Worker
|--------------------------------------------------------------------------
|
| Version: 1.0.0
|
| Local deterministic JavaScript workload.
| Runs inside a Web Worker so the main timer UI stays responsive.
|
| One completed batch = one workload cycle.
|
| Vibe code by Dalibor Klobučarić & my friend ChatGPT
|
|--------------------------------------------------------------------------
*/

let running = false;
let workerId = 0;
let batchSize = 180000;
let seed = 12345;
let localCycles = 0;

self.onmessage = function (event) {
    const data = event.data || {};

    if (data.command === 'init') {
        workerId = Number(data.workerId || 0);
        return;
    }

    if (data.command === 'start') {
        batchSize = Number(data.batchSize || 180000);
        seed = Number(data.seed || 12345) + workerId * 1009;

        if (!running) {
            running = true;
            runLoop();
        }

        return;
    }

    if (data.command === 'stop') {
        running = false;
        return;
    }
};

function runLoop() {
    if (!running) {
        return;
    }

    const result = runDeterministicMathBatch(batchSize, seed + localCycles);

    localCycles++;

    self.postMessage({
        type: 'cycle',
        workerId: workerId,
        localCycles: localCycles,
        result: result
    });

    /*
     * Yield back to the worker event loop.
     * This keeps the worker responsive to stop messages.
     */
    setTimeout(runLoop, 0);
}

function runDeterministicMathBatch(size, inputSeed) {
    /*
     * Deterministic pseudo-random-ish math workload.
     * No Math.random(), so the workload is repeatable.
     *
     * This intentionally mixes:
     * - integer operations
     * - floating point math
     * - sqrt / sin / cos
     *
     * The result is posted back so the browser cannot trivially discard the work.
     */

    let x = inputSeed >>> 0;
    let acc = 0.000001;

    for (let i = 1; i <= size; i++) {
        x = (x * 1664525 + 1013904223) >>> 0;

        const a = (x & 1023) + 1;
        const b = ((x >>> 10) & 1023) + 1;
        const c = i % 360;

        acc += Math.sqrt(a * b + i) * Math.sin(c) * Math.cos(a / b);

        if (acc > 1000000000 || acc < -1000000000) {
            acc = acc % 1000000;
        }
    }

    return Number(acc.toFixed(5));
}

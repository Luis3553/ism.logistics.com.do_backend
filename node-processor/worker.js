import { Worker } from "worker_threads";
import path from "path";
import { fileURLToPath } from "url";

const __dirname = path.dirname(fileURLToPath(import.meta.url));

export async function runWorker({ id, csv, allowedSpeed, minDuration }) {
    const workerPath = path.resolve(__dirname, "workerThread.js");

    return new Promise((resolve, reject) => {
        const worker = new Worker(workerPath, {
            workerData: {
                csv: csv.toString("utf8"),
                allowedSpeed,
                minDuration,
            },
        });

        worker.on("message", (data) => resolve({ id, data }));
        worker.on("error", reject);
        worker.on("exit", (code) => {
            if (code !== 0) reject(new Error(`Worker failed: ${code}`));
        });
    });
}

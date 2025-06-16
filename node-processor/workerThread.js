import { workerData, parentPort } from "worker_threads";
import { detectSpeedBurstsFromCsv } from "./burstDetector.js";

(async () => {
    const { csv, allowedSpeed, minDuration } = workerData;
    const data = await detectSpeedBurstsFromCsv(csv, allowedSpeed, minDuration);
    parentPort.postMessage(data);
})();

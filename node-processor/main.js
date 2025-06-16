import pLimit from "p-limit";
import { fetchAllCsvs } from "./fetchAllCsv.js";
import { runWorker } from "./worker.js";
import minimist from "minimist";
import { logToLaravel } from "./utils/logToLaravel.js";
import { createTableStructureForSpeedupReport } from "./createTableStructureForSpeedupReport.js";
import fetchAddresses from "./utils/geocoder.js";
import updateProgress from "./utils/updateProgress.js";
import { getTrackerGroups, getTrackers } from "./utils/dataHelpers.js";
import { sendResult } from "./sendResult.js";

const limit = pLimit(1);
const argv = minimist(process.argv.slice(2), {
    string: ["hash", "report_id", "ids", "from", "to", "allowed_speed", "min_duration"],
    alias: {},
    default: {},
});

// args
const hash = argv.hash;
const reportId = argv.report_id;
const trackerIds = argv.ids ? argv.ids.split(",") : [];
const fromDate = argv.from;
const toDate = argv.to;
const allowedSpeed = argv.allowed_speed;
const minDuration = argv.min_duration;

(async () => {
    try {
        const start = Date.now();
        const [cvs, trackersInfo, groups] = await Promise.all([fetchAllCsvs(trackerIds, fromDate, toDate, hash), getTrackers(hash), getTrackerGroups(hash)]);

        await updateProgress(reportId, 33, hash);

        const jobs = cvs.map((csv) => limit(() => runWorker({ ...csv, allowedSpeed, minDuration })));
        let outputs = await Promise.all(jobs);
        outputs = outputs.filter((output) => output.data.length > 0);

        await updateProgress(reportId, 66, hash);

        for (const output of outputs) {
            output.data = await fetchAddresses(output.data, hash);
        }

        await updateProgress(reportId, 99, hash);

        let result = createTableStructureForSpeedupReport({ outputs, allowedSpeed, minDuration, fromDate, toDate, groups, trackersInfo });

        await sendResult(reportId, result, hash);
        process.exit(0);
    } catch (error) {
        logToLaravel(`Error in main process: ${error.message}`);
        await updateProgress(reportId, -1, hash);
        process.exit(1);
    }
})();

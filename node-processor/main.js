import pLimit from "p-limit";
import { fetchAllCsvs } from "./fetchAllCsv.js";
import { runWorker } from "./worker.js";
import minimist from "minimist";
import { createTableStructureForSpeedupReport } from "./createTableStructureForSpeedupReport.js";
import fetchAddresses from "./utils/geocoder.js";
import updateProgress from "./utils/updateProgress.js";
import { getTrackerGroups, getTrackers } from "./utils/dataHelpers.js";
import fs from "fs";
import os from "os";
import path from "path";

const limit = pLimit(5);
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

        const tempDir = os.tmpdir();
        const tempFilePath = path.join(tempDir, `speedup_report_${reportId}_${Date.now()}.json`);
        fs.writeFileSync(tempFilePath, JSON.stringify(result));

        // Optionally, you can log the temp file path for Laravel to read
        console.log(tempFilePath);
        process.exit(0);
    } catch (error) {
        console.error(error);
        process.exit(1);
    }
})();

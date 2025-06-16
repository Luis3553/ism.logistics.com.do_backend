import axios from "axios";
import { logToLaravel } from "./logToLaravel.js";

async function updateProgress(reportId, percent, hash) {
    try {
        let result = await axios.put(
            `http://127.0.0.1/api/reports/${reportId}/status/update`,
            {
                percent,
            },
            {
                headers: { "Content-Type": "application/json", "X-Hash-Token": hash },
            }
        );
        if (result.status !== 200) {
            logToLaravel(`Failed to update progress for report ${reportId}: ${result.statusText}`);
        } else {
            logToLaravel(`Progress updated to ${percent}% for report ${reportId}`);
        }
    } catch (err) {
        logToLaravel(`Failed to update progress for report ${reportId}: ${err.message}`);
    }
}

export default updateProgress;

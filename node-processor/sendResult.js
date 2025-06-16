import axios from "axios";
import { logToLaravel } from "./utils/logToLaravel.js";

export async function sendResult(reportId, data, hash) {
    try {
        let result = await axios.put(
            `http://kola-real.local/api/reports/${reportId}/result`,
            {
                data,
                secret: "xd",
            },
            {
                headers: { "Content-Type": "application/json", "X-Hash-Token": hash },
            }
        );

        logToLaravel(`Result sent for report ${reportId}: ${result}`);
    } catch (err) {
        logToLaravel(`Failed to send result for report ${reportId}: ${err.message}`);
        throw new Error(`Failed to send result: ${err.message}`);
    }
}

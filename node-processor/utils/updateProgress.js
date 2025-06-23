import axios from "axios";

async function updateProgress(reportId, percent, hash) {
    try {
        await axios.put(
            `${process.env.APP_URL}/reports/${reportId}/status/update`,
            {
                percent,
            },
            {
                headers: { "Content-Type": "application/json", "X-Hash-Token": hash },
            }
        );
    } catch (err) {
        throw new Error(`Failed to update progress: ${err.message}`);
    }
}

export default updateProgress;

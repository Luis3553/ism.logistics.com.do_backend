import axios from "axios";

export async function sendResult(reportId, data, hash) {
    try {
        console.log(process.env.APP_URL);
        await axios.put(
            `${process.env.APP_URL}/reports/${reportId}/result`,
            {
                data,
                secret: "xd",
            },
            {
                headers: { "Content-Type": "application/json", "X-Hash-Token": hash },
            }
        );
    } catch (err) {
        throw new Error(`Failed to send result: ${err.message}`);
    }
}

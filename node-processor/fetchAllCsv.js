import axios from "axios";
import pLimit from "p-limit";

const limit = pLimit(50); // 50 requests at a time

async function fetchCsv(id, from, to, hash) {
    const response = await axios.post(
        "https://app.progps.com.do/api-v2/tracker/raw_data/read",
        {
            tracker_id: id,
            from: from,
            to: to,
            hash: hash,
            columns: ["lat", "lng", "speed"],
            format: "csv",
        },
        { responseType: "arraybuffer" }
    );

    return Buffer.from(response.data);
}

export async function fetchAllCsvs(trackerIds, from, to, hash) {
    const jobs = trackerIds.map((id) =>
        limit(async () => {
            const csv = await fetchCsv(id, from, to, hash);
            return { id, csv };
        })
    );

    return await Promise.all(jobs);
}

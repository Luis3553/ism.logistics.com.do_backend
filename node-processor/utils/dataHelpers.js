import axios from "axios";

export async function getTrackerGroups(hash) {
    try {
        const response = await axios.post(
            "https://app.progps.com.do/api-v2/tracker/group/list",
            {
                hash: hash,
            },
            {
                headers: {
                    "Content-Type": "application/json",
                },
            }
        );

        return response.data.list;
    } catch (error) {
        throw new Error(`Error fetching tracker groups: ${error.message}`);
    }
}

export async function getTrackers(hash) {
    try {
        const response = await axios.post(
            "https://app.progps.com.do/api-v2/tracker/list",
            {
                hash: hash,
            },
            {
                headers: {
                    "Content-Type": "application/json",
                },
            }
        );

        return response.data.list;
    } catch (error) {
        throw new Error(`Error fetching trackers: ${error.message}`);
    }
}

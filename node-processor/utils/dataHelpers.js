import axios from "axios";
import { logToLaravel } from "./logToLaravel.js";

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
        logToLaravel(`Error fetching tracker groups: ${error}`);
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
        logToLaravel(`Error fetching trackers for group ${groupId}: ${error}`);
    }
}

import axios from "axios";
import pLimit from "p-limit";
import { logToLaravel } from "./logToLaravel.js";

const limit = pLimit(400);
const addressCache = new Map();

async function getAddress(lat, lng, retries, delay, hash) {
    for (let attempt = 1; attempt <= retries; attempt++) {
        try {
            const res = await axios.post("https://app.progps.com.do/api-v2/geocoder/search_location", {
                hash: hash,
                lat,
                lng,
                lang: "es_ES",
                geocoder: "google",
            });
            return res.data?.value || "N/A";
        } catch (e) {
            logToLaravel("Error fetching address:", { lat, lng, attempt, error: e.message });
            if (attempt === retries) {
                return `Error: ${e.response?.status || e.message}`;
            }
            await new Promise((res) => setTimeout(res, delay));
        }
    }
}

async function fetchAddresses(events, hash) {
    const jobs = events.map((event, idx) =>
        limit(async () => {
            let startAddress = await getCachedAddress(event.start_lat, event.start_lng, hash);
            event.start_address = startAddress;

            if (event.end_lat == 0 || event.end_lng == 0) {
                event.end_address = startAddress;
            } else {
                event.end_address = await getCachedAddress(event.end_lat, event.end_lng, hash);
            }

            return event;
        })
    );
    return await Promise.all(jobs);
}

async function getCachedAddress(lat, lng, hash) {
    const key = `${Number(lat).toFixed(4)}_${Number(lng).toFixed(4)}`;
    if (addressCache.has(key)) return addressCache.get(key);

    const address = await getAddress(lat, lng, 3, 500, hash);
    addressCache.set(key, address);
    return address;
}

export default fetchAddresses;

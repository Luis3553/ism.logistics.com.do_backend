import { parse } from "csv-parse/sync";
import { formatDurationHMS, formatTo12HourFull, getDurationInSeconds } from "./utils/timeAndDateFormaters.js";
import { logToLaravel } from "./utils/logToLaravel.js";

export async function detectSpeedBurstsFromCsv(csvText, threshold, min_duration) {
    try {
        let min_durationSec = min_duration * 60;
        const records = parse(csvText, {
            columns: true,
            skip_empty_lines: true,
            trim: true,
        });

        const events = [];
        let inHighSpeed = false;
        let highSpeedPeriod = {};
        let speeds = [];

        for (const row of records) {
            const speed = parseFloat(row.speed);
            const time = row.msg_time;

            if (speed > threshold) {
                if (!inHighSpeed) {
                    inHighSpeed = true;
                    highSpeedPeriod = {
                        start_time: time,
                        start_lat: row.lat,
                        start_lng: row.lng,
                    };
                    speeds = [];
                }
                speeds.push(speed);
            } else if (inHighSpeed) {
                highSpeedPeriod.end_time = time;
                highSpeedPeriod.end_lat = row.lat;
                highSpeedPeriod.end_lng = row.lng;
                highSpeedPeriod.max_speed = Math.max(...speeds);
                highSpeedPeriod.average_speed = Math.round(speeds.reduce((a, b) => a + b) / speeds.length);

                const start = new Date(highSpeedPeriod.start_time);
                const end = new Date(highSpeedPeriod.end_time);
                const durationSec = (end - start) / 1000;

                if (durationSec >= min_durationSec) events.push(highSpeedPeriod);

                inHighSpeed = false;
                highSpeedPeriod = {};
                speeds = [];
            }
        }

        events.forEach((event) => {
            const rawStart = event.start_time;
            const rawEnd = event.end_time;

            event.duration = {
                value: formatDurationHMS(rawStart, rawEnd),
                raw: getDurationInSeconds(rawStart, rawEnd),
            };

            event.start_time = formatTo12HourFull(rawStart);
            event.end_time = formatTo12HourFull(rawEnd);
        });

        return events;
    } catch (error) {
        throw new Error(`Failed to process CSV: ${error.message}`);
    }
}

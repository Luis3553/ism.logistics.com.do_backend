import { format, parseISO, parse, differenceInSeconds, intervalToDuration } from "date-fns";

// Human-readable duration in Spanish
export function formatHumanReadableDuration(seconds) {
    if (seconds < 1) return 0;

    const duration = intervalToDuration({ start: 0, end: seconds * 1000 });

    const labels = {
        years: "año",
        months: "mes",
        days: "d",
        hours: "h",
        minutes: "min",
        seconds: "s",
    };

    const result = [];

    for (const [unit, label] of Object.entries(labels)) {
        const amount = duration[unit];
        if (amount > 0) {
            const plural = amount > 1 && !["d", "h", "min", "s"].includes(label) ? "es" : "";
            result.push(`${amount}${label}${plural}`);
        }
    }

    return result.join(" ");
}

// Format to 12-hour time with full date
export function formatTo12HourFull(dateString) {
    // Try parsing as SQL (yyyy-MM-dd HH:mm:ss) or ISO
    let date;
    try {
        date = parse(dateString, "yyyy-MM-dd HH:mm:ss", new Date());
        if (isNaN(date)) throw new Error();
    } catch {
        date = parseISO(dateString);
    }
    return format(date, "yyyy/MM/dd hh:mm:ss a");
}

// Format duration HH:MM:SS between two ISO strings
export function formatDurationHMS(start, end) {
    let startDate, endDate;
    try {
        startDate = parse(start, "yyyy-MM-dd HH:mm:ss", new Date());
        if (isNaN(startDate)) throw new Error();
    } catch {
        startDate = parseISO(start);
    }
    try {
        endDate = parse(end, "yyyy-MM-dd HH:mm:ss", new Date());
        if (isNaN(endDate)) throw new Error();
    } catch {
        endDate = parseISO(end);
    }

    let totalSeconds = Math.abs(differenceInSeconds(endDate, startDate));
    const hours = String(Math.floor(totalSeconds / 3600)).padStart(2, "0");
    totalSeconds %= 3600;
    const minutes = String(Math.floor(totalSeconds / 60)).padStart(2, "0");
    const seconds = String(totalSeconds % 60).padStart(2, "0");

    return `${hours}:${minutes}:${seconds}`;
}

// Duration in seconds
export function getDurationInSeconds(start, end) {
    let startDate, endDate;
    try {
        startDate = parse(start, "yyyy-MM-dd HH:mm:ss", new Date());
        if (isNaN(startDate)) throw new Error();
    } catch {
        startDate = parseISO(start);
    }
    try {
        endDate = parse(end, "yyyy-MM-dd HH:mm:ss", new Date());
        if (isNaN(endDate)) throw new Error();
    } catch {
        endDate = parseISO(end);
    }
    return Math.abs(differenceInSeconds(endDate, startDate));
}

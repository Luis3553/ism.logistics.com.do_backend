import fs from "fs";
import path from "path";

export function logToLaravel(message) {
    const logPath = path.resolve("./storage/logs/laravel.log");
    const timestamp = new Date().toISOString();
    fs.appendFileSync(logPath, `[${timestamp}] node.INFO: ${message}\n`);
}

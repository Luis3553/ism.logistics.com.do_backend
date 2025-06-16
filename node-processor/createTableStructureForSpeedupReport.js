import { logToLaravel } from "./utils/logToLaravel.js";
import { formatHumanReadableDuration } from "./utils/timeAndDateFormaters.js";
import { format } from "date-fns";

export function createTableStructureForSpeedupReport({ outputs, allowedSpeed, minDuration, fromDate, toDate, groups, trackersInfo }) {
    try {
        const groupsMap = groups.reduce(
            (acc, group) => {
                acc[group.id] = group;
                return acc;
            },
            { 0: { id: 0, title: "Grupo Principal" } }
        );

        const trackersInfoMap = trackersInfo.reduce((acc, tracker) => {
            acc[tracker.id] = tracker;
            return acc;
        }, {});

        const groupedOutputsByTrackerGroup = outputs.reduce((acc, event) => {
            const group = groupsMap[trackersInfoMap[event.id].group_id];
            (acc[group.title] ??= []).push(event);
            return acc;
        }, {});

        const totalSeconds = outputs.reduce((acc, tracker) => {
            return (
                acc +
                tracker.data.reduce((innerAcc, event) => {
                    return innerAcc + (event.duration?.raw || 0);
                }, 0)
            );
        }, 0);

        const result = {
            title: "Informe de Velocidad Excesiva",
            date: `Desde ${format(new Date(fromDate), "yyyy/MM/dd hh:mm:ss a")} hasta ${format(new Date(toDate), "yyyy/MM/dd hh:mm:ss a")}`,
            summary: {
                title: "Resumen General",
                color: "#eeece1",
                rows: [
                    {
                        title: "Total Eventos",
                        value: outputs.reduce((acc, output) => acc + output.data.length, 0),
                    },
                    {
                        title: "Total Vehículos",
                        value: outputs.length,
                    },
                    {
                        title: "Duración Total",
                        value: formatHumanReadableDuration(totalSeconds),
                    },
                    {
                        title: "Velocidad Permitida",
                        value: allowedSpeed,
                    },
                    {
                        title: "Duración de Violación",
                        value: `${minDuration} min`,
                    },
                ],
            },
            data: Object.entries(groupedOutputsByTrackerGroup).map(([groupTitle, events]) => {
                const totalEvents = events.reduce((acc, event) => acc + event.data.length, 0);

                return {
                    groupLabel: `${groupTitle} (${events.length} Vehículos) (${totalEvents} Eventos)`,
                    bgColor: "#eeece1",
                    content: {
                        columns: [
                            { name: "Nombre del objeto", key: "tracker_name" },
                            { name: "Tiempo Inicio", key: "start_time" },
                            { name: "Tiempo Fin", key: "end_time" },
                            { name: "Duración", key: "duration" },
                            { name: "Dirección Inicio", key: "start_address" },
                            { name: "Dirección Final", key: "end_address" },
                            { name: "Velocidad Promedio", key: "average_speed" },
                            { name: "Velocidad Máx.", key: "max_speed" },
                        ],
                        rows: events.flatMap((event) =>
                            event.data.map((speed) => {
                                const info = trackersInfoMap[event.id || event.tracker_id] || {};

                                return {
                                    tracker_name: info.label || "",
                                    start_time: speed.start_time,
                                    end_time: speed.end_time,
                                    duration: speed.duration.value,
                                    start_address: speed.start_address,
                                    end_address: speed.end_address,
                                    average_speed: speed.average_speed,
                                    max_speed: speed.max_speed,
                                };
                            })
                        ),
                    },
                };
            }),
            columns_dimensions_for_excel_file: {
                A: 43,
                B: 22,
                C: 22,
                D: 9,
                E: 43,
                F: 43,
                G: 20,
                H: 20,
            },
        };
        return result;
    } catch (e) {
        throw new Error(`Failed to create table structure for speedup report: ${e.stack}`);
    }
}

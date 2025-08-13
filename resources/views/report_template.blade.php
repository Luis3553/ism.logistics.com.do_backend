<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <title>{{ $reportData['title'] }}</title>
    <style>
        body {
            margin: 0;
            padding: 0;
            font-family: Roboto, DejaVu Sans, sans-serif;
        }

        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 10px;
            margin: 40px 40px 40px 40px;
        }

        h1 {
            font-size: 18px;
            margin-top: 0px;
            line-height: 1;
            font-weight: bold;
            margin-bottom: -10px;
        }

        h2 {
            font-size: 12px;
            margin-bottom: 10px;
        }

        table {
            border-collapse: collapse;
        }

        th,
        td {
            border: 1px solid #000;
            padding: 3px 4px;
            text-align: left;
        }

        .summary-table td:first-child {
            width: 30%;
            font-weight: bold;
        }

        .group-title {
            background-color: #C5D9F1;
            font-weight: bold;
            padding: 3px 4px;
        }

        .header-row {
            background-color: #f2f2f2;
            font-weight: bold;
        }

        table {
            border-collapse: collapse;
            page-break-inside: auto;
        }

        tr {
            page-break-inside: avoid;
            page-break-after: auto;
        }

        thead {
            display: table-header-group;
        }

        tfoot {
            display: table-footer-group;
        }
    </style>
    @php
        $columnWidthsForDataTables = [
            1 => [220, 100, 100, 110, 80],
            2 => [150, 130, 130, 50, 190, 190, 60, 60],
            3 => [180, 130, 130, 60, 420, 65],
            4 => [200, 100, 70, 110, 140, 90, 90],
            5 => [80, 170, 90, 80, 120, 110, 130, 90, 90],
            6 => [180, 100, 65, 65, 85, 65, 60, 70, 65, 65, 100],
            7 => [180, 100, 65, 130, 85, 65, 60, 130, 120],
            8 => array_merge(
                [145, 69, 50, 30],
                array_fill(0, max(0, count($reportData['data'][0]['content']['columns'] ?? []) - 4), 10),
            ),
            9 => [180, 100, 40, 65, 90, 110, 65],
            10 => [60, 50, 240, 50, 240, 85, 70, 80, 90],
        ];

        $sumOfColumnWidths = array_sum($columnWidthsForDataTables[$reportId] ?? []);
        $colsCount = count($columnWidthsForDataTables[$reportId] ?? []);

        $paperOrientations = [
            1 => 'portrait',
            2 => 'landscape',
            3 => 'landscape',
            4 => 'landscape',
            5 => 'landscape',
            6 => 'landscape',
            7 => 'landscape',
            8 => 'landscape',
            9 => 'portrait',
            10 => 'landscape',
        ];

        $widths = $columnWidthsForDataTables[$reportId];
    @endphp
    <style>
        @page {
            size: A4 {{ $paperOrientations[$reportId] }};
            margin: 0mm;
        }

        @foreach ($widths as $i => $width)
            .col-{{ $i }} {
                width: {{ $width }}px;
            }
        @endforeach
    </style>
</head>

<body>
    {{-- Title and Date --}}
    <h1>{{ $reportData['title'] }}</h1>
    <h2>{{ $reportData['date'] }}</h2>

    {{-- Summary Table --}}
    <table class="summary-table">
        <tr>
            <td colSpan="2" style="background-color: #efefef; text-align: center;">
                {{ $reportData['summary']['title'] }}</td>
        </tr>
        @foreach ($reportData['summary']['rows'] as $row)
            <tr>
                <td style="background-color: #efefef;">{{ $row['title'] }}</td>
                <td style="text-align: right;">{{ $row['value'] }}</td>
            </tr>
        @endforeach
    </table>

    {{-- Data Tables --}}
    @foreach ($reportData['data'] as $group)
        @include('group-table', [
            'group' => $group,
            'isNested' => false,
            'colsCount' => $colsCount,
            'sumOfCols' => $sumOfColumnWidths,
        ])
    @endforeach

</body>

</html>

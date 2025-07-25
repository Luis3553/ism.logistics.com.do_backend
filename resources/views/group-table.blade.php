{{-- Recursive group renderer --}}
@php
    $columns = $group['content']['columns'] ?? [];
    $rows = $group['content']['rows'] ?? null;
    $marginTop = $isNested ? '0px' : '20px';
@endphp

<table style="margin-top: {{ $marginTop }};">
    <thead>
        <tr>
            <th class="group-title" colspan="{{ $colsCount }}" style="background-color: {{ $group['bgColor'] }};">
                {{ $group['groupLabel'] }}
            </th>
        </tr>

        @if ($columns)
            <tr class="header-row">
                @foreach ($columns as $column)
                    @php
                        $style = $column['style'] ?? [];
                        $thStyles = [];

                        if (!empty($style['alignment']['horizontal'])) {
                            $thStyles[] = 'text-align: ' . $style['alignment']['horizontal'];
                        }

                        $styleAttr = implode('; ', $thStyles);
                    @endphp
                    <th style="{{ $styleAttr }}">{{ $column['name'] }}</th>
                @endforeach
            </tr>
        @else
            <tr>
                @for ($i = 0; $i < $colsCount; $i++)
                    <th style="padding: 0px 4px; border-top: 0; border-bottom: 0;"></th>
                @endfor
            </tr>
        @endif
    </thead>

    @if ($rows)
        <tbody>
            @foreach ($rows as $row)
                <tr>
                    @foreach ($columns as $i => $column)
                        @php
                            $key = $column['key'];
                            $cell = $row[$key] ?? ['value' => '-', 'style' => []];
                            $value = $cell['value'] ?? '-';
                            $style = $cell['style'] ?? [];

                            $tdStyles = [];

                            if (!empty($style['fill']['startColor']['rgb'])) {
                                $tdStyles[] = 'background-color: #' . $style['fill']['startColor']['rgb'];
                            }

                            if (!empty($style['alignment']['horizontal'])) {
                                $tdStyles[] = 'text-align: ' . $style['alignment']['horizontal'];
                            }

                            if (!empty($style['font']['bold'])) {
                                $tdStyles[] = 'font-weight: bold';
                            }

                            $styleAttr = implode('; ', $tdStyles);
                        @endphp
                        <td class="col-{{ $i }}" style="{{ $styleAttr }}">{{ $value }}</td>
                    @endforeach
                </tr>
            @endforeach
        </tbody>
    @else
        <tbody>
            <tr>
                @for ($i = 0; $i < $colsCount; $i++)
                    <td style="padding: 0px 4px; border-top: 0; border-bottom: 0;" class="col-{{ $i }}">
                    </td>
                @endfor
            </tr>
        </tbody>
    @endif
</table>

{{-- Recurse into child groups if present --}}
@if (!isset($group['content']['columns']) && isset($group['content']))
    @foreach ($group['content'] as $subgroup)
        @include('group-table', [
            'group' => $subgroup,
            'isNested' => true,
            'colsCount' => $colsCount,
            'sumOfCols' => $sumOfColumnWidths,
        ])
    @endforeach
@endif

{{-- What an import did. $summary is GuestImport::summary()'s shape: the small import's flash and a background import's row both fill it. --}}
@php
    $problems = $summary['problems'] ?? [];
    $skippedRows = $summary['skipped_rows'] ?? [];
@endphp
Added {{ number_format($summary['created']) }}, skipped {{ number_format($summary['skipped']) }}.
@if ($summary['capped'] > 0)
    {{ number_format($summary['capped']) }} more {{ Str::plural('guest', $summary['capped']) }} weren't added. This event is at its plan's guest limit.
@endif
@if ($summary['skipped'] > 0 && $skippedRows !== [])
    <p class="evt-import-list-head">Skipped because they are already on the list or appear earlier in the file:</p>
    <ul class="evt-import-problems">
        @foreach ($skippedRows as $skip)
            <li>Row {{ $skip['row'] }}: {{ $skip['message'] }}</li>
        @endforeach
        @if ($summary['skipped'] > count($skippedRows))
            <li>And {{ number_format($summary['skipped'] - count($skippedRows)) }} more.</li>
        @endif
    </ul>
@endif
@if ($summary['invalid'] > 0)
    <p class="evt-import-list-head">{{ number_format($summary['invalid']) }} {{ Str::plural('row', $summary['invalid']) }} couldn't be imported. Fix {{ $summary['invalid'] === 1 ? 'it' : 'them' }} in the file and import again:</p>
    <ul class="evt-import-problems">
        @foreach ($problems as $problem)
            <li>Row {{ $problem['row'] }}: {{ $problem['message'] }}</li>
        @endforeach
        @if ($summary['invalid'] > count($problems))
            <li>And {{ number_format($summary['invalid'] - count($problems)) }} more.</li>
        @endif
    </ul>
@endif

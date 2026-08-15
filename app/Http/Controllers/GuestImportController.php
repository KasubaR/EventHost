<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreGuestImportRequest;
use App\Imports\EventGuestsImport;
use App\Models\Event;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\StreamedResponse;

class GuestImportController extends Controller
{
    public function create(Event $event): View
    {
        $this->authorize('update', $event);

        return view('events.guests.import', compact('event'));
    }

    public function store(StoreGuestImportRequest $request, Event $event): RedirectResponse
    {
        $this->authorize('update', $event);

        $import = new EventGuestsImport($event);

        Excel::import($import, $request->file('file'));

        return redirect()
            ->route('events.guests.index', $event)
            ->with('status', 'guests-imported')
            ->with('import_created', $import->createdCount)
            ->with('import_skipped', $import->skippedCount);
    }

    public function downloadTemplate(Event $event): StreamedResponse
    {
        $this->authorize('update', $event);

        $filename = 'guest-import-template.csv';

        return response()->streamDownload(function (): void {
            $handle = fopen('php://output', 'w');
            if ($handle === false) {
                return;
            }

            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, ['name', 'email', 'phone', 'group', 'table']);
            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}

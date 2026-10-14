<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreGuestImportRequest;
use App\Models\Event;
use App\Models\GuestImport;
use App\Services\GuestImporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class GuestImportController extends Controller
{
    public function create(Event $event): View
    {
        $this->authorizeInvitation($event);

        $runningImport = GuestImport::query()->where('event_id', $event->id)->active()->latest('id')->first();
        $maxRows = (int) config('events.guest_import.max_rows', 20000);
        $syncMaxRows = (int) config('events.guest_import.sync_max_rows', 500);

        return view('events.guests.import', compact('event', 'runningImport', 'maxRows', 'syncMaxRows'));
    }

    public function store(StoreGuestImportRequest $request, Event $event, GuestImporter $importer): RedirectResponse
    {
        $this->authorizeInvitation($event);

        $result = $importer->start($event, $request->user(), $request->file('file'), $request->inspection());

        if ($result instanceof GuestImport) {
            return redirect()->route('events.guests.import.show', ['event' => $event, 'guestImport' => $result]);
        }

        return redirect()
            ->route('events.guests.index', $event)
            ->with('status', 'guests-imported')
            ->with('import_created', $result->createdCount)
            ->with('import_skipped', $result->skippedCount)
            ->with('import_capped', $result->cappedCount)
            ->with('import_invalid', $result->invalidCount)
            ->with('import_problems', $result->problems)
            ->with('import_skipped_rows', $result->skippedRows);
    }

    /**
     * Where a background import is followed. Renders its current state without JavaScript; guest-import-status.js
     * polls progress() and reloads the page once the import has finished.
     */
    public function show(Event $event, GuestImport $guestImport): View
    {
        $this->authorizeImport($event, $guestImport);

        if ($guestImport->isFinished() && $guestImport->seen_at === null) {
            $guestImport->forceFill(['seen_at' => now()])->save();
        }

        return view('events.guests.import-status', ['event' => $event, 'import' => $guestImport]);
    }

    public function progress(Event $event, GuestImport $guestImport): JsonResponse
    {
        $this->authorizeImport($event, $guestImport);

        return response()->json([
            'status' => $guestImport->status,
            'finished' => $guestImport->isFinished() || $guestImport->isStale(),
            'processed_rows' => $guestImport->processed_rows,
            'total_rows' => $guestImport->total_rows,
        ]);
    }

    public function downloadTemplate(Event $event): StreamedResponse
    {
        $this->authorizeInvitation($event);

        $filename = 'guest-import-template.csv';

        return response()->streamDownload(function (): void {
            $handle = fopen('php://output', 'w');
            if ($handle === false) {
                return;
            }

            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, ['name', 'email', 'phone', 'group', 'table', 'plus_one']);
            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private function authorizeInvitation(Event $event): void
    {
        $this->authorize('update', $event);

        abort_unless($event->isInvitation(), 404);
    }

    private function authorizeImport(Event $event, GuestImport $guestImport): void
    {
        $this->authorizeInvitation($event);

        abort_unless($guestImport->event_id === $event->id, 404);
    }
}

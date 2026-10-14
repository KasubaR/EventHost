<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreGuestImportApiRequest;
use App\Models\Event;
use App\Models\GuestImport;
use App\Services\GuestImporter;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * JSON sibling of App\Http\Controllers\GuestImportController — returns the
 * created/skipped/capped/invalid counts (plus the first invalid and skipped rows) directly
 * instead of via flash keys. A file too big to import while the client waits is queued
 * instead: 202 with `import_id` and a `status_url` that show() answers. Authorizes via
 * EventPolicy directly instead of the narrower owner-only FormRequest check (Slice
 * C3 plan, design decision #1). Web controller untouched.
 */
class GuestImportController extends Controller
{
    public function store(StoreGuestImportApiRequest $request, Event $event, GuestImporter $importer): JsonResponse
    {
        $this->authorizeInvitation($event);

        $result = $importer->start($event, $request->user(), $request->file('file'), $request->inspection());

        if ($result instanceof GuestImport) {
            return response()->json([
                'queued' => true,
                'import_id' => $result->id,
                'status' => $result->status,
                'status_url' => route('api.v1.host.events.guests.import.show', ['event' => $event, 'guestImport' => $result]),
            ], 202);
        }

        return response()->json([
            'created' => $result->createdCount,
            'skipped' => $result->skippedCount,
            'capped' => $result->cappedCount,
            'invalid' => $result->invalidCount,
            'problems' => $result->problems,
            'skipped_rows' => $result->skippedRows,
        ]);
    }

    public function show(Event $event, GuestImport $guestImport): JsonResponse
    {
        $this->authorizeInvitation($event);

        abort_unless($guestImport->event_id === $event->id, 404);

        return response()->json([
            'import_id' => $guestImport->id,
            'status' => $guestImport->isStale() ? GuestImport::STATUS_FAILED : $guestImport->status,
            'finished' => $guestImport->isFinished() || $guestImport->isStale(),
            'file_name' => $guestImport->original_name,
            'total_rows' => $guestImport->total_rows,
            'processed_rows' => $guestImport->processed_rows,
            ...$guestImport->summary(),
            'failure' => $guestImport->isStale() ? GuestImport::STALE_MESSAGE : $guestImport->failure_message,
            'started_at' => $guestImport->started_at?->toIso8601String(),
            'finished_at' => $guestImport->finished_at?->toIso8601String(),
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
}

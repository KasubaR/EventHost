<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreGuestImportApiRequest;
use App\Imports\EventGuestsImport;
use App\Models\Event;
use Illuminate\Http\JsonResponse;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * JSON sibling of App\Http\Controllers\GuestImportController — returns the
 * created/skipped/capped counts directly instead of via flash keys. Authorizes via
 * EventPolicy directly instead of the narrower owner-only FormRequest check (Slice
 * C3 plan, design decision #1). Web controller untouched.
 */
class GuestImportController extends Controller
{
    public function store(StoreGuestImportApiRequest $request, Event $event): JsonResponse
    {
        $this->authorizeInvitation($event);

        $import = new EventGuestsImport($event);

        Excel::import($import, $request->file('file'));

        return response()->json([
            'created' => $import->createdCount,
            'skipped' => $import->skippedCount,
            'capped' => $import->cappedCount,
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
            fputcsv($handle, ['name', 'email', 'phone', 'group', 'table']);
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

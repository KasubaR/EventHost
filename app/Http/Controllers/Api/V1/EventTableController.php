<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreEventTableApiRequest;
use App\Http\Requests\Api\V1\UpdateEventTableApiRequest;
use App\Http\Resources\Api\V1\TableResource;
use App\Models\Event;
use App\Models\EventTable;
use App\Services\QrCodeService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * JSON sibling of App\Http\Controllers\EventTableController. Authorizes via
 * EventTablePolicy/EventPolicy directly instead of the narrower owner-only
 * FormRequest checks, and moves the premium-tier gate out of authorize() into an
 * explicit, uniformly-shaped check (Slice C3 plan, design decisions #1-#3). No qr()
 * action: TableResource.qr_payload_url replaces it (decision #4). Web controller
 * untouched.
 */
class EventTableController extends Controller
{
    public function index(Event $event): JsonResponse
    {
        $this->authorize('update', $event);
        $event->loadMissing('user');

        if (! $event->ownerHasPremiumEventTools()) {
            return response()->json(['error' => 'premium_required', 'required_tier' => 'pro'], 403);
        }

        $tables = $event->tables()->get()->map(fn (EventTable $t) => new TableResource($t, $event))->values();

        return response()->json(['tables' => $tables]);
    }

    public function store(StoreEventTableApiRequest $request, Event $event): JsonResponse
    {
        $this->authorize('update', $event);
        $event->loadMissing('user');

        if (! $event->ownerHasPremiumEventTools()) {
            return response()->json(['error' => 'premium_required', 'required_tier' => 'pro'], 403);
        }

        $table = $event->tables()->create([
            'label' => $request->validated()['label'],
            'sort_order' => (int) $event->tables()->max('sort_order') + 1,
        ]);

        return response()->json(new TableResource($table, $event), 201);
    }

    public function update(UpdateEventTableApiRequest $request, Event $event, EventTable $table): JsonResponse
    {
        $table->loadMissing('event.user');
        $this->authorize('update', $table);
        abort_unless($table->event_id === $event->id, 404);

        if (! $event->ownerHasPremiumEventTools()) {
            return response()->json(['error' => 'premium_required', 'required_tier' => 'pro'], 403);
        }

        $table->update(['label' => $request->validated()['label']]);

        return response()->json(new TableResource($table->fresh(), $event))->setStatusCode(200);
    }

    public function destroy(Event $event, EventTable $table): JsonResponse
    {
        abort_unless($table->event_id === $event->id, 404);
        $this->authorize('delete', $table);

        $table->delete();

        return response()->json(null, 204);
    }

    public function qrSheet(Event $event, QrCodeService $qrCodeService): Response|JsonResponse
    {
        $this->authorize('update', $event);
        $event->loadMissing('user');

        if (! $event->ownerHasPremiumEventTools()) {
            return response()->json(['error' => 'premium_required', 'required_tier' => 'pro'], 403);
        }

        $tables = $event->tables()->get()->map(fn (EventTable $table) => [
            'label' => $table->label,
            'code' => $table->code,
            'qr_data_uri' => 'data:image/svg+xml;base64,'.base64_encode($qrCodeService->svg($table->publicUploadUrl(), 220)),
        ]);

        $pdf = Pdf::loadView('events.tables.qr-sheet', compact('event', 'tables'))
            ->setPaper('a4', 'portrait');

        return $pdf->download('table-qr-codes-'.$event->slug.'.pdf');
    }
}

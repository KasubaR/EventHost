<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreEventTableRequest;
use App\Http\Requests\UpdateEventTableRequest;
use App\Models\Event;
use App\Models\EventTable;
use App\Services\QrCodeService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\View\View;

class EventTableController extends Controller
{
    public function index(Event $event): View|RedirectResponse
    {
        $this->authorize('update', $event);

        if (! $event->ownerHasPremiumEventTools()) {
            return redirect()->route('billing.show')->with('status', 'premium-required-tables');
        }

        $tables = $event->tables()->get();

        return view('events.tables.index', compact('event', 'tables'));
    }

    public function store(StoreEventTableRequest $request, Event $event): RedirectResponse
    {
        $event->tables()->create([
            'label' => $request->validated()['label'],
            'sort_order' => (int) $event->tables()->max('sort_order') + 1,
        ]);

        return redirect()->route('events.tables.index', $event)->with('status', 'table-created');
    }

    public function update(UpdateEventTableRequest $request, Event $event, EventTable $table): RedirectResponse
    {
        abort_unless($table->event_id === $event->id, 404);

        $table->update(['label' => $request->validated()['label']]);

        return redirect()->route('events.tables.index', $event)->with('status', 'table-updated');
    }

    public function destroy(Event $event, EventTable $table): RedirectResponse
    {
        abort_unless($table->event_id === $event->id, 404);
        $this->authorize('delete', $table);

        $table->delete();

        return redirect()->route('events.tables.index', $event)->with('status', 'table-deleted');
    }

    public function qr(Event $event, EventTable $table, QrCodeService $qrCodeService): Response
    {
        abort_unless($table->event_id === $event->id, 404);
        $this->authorize('update', $event);

        if (! $event->ownerHasPremiumEventTools()) {
            abort(403);
        }

        $svg = $qrCodeService->svg($table->publicUploadUrl());

        return response($svg, 200, ['Content-Type' => 'image/svg+xml']);
    }

    public function qrSheet(Event $event, QrCodeService $qrCodeService): Response
    {
        $this->authorize('update', $event);

        if (! $event->ownerHasPremiumEventTools()) {
            abort(403);
        }

        $tables = $event->tables()->get()->map(fn (EventTable $table) => [
            'label' => $table->label,
            'code' => $table->code,
            // dompdf can't parse a raw inline <svg> reliably; a data URI keeps the
            // SVG's own XML prolog self-contained inside the <img> tag instead.
            'qr_data_uri' => 'data:image/svg+xml;base64,'.base64_encode($qrCodeService->svg($table->publicUploadUrl(), 220)),
        ]);

        $pdf = Pdf::loadView('events.tables.qr-sheet', compact('event', 'tables'))
            ->setPaper('a4', 'portrait');

        return $pdf->download('table-qr-codes-'.$event->slug.'.pdf');
    }
}

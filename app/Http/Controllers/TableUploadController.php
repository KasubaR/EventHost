<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreTablePhotoRequest;
use App\Models\Event;
use App\Models\EventTable;
use App\Services\EventPhotoUploadService;
use App\Services\PublicInvitationResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TableUploadController extends Controller
{
    public function show(string $slug, string $code, PublicInvitationResolver $resolver): View|RedirectResponse
    {
        $resolved = $resolver->resolveSibling($slug);

        if ($resolved instanceof RedirectResponse) {
            return $resolved;
        }

        $event = $resolved;
        $table = $this->resolveTable($event, $code);

        return view('events.tables.upload', [
            'event' => $event,
            'table' => $table,
            'isLive' => $event->photoWallIsLive(),
        ]);
    }

    public function store(
        StoreTablePhotoRequest $request,
        string $slug,
        string $code,
        EventPhotoUploadService $uploadService,
        PublicInvitationResolver $resolver,
    ): JsonResponse|RedirectResponse {
        $resolved = $resolver->resolveSibling($slug);

        if ($resolved instanceof RedirectResponse) {
            return $resolved;
        }

        $event = $resolved;
        $table = $this->resolveTable($event, $code);

        if (! $event->photoWallIsLive()) {
            abort(403);
        }

        $photo = $uploadService->store(
            $event,
            $table,
            $request->file('photo'),
            $request->validated()['uploader_name'] ?? null,
            $this->hashIp($request),
        );

        if ($request->wantsJson()) {
            return response()->json([
                'photo' => [
                    'id' => $photo->id,
                    'thumbnail_url' => $photo->thumbnail_url,
                    'status' => $photo->status->value,
                ],
            ], 201);
        }

        return back()->with('status', 'photo-uploaded');
    }

    private function resolveTable(Event $event, string $code): EventTable
    {
        return EventTable::query()
            ->where('event_id', $event->id)
            ->where('code', strtoupper($code))
            ->firstOrFail();
    }

    private function hashIp(Request $request): string
    {
        return hash('sha256', $request->ip().'|'.config('app.key'));
    }
}

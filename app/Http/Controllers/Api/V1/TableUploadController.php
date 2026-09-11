<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTablePhotoRequest;
use App\Http\Resources\Api\V1\PublicEventListResource;
use App\Http\Resources\Api\V1\TablePhotoResource;
use App\Models\Event;
use App\Models\EventTable;
use App\Services\EventPhotoUploadService;
use App\Services\PublicInvitationResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * JSON sibling of App\Http\Controllers\TableUploadController — no session, no Blade
 * view. store() keeps the web controller's own 201 (a genuinely created EventPhoto row,
 * unlike every other B1/B2/B3 write endpoint — see the Slice B3 plan, decision #5). The
 * web controller is untouched by this class.
 */
class TableUploadController extends Controller
{
    public function show(string $slug, string $code, PublicInvitationResolver $resolver): JsonResponse|RedirectResponse
    {
        $resolved = $resolver->resolveSibling($slug);

        if ($resolved instanceof RedirectResponse) {
            return $resolved;
        }

        $event = $resolved;
        $table = $this->resolveTable($event, $code);

        return response()->json([
            'event' => new PublicEventListResource($event),
            'table' => [
                'label' => $table->label,
                'code' => $table->code,
            ],
            'is_live' => $event->photoWallIsLive(),
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

        return response()->json(['photo' => new TablePhotoResource($photo)], 201);
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

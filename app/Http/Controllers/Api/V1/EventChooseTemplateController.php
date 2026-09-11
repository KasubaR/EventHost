<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ChooseEventTemplateRequest;
use App\Http\Resources\Api\V1\EventResource;
use App\Http\Resources\Api\V1\InvitationTemplateCategoryResource;
use App\Http\Resources\Api\V1\InvitationTemplateResource;
use App\Models\Event;
use App\Models\InvitationTemplate;
use App\Models\InvitationTemplateCategory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * JSON sibling of App\Http\Controllers\EventChooseTemplateController. `index()` is
 * named for REST convention (web's equivalent is `show()`) — cosmetic only. The
 * web controller is untouched by this class.
 */
class EventChooseTemplateController extends Controller
{
    public function index(Request $request, Event $event): JsonResponse
    {
        $this->authorize('update', $event);

        if ($event->isTicketed()) {
            return response()->json(['error' => 'is_ticketed'], 422);
        }

        $q = trim((string) $request->query('q', ''));
        $categorySlug = $request->query('category');

        $categories = Cache::remember('tpl_categories', 300, function (): Collection {
            return InvitationTemplateCategory::query()
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get();
        });

        $templates = InvitationTemplate::query()
            ->where('is_active', true)
            ->with('categories')
            ->when($categorySlug, function ($query) use ($categorySlug): void {
                $query->whereHas('categories', function ($q2) use ($categorySlug): void {
                    $q2->where('slug', $categorySlug);
                });
            })
            ->when($q !== '', function ($query) use ($q): void {
                $like = '%'.$q.'%';
                $query->where(function ($inner) use ($like): void {
                    $inner->where('name', 'like', $like)
                        ->orWhere('description', 'like', $like);
                });
            })
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return response()->json([
            'categories' => InvitationTemplateCategoryResource::collection($categories),
            'templates' => InvitationTemplateResource::collection($templates),
        ]);
    }

    public function update(ChooseEventTemplateRequest $request, Event $event): JsonResponse
    {
        if ($event->isTicketed()) {
            return response()->json(['error' => 'is_ticketed'], 422);
        }

        $event->update([
            'invitation_template_id' => (int) $request->validated('invitation_template_id'),
        ]);

        return response()->json(new EventResource($event->fresh()))->setStatusCode(200);
    }
}

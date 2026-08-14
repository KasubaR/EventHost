<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateInvitationTemplateRequest;
use App\Models\InvitationTemplate;
use App\Support\AdminActivity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Intervention\Image\ImageManager;

class InvitationTemplateController extends Controller
{
    public function index(): View
    {
        $templates = InvitationTemplate::query()
            ->with('categories')
            ->orderByDesc('is_featured')
            ->orderBy('featured_sort_order')
            ->orderBy('name')
            ->get();

        return view('admin.templates.index', [
            'templates' => $templates,
            'featuredCount' => $templates->where('is_featured', true)->count(),
            'homepageLimit' => InvitationTemplate::HOMEPAGE_FEATURED_LIMIT,
        ]);
    }

    public function update(
        UpdateInvitationTemplateRequest $request,
        InvitationTemplate $invitation_template
    ): RedirectResponse {
        $data = $request->validated();
        $previousImage = $invitation_template->preview_image;
        $newImage = null;

        if ($request->hasFile('preview_image')) {
            $file = $request->file('preview_image');
            if ($file instanceof UploadedFile) {
                $newImage = $this->storePreviewImage($file);
            }
        }

        DB::transaction(function () use ($invitation_template, $data, $newImage): void {
            $invitation_template->is_featured = (bool) $data['is_featured'];
            $invitation_template->featured_sort_order = (int) $data['featured_sort_order'];

            if ($newImage !== null) {
                $invitation_template->preview_image = $newImage;
            }

            $invitation_template->save();
        });

        // Only drop the old file once the new path is safely committed.
        if ($newImage !== null && $previousImage !== null && $previousImage !== '') {
            DB::afterCommit(function () use ($previousImage): void {
                Storage::disk('public')->delete($previousImage);
            });
        }

        AdminActivity::log('Admin updated invitation template', [
            'template_id' => $invitation_template->id,
            'slug' => $invitation_template->slug,
            'is_featured' => $invitation_template->is_featured,
            'image_replaced' => $newImage !== null,
        ]);

        return redirect()
            ->route('admin.templates.index')
            ->with('status', 'template-updated');
    }

    public function destroyImage(InvitationTemplate $invitation_template): RedirectResponse
    {
        $previousImage = $invitation_template->preview_image;

        DB::transaction(function () use ($invitation_template): void {
            // A template with no image cannot stay on the homepage.
            $invitation_template->preview_image = null;
            $invitation_template->is_featured = false;
            $invitation_template->save();
        });

        if ($previousImage !== null && $previousImage !== '') {
            DB::afterCommit(function () use ($previousImage): void {
                Storage::disk('public')->delete($previousImage);
            });
        }

        AdminActivity::log('Admin removed invitation template image', [
            'template_id' => $invitation_template->id,
            'slug' => $invitation_template->slug,
        ]);

        return redirect()
            ->route('admin.templates.index')
            ->with('status', 'template-image-removed');
    }

    /**
     * Mirrors EventController::storeCoverImage(), only portrait — the card grids
     * on the homepage, /templates and the wizard all use a 3:4 thumbnail.
     */
    private function storePreviewImage(UploadedFile $file): string
    {
        $manager = extension_loaded('imagick')
            ? ImageManager::imagick()
            : ImageManager::gd();
        $image = $manager->read($file->getRealPath());
        $image->cover(600, 800);
        $webp = $image->toWebp(85);

        $path = 'templates/'.uniqid('tpl_', true).'.webp';
        Storage::disk('public')->put($path, $webp->toString());

        return $path;
    }
}

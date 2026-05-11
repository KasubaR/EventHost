<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateInvitationDesignRequest;
use App\Jobs\ProcessInvitationDesignImageJob;
use App\Models\Event;
use App\Services\InvitationCustomizationService;
use App\Support\InvitationCustomizationPersistenceValidator;
use App\Support\InvitationLayoutVariant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class EventInvitationDesignController extends Controller
{
    public function update(UpdateInvitationDesignRequest $request, Event $event, InvitationCustomizationService $customizationService): RedirectResponse
    {
        $validated = $request->validated();

        // Tracks every file written during this request for rollback on failure.
        $uploadedPaths = [];
        // Tracks pre-existing files to delete after a successful commit.
        // Populated inside the transaction so the locked row is the source of truth,
        // but consumed outside DB::afterCommit so the deletion is not silently
        // dropped if the process dies between commit and the after-commit callback.
        $pathsToDelete = [];

        try {
            DB::transaction(function () use ($event, $request, $validated, $customizationService, &$uploadedPaths, &$pathsToDelete): void {
                // Lock the row before reading so concurrent requests cannot clobber each other's gallery writes.
                $fresh = Event::lockForUpdate()->findOrFail($event->id);

                // Optimistic lock: if another session saved the customization while this form was open,
                // reject the submit so the user does not silently overwrite the newer state.
                $submittedToken = $request->input('customization_token', '');
                if ($submittedToken !== '') {
                    try {
                        $currentToken = md5(json_encode($fresh->invitation_customization, JSON_THROW_ON_ERROR));
                    } catch (\JsonException) {
                        // Customization contains un-encodable data; the token cannot be
                        // verified reliably, so fail closed rather than silently skipping
                        // the concurrency guard (the old ?: '' fallback made every token
                        // md5('') and effectively disabled the check).
                        throw ValidationException::withMessages([
                            'customization_token' => 'Could not verify the invitation design state. Please reload the page and try again.',
                        ]);
                    }
                    if ($submittedToken !== $currentToken) {
                        throw ValidationException::withMessages([
                            'customization_token' => 'Your invitation design was updated by another session. Reload the page to see the latest version before saving again.',
                        ]);
                    }
                }

                $template = $customizationService->resolvedTemplate($fresh);

                $candidateSections = [];
                foreach ($validated['section_order'] as $type) {
                    $candidateSections[] = [
                        'type' => $type,
                        'visible' => (bool) ($validated['section_visible'][$type] ?? false),
                    ];
                }

                $sections = $customizationService->mergeSectionsForPersistence($template, $candidateSections);

                $priorCustomization = is_array($fresh->invitation_customization) ? $fresh->invitation_customization : [];
                $prevEffects = $priorCustomization['effects'] ?? [];

                $galleryExisting = $priorCustomization['media']['gallery'] ?? [];
                $removeSet = $validated['gallery_remove'] ?? [];
                // Intersect against the event's own gallery before touching the filesystem.
                // Without this, a malicious user could submit paths from another event
                // and have them deleted via Storage::delete().
                $removeSet = array_values(array_intersect($removeSet, $galleryExisting));
                $galleryKeep = array_values(array_diff($galleryExisting, $removeSet));

                $pathsToDelete = array_values($removeSet);

                $videoToKeep = $prevEffects['video_background'] ?? null;
                if ($request->boolean('clear_video') && $videoToKeep !== null) {
                    $pathsToDelete[] = $videoToKeep;
                    $videoToKeep = null;
                } elseif ($request->hasFile('video_background') && $videoToKeep !== null) {
                    $pathsToDelete[] = $videoToKeep;
                    $videoToKeep = null;
                }

                $audioToKeep = $prevEffects['audio_track'] ?? null;
                if ($request->boolean('clear_audio') && $audioToKeep !== null) {
                    $pathsToDelete[] = $audioToKeep;
                    $audioToKeep = null;
                } elseif ($request->hasFile('audio_track') && $audioToKeep !== null) {
                    $pathsToDelete[] = $audioToKeep;
                    $audioToKeep = null;
                }

                $newGallery = [];
                $galleryOriginalPathsForJobs = [];
                foreach ($request->file('gallery_images', []) ?: [] as $file) {
                    if ($file instanceof UploadedFile) {
                        $path = $this->storeInvitationRasterOriginal($file, $fresh->id, 'invitation-gallery', 'gal_src_');
                        $newGallery[] = $path;
                        $uploadedPaths[] = $path;
                        $galleryOriginalPathsForJobs[] = $path;
                    }
                }

                $layoutVariant = InvitationLayoutVariant::normalize($template->layout_variant ?? null);
                $maxPortrait = InvitationLayoutVariant::maxInvitationHeroPortraitSlots($layoutVariant);
                $maxCouple = InvitationLayoutVariant::maxCouplePhotoSlots($layoutVariant);

                $priorHero = isset($priorCustomization['media']['hero_portrait'])
                    && is_string($priorCustomization['media']['hero_portrait'])
                    && $priorCustomization['media']['hero_portrait'] !== ''
                    ? $priorCustomization['media']['hero_portrait']
                    : null;
                $priorCouple = [];
                if (isset($priorCustomization['media']['couple_photos']) && is_array($priorCustomization['media']['couple_photos'])) {
                    $priorCouple = array_values(array_filter(array_map('strval', $priorCustomization['media']['couple_photos'])));
                }

                if ($maxPortrait === 0 && $priorHero !== null) {
                    $pathsToDelete[] = $priorHero;
                }
                if ($maxCouple === 0 && $priorCouple !== []) {
                    foreach ($priorCouple as $p) {
                        $pathsToDelete[] = $p;
                    }
                }

                $heroPortraitKeep = null;
                $heroOriginalPathsForJobs = [];
                if ($maxPortrait > 0) {
                    $heroPortraitKeep = $priorHero;
                    if ($request->boolean('clear_hero_portrait') && $heroPortraitKeep !== null) {
                        $pathsToDelete[] = $heroPortraitKeep;
                        $heroPortraitKeep = null;
                    } elseif ($request->hasFile('invitation_hero_portrait')) {
                        $file = $request->file('invitation_hero_portrait');
                        if ($file instanceof UploadedFile) {
                            if ($heroPortraitKeep !== null) {
                                $pathsToDelete[] = $heroPortraitKeep;
                            }
                            $path = $this->storeInvitationRasterOriginal($file, $fresh->id, 'invitation-hero', 'hero_src_');
                            $heroPortraitKeep = $path;
                            $uploadedPaths[] = $path;
                            $heroOriginalPathsForJobs[] = $path;
                        }
                    }
                }

                $coupleKeep = [];
                $coupleOriginalPathsForJobs = [];
                if ($maxCouple > 0) {
                    $coupleRemove = array_values(array_intersect($validated['couple_remove'] ?? [], $priorCouple));
                    foreach ($coupleRemove as $p) {
                        $pathsToDelete[] = $p;
                    }
                    $coupleKeep = array_values(array_diff($priorCouple, $coupleRemove));
                    foreach ($request->file('couple_photos', []) ?: [] as $file) {
                        if ($file instanceof UploadedFile) {
                            $path = $this->storeInvitationRasterOriginal($file, $fresh->id, 'invitation-couple', 'couple_src_');
                            $coupleKeep[] = $path;
                            $uploadedPaths[] = $path;
                            $coupleOriginalPathsForJobs[] = $path;
                        }
                    }
                }

                $videoPath = $videoToKeep;
                if ($request->hasFile('video_background')) {
                    $videoPath = $this->storeMediaUpload($request->file('video_background'), $fresh->id, 'video');
                    $uploadedPaths[] = $videoPath;
                }

                $audioPath = $audioToKeep;
                if ($request->hasFile('audio_track')) {
                    $audioPath = $this->storeMediaUpload($request->file('audio_track'), $fresh->id, 'audio');
                    $uploadedPaths[] = $audioPath;
                }

                // Coerce empty strings to null — the persistence validator's mediaPathRule
                // closure skips '' (same as null), so without this an empty string written
                // by a prior bug would persist and be read as truthy by downstream code.
                $videoPath = $videoPath ?: null;
                $audioPath = $audioPath ?: null;

                $newCustomization = [
                    'schema_version' => InvitationCustomizationService::CURRENT_SCHEMA_VERSION,
                    'theme' => [
                        'primary' => $validated['theme_primary'],
                        'accent' => $validated['theme_accent'],
                        'background' => $validated['theme_background'],
                        'font_heading_key' => $validated['font_heading_key'],
                        'font_body_key' => $validated['font_body_key'],
                    ],
                    'sections' => $sections,
                    'content' => [
                        'story' => (string) ($validated['content_story'] ?? ''),
                        'schedule' => array_values($validated['schedule_items']),
                    ],
                    'media' => [
                        'gallery' => array_values(array_merge($galleryKeep, $newGallery)),
                        'hero_portrait' => $maxPortrait > 0 ? $heroPortraitKeep : null,
                        'couple_photos' => $maxCouple > 0 ? array_values($coupleKeep) : [],
                    ],
                    'effects' => [
                        'animation_subtle' => $validated['animation_subtle'],
                        'countdown_enabled' => $validated['countdown_enabled'],
                        'video_background' => $videoPath,
                        'audio_track' => $audioPath,
                    ],
                ];

                InvitationCustomizationPersistenceValidator::validate($newCustomization);

                if ($priorCustomization !== []) {
                    $fresh->invitation_customization_previous = $priorCustomization;
                    $fresh->invitation_customization_previous_captured_at = now();
                    $fresh->invitation_customization_previous_captured_by_user_id = $request->user()->id;
                }

                $fresh->invitation_customization = $newCustomization;

                $fresh->save();

                $eventIdForRasterJobs = $fresh->id;

                DB::afterCommit(function () use (
                    $galleryOriginalPathsForJobs,
                    $heroOriginalPathsForJobs,
                    $coupleOriginalPathsForJobs,
                    $eventIdForRasterJobs,
                ): void {
                    foreach ($galleryOriginalPathsForJobs as $originalPath) {
                        ProcessInvitationDesignImageJob::dispatch($eventIdForRasterJobs, $originalPath, 'gallery');
                    }
                    foreach ($heroOriginalPathsForJobs as $originalPath) {
                        ProcessInvitationDesignImageJob::dispatch($eventIdForRasterJobs, $originalPath, 'hero_portrait');
                    }
                    foreach ($coupleOriginalPathsForJobs as $originalPath) {
                        ProcessInvitationDesignImageJob::dispatch($eventIdForRasterJobs, $originalPath, 'couple');
                    }
                });
            });

            // Transaction committed — safe to delete old files now.
            // Done synchronously here rather than in DB::afterCommit so the cleanup
            // is not silently lost if the process dies before the after-commit hook runs.
            foreach (array_unique(array_filter($pathsToDelete)) as $path) {
                if (! Storage::disk('public')->delete($path)) {
                    Log::warning('invitation.media_delete_failed', ['path' => $path]);
                }
            }

            Log::info('invitation_design.updated', [
                'event_id' => $event->id,
                'user_id' => $request->user()->id,
                'gallery_uploads' => count($request->file('gallery_images') ?: []),
                'gallery_removals' => count($validated['gallery_remove'] ?? []),
                'hero_portrait_upload' => $request->hasFile('invitation_hero_portrait'),
                'couple_uploads' => count($request->file('couple_photos') ?: []),
                'cleared_video' => $request->boolean('clear_video'),
                'cleared_audio' => $request->boolean('clear_audio'),
                'has_new_video_upload' => $request->hasFile('video_background'),
                'has_new_audio_upload' => $request->hasFile('audio_track'),
            ]);
        } catch (\Throwable $e) {
            Log::warning('invitation_design.update_failed', [
                'event_id' => $event->id,
                'user_id' => $request->user()->id,
                'exception_class' => $e::class,
            ]);

            foreach ($uploadedPaths as $path) {
                Storage::disk('public')->delete($path);
            }

            throw $e;
        }

        return back()->with('status', 'invitation-design-saved');
    }

    /**
     * Store the uploaded binary as-is; {@see ProcessInvitationDesignImageJob} converts to WebP asynchronously.
     */
    private function storeInvitationRasterOriginal(UploadedFile $file, int $eventId, string $directoryPrefix, string $namePrefix): string
    {
        $dir = $directoryPrefix.'/'.$eventId;
        $ext = strtolower((string) ($file->guessExtension() ?: $file->getClientOriginalExtension() ?: 'jpg'));
        if (! in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
            $ext = 'jpg';
        }
        $entropy = str_replace('.', '_', uniqid('', true));
        $name = $namePrefix.$entropy.'.'.$ext;

        return $file->storeAs($dir, $name, 'public');
    }

    private function storeMediaUpload(UploadedFile $file, int $eventId, string $prefix): string
    {
        $dir = 'invitation-media/'.$eventId;
        $ext = $file->guessExtension() ?: $file->getClientOriginalExtension()
            ?: throw new \RuntimeException('Could not determine extension for uploaded media file.');
        $entropy = str_replace('.', '_', uniqid('', true));
        $name = $prefix.'_'.$entropy.'.'.$ext;

        return $file->storeAs($dir, $name, 'public');
    }
}

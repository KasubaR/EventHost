<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateInvitationDesignRequest;
use App\Jobs\ProcessInvitationDesignImageJob;
use App\Models\Event;
use App\Models\StagedMedia;
use App\Services\InvitationCustomizationService;
use App\Support\InvitationCustomizationPersistenceValidator;
use App\Support\InvitationLayoutVariant;
use App\Support\InvitationMediaStager;
use App\Support\InvitationPalettes;
use App\Support\InvitationVideoBackground;
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

                // Files the browser already uploaded while the user was still editing.
                // Scoped to event *and* user, so an id lifted from another session
                // resolves to nothing rather than to someone else's photo.
                $stagedIds = array_values(array_unique(array_map('intval', $validated['staged_media'] ?? [])));
                $stagedBySlot = ($stagedIds === []
                    ? collect()
                    : StagedMedia::query()
                        ->ownedBy($fresh->id, $request->user()->id)
                        ->whereIn('id', $stagedIds)
                        ->lockForUpdate()
                        ->get())
                    ->groupBy('slot');

                $consumedStagedIds = [];

                /**
                 * Claim every staged file for a slot and return its paths. The row is
                 * marked consumed whether or not the branch below ends up using the
                 * path — a caller that discards one must add it to $pathsToDelete,
                 * or the file is orphaned the moment its row goes away.
                 *
                 * @return list<string>
                 */
                $takeStaged = function (string $slot) use ($stagedBySlot, &$consumedStagedIds): array {
                    $paths = [];
                    foreach ($stagedBySlot->get($slot, collect()) as $row) {
                        $consumedStagedIds[] = $row->id;
                        $paths[] = $row->path;
                    }

                    return $paths;
                };

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
                if ($request->boolean('clear_video')) {
                    if ($videoToKeep !== null && InvitationVideoBackground::isFilePath($videoToKeep)) {
                        $pathsToDelete[] = $videoToKeep;
                    }
                    $videoToKeep = null;
                }

                $stagedAudio = $takeStaged(StagedMedia::SLOT_AUDIO);

                $audioToKeep = $prevEffects['audio_track'] ?? null;
                if ($request->boolean('clear_audio')) {
                    // An explicit Remove beats a staged replacement, matching how the
                    // checkbox has always beaten a file input. The staged file is
                    // dropped here rather than left behind without its row.
                    foreach ($stagedAudio as $discarded) {
                        $pathsToDelete[] = $discarded;
                    }
                    $stagedAudio = [];

                    if ($audioToKeep !== null) {
                        $pathsToDelete[] = $audioToKeep;
                        $audioToKeep = null;
                    }
                } elseif (($stagedAudio !== [] || $request->hasFile('audio_track')) && $audioToKeep !== null) {
                    $pathsToDelete[] = $audioToKeep;
                    $audioToKeep = null;
                }

                $newGallery = [];
                $galleryOriginalPathsForJobs = [];

                // Staged paths never join $uploadedPaths: that list is rolled back on
                // failure, and these files must survive a rejected save so the form
                // can be redisplayed with its tiles intact.
                foreach ($takeStaged(StagedMedia::SLOT_GALLERY) as $path) {
                    $newGallery[] = $path;
                    $galleryOriginalPathsForJobs[] = $path;
                }

                foreach ($request->file('gallery_images', []) ?: [] as $file) {
                    if ($file instanceof UploadedFile) {
                        $path = InvitationMediaStager::store($file, StagedMedia::SLOT_GALLERY, $fresh->id);
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
                $priorCoupleRaw = isset($priorCustomization['media']['couple_photos']) && is_array($priorCustomization['media']['couple_photos'])
                    ? $priorCustomization['media']['couple_photos']
                    : [];

                // BFA uses a fixed positional 4-slot array; others use a compact array.
                if ($layoutVariant === InvitationLayoutVariant::BEAUTY_FOR_ASHES) {
                    $priorCouple = [];
                    for ($i = 0; $i < 4; $i++) {
                        $priorCouple[] = (is_string($priorCoupleRaw[$i] ?? null) && $priorCoupleRaw[$i] !== '')
                            ? $priorCoupleRaw[$i]
                            : '';
                    }
                } else {
                    $priorCouple = array_values(array_filter(array_map('strval', $priorCoupleRaw)));
                }

                if ($maxPortrait === 0 && $priorHero !== null) {
                    $pathsToDelete[] = $priorHero;
                }
                if ($maxCouple === 0 && $priorCouple !== []) {
                    foreach ($priorCouple as $p) {
                        if ($p !== '') {
                            $pathsToDelete[] = $p;
                        }
                    }
                }

                $heroPortraitKeep = null;
                $heroOriginalPathsForJobs = [];
                $stagedHero = $takeStaged(StagedMedia::SLOT_HERO_PORTRAIT);

                if ($maxPortrait === 0) {
                    // The layout lost its hero slot between staging and saving.
                    foreach ($stagedHero as $discarded) {
                        $pathsToDelete[] = $discarded;
                    }
                    $stagedHero = [];
                }

                if ($maxPortrait > 0) {
                    $heroPortraitKeep = $priorHero;
                    if ($request->boolean('clear_hero_portrait')) {
                        foreach ($stagedHero as $discarded) {
                            $pathsToDelete[] = $discarded;
                        }
                        $stagedHero = [];

                        if ($heroPortraitKeep !== null) {
                            $pathsToDelete[] = $heroPortraitKeep;
                            $heroPortraitKeep = null;
                        }
                    } elseif ($stagedHero !== []) {
                        if ($heroPortraitKeep !== null) {
                            $pathsToDelete[] = $heroPortraitKeep;
                        }
                        // Single-value slot — staging replaces rather than appends, so
                        // anything past the first is a leftover from a race.
                        $heroPortraitKeep = array_shift($stagedHero);
                        $heroOriginalPathsForJobs[] = $heroPortraitKeep;
                        foreach ($stagedHero as $extra) {
                            $pathsToDelete[] = $extra;
                        }
                    } elseif ($request->hasFile('invitation_hero_portrait')) {
                        $file = $request->file('invitation_hero_portrait');
                        if ($file instanceof UploadedFile) {
                            if ($heroPortraitKeep !== null) {
                                $pathsToDelete[] = $heroPortraitKeep;
                            }
                            $path = InvitationMediaStager::store($file, StagedMedia::SLOT_HERO_PORTRAIT, $fresh->id);
                            $heroPortraitKeep = $path;
                            $uploadedPaths[] = $path;
                            $heroOriginalPathsForJobs[] = $path;
                        }
                    }
                }

                $coupleKeep = [];
                $coupleOriginalPathsForJobs = [];

                if ($maxCouple === 0) {
                    // Layout has no portrait slots — claim and drop anything staged for them.
                    $orphanedPortraitSlots = [StagedMedia::SLOT_COUPLE];
                    for ($i = 0; $i < 4; $i++) {
                        $orphanedPortraitSlots[] = StagedMedia::speakerSlot($i);
                    }
                    foreach ($orphanedPortraitSlots as $slot) {
                        foreach ($takeStaged($slot) as $discarded) {
                            $pathsToDelete[] = $discarded;
                        }
                    }
                }

                if ($maxCouple > 0) {
                    if ($layoutVariant === InvitationLayoutVariant::BEAUTY_FOR_ASHES) {
                        // Per-slot: speaker_photo[i] uploads, speaker_photo_clear[i] clears.
                        for ($i = 0; $i < 4; $i++) {
                            $currentSlot = $priorCouple[$i] ?? '';
                            $clearSlot = (bool) ($validated['speaker_photo_clear'][$i] ?? false);
                            $stagedSlot = $takeStaged(StagedMedia::speakerSlot($i));

                            if ($clearSlot && $currentSlot !== '') {
                                $pathsToDelete[] = $currentSlot;
                                foreach ($stagedSlot as $discarded) {
                                    $pathsToDelete[] = $discarded;
                                }
                                $coupleKeep[] = '';
                            } elseif ($stagedSlot !== []) {
                                if ($currentSlot !== '') {
                                    $pathsToDelete[] = $currentSlot;
                                }
                                $path = array_shift($stagedSlot);
                                foreach ($stagedSlot as $extra) {
                                    $pathsToDelete[] = $extra;
                                }
                                $coupleKeep[] = $path;
                                $coupleOriginalPathsForJobs[] = $path;
                            } elseif ($request->hasFile("speaker_photo.$i")) {
                                $file = $request->file("speaker_photo.$i");
                                if ($file instanceof UploadedFile) {
                                    if ($currentSlot !== '') {
                                        $pathsToDelete[] = $currentSlot;
                                    }
                                    $path = InvitationMediaStager::store($file, StagedMedia::SLOT_COUPLE, $fresh->id);
                                    $coupleKeep[] = $path;
                                    $uploadedPaths[] = $path;
                                    $coupleOriginalPathsForJobs[] = $path;
                                } else {
                                    $coupleKeep[] = $currentSlot;
                                }
                            } else {
                                $coupleKeep[] = $currentSlot;
                            }
                        }

                        // Nothing addresses the batch slot on this layout; drop any stray.
                        foreach ($takeStaged(StagedMedia::SLOT_COUPLE) as $discarded) {
                            $pathsToDelete[] = $discarded;
                        }
                    } else {
                        // Batch upload for other templates (e.g. botanical_graduation).
                        $coupleRemove = array_values(array_intersect($validated['couple_remove'] ?? [], $priorCouple));
                        foreach ($coupleRemove as $p) {
                            $pathsToDelete[] = $p;
                        }
                        $coupleKeep = array_values(array_diff($priorCouple, $coupleRemove));

                        foreach ($takeStaged(StagedMedia::SLOT_COUPLE) as $path) {
                            $coupleKeep[] = $path;
                            $coupleOriginalPathsForJobs[] = $path;
                        }

                        foreach ($request->file('couple_photos', []) ?: [] as $file) {
                            if ($file instanceof UploadedFile) {
                                $path = InvitationMediaStager::store($file, StagedMedia::SLOT_COUPLE, $fresh->id);
                                $coupleKeep[] = $path;
                                $uploadedPaths[] = $path;
                                $coupleOriginalPathsForJobs[] = $path;
                            }
                        }

                        // Numbered slots belong to Beauty for Ashes only.
                        for ($i = 0; $i < 4; $i++) {
                            foreach ($takeStaged(StagedMedia::speakerSlot($i)) as $discarded) {
                                $pathsToDelete[] = $discarded;
                            }
                        }
                    }
                }

                $youtubeRaw = isset($validated['video_background_youtube'])
                    ? trim((string) $validated['video_background_youtube'])
                    : '';

                if ($youtubeRaw !== '') {
                    $normalized = InvitationVideoBackground::normalizeUserInput($youtubeRaw);
                    $videoPath = $normalized ?? $videoToKeep;
                    if ($normalized !== null && $videoToKeep !== null && InvitationVideoBackground::isFilePath($videoToKeep)) {
                        $pathsToDelete[] = $videoToKeep;
                    }
                } else {
                    $videoPath = $videoToKeep;
                }

                $audioPath = $audioToKeep;
                if ($stagedAudio !== []) {
                    $audioPath = array_shift($stagedAudio);
                    foreach ($stagedAudio as $extra) {
                        $pathsToDelete[] = $extra;
                    }
                } elseif ($request->hasFile('audio_track')) {
                    $audioPath = InvitationMediaStager::store($request->file('audio_track'), StagedMedia::SLOT_AUDIO, $fresh->id);
                    $uploadedPaths[] = $audioPath;
                }

                // Coerce empty strings to null — the persistence validator's mediaPathRule
                // closure skips '' (same as null), so without this an empty string written
                // by a prior bug would persist and be read as truthy by downstream code.
                $videoPath = $videoPath ?: null;
                $audioPath = $audioPath ?: null;

                // Colours come from the curated catalogue rather than free-form hex, so an
                // unreadable combination cannot reach a public invitation. Layouts that
                // ignore the theme variables submit no palette and keep what they have.
                $storedTheme = $fresh->invitation_customization['theme'] ?? [];
                $palette = InvitationPalettes::get((string) ($validated['theme_palette'] ?? ''));
                $themeColours = $palette !== null
                    ? [
                        'palette_key' => (string) $validated['theme_palette'],
                        'primary' => $palette['primary'],
                        'accent' => $palette['accent'],
                        'background' => $palette['background'],
                    ]
                    : [
                        'palette_key' => $storedTheme['palette_key'] ?? null,
                        'primary' => $storedTheme['primary'] ?? ($template->default_theme['primary'] ?? '#1a2a4a'),
                        'accent' => $storedTheme['accent'] ?? ($template->default_theme['accent'] ?? '#1e47bb'),
                        'background' => $storedTheme['background'] ?? ($template->default_theme['background'] ?? '#fafafa'),
                    ];

                $newCustomization = [
                    'schema_version' => InvitationCustomizationService::CURRENT_SCHEMA_VERSION,
                    'theme' => $themeColours + [
                        'font_heading_key' => $validated['font_heading_key'],
                        'font_body_key' => $validated['font_body_key'],
                    ],
                    'sections' => $sections,
                    'content' => [
                        'story' => (string) ($validated['content_story'] ?? ''),
                        'schedule' => array_values($validated['schedule_items']),
                        'speaker_cards' => InvitationCustomizationService::normalizeSpeakerCards($validated['speaker_cards'] ?? []),
                        'venue_note' => (string) ($validated['venue_note'] ?? ''),
                        'bfa_conference_theme' => (string) ($validated['bfa_conference_theme'] ?? ''),
                        'bfa_dress_code' => (string) ($validated['bfa_dress_code'] ?? ''),
                        'bfa_presenter_line' => (string) ($validated['bfa_presenter_line'] ?? ''),
                        'bfa_presents_line' => (string) ($validated['bfa_presents_line'] ?? ''),
                        'bfa_tagline_bar' => (string) ($validated['bfa_tagline_bar'] ?? ''),
                        'bfa_tagline_quote' => (string) ($validated['bfa_tagline_quote'] ?? ''),
                        'bfa_host_slot' => (int) ($validated['bfa_host_slot'] ?? 1),
                        'contact_phone_primary' => (string) ($validated['contact_phone_primary'] ?? ''),
                        'contact_phone_secondary' => (string) ($validated['contact_phone_secondary'] ?? ''),
                        'ei_color_theme' => (string) ($validated['ei_color_theme'] ?? ''),
                        'ei_guest_speaker' => (string) ($validated['ei_guest_speaker'] ?? ''),
                        'ei_mc' => (string) ($validated['ei_mc'] ?? ''),
                        'wi_hero_eyebrow' => (string) ($validated['wi_hero_eyebrow'] ?? ''),
                        'wi_couple_caption' => (string) ($validated['wi_couple_caption'] ?? ''),
                        'wi_footer_quote' => (string) ($validated['wi_footer_quote'] ?? ''),
                        'wi2_hero_tag' => (string) ($validated['wi2_hero_tag'] ?? ''),
                        'wi2_invite_formal' => (string) ($validated['wi2_invite_formal'] ?? ''),
                        'wi2_invite_body' => (string) ($validated['wi2_invite_body'] ?? ''),
                        'wi2_photo_quote' => (string) ($validated['wi2_photo_quote'] ?? ''),
                        'wi2_photo_quote_cite' => (string) ($validated['wi2_photo_quote_cite'] ?? ''),
                        'wi2_footer_monogram' => (string) ($validated['wi2_footer_monogram'] ?? ''),
                        'wi2_footer_legal' => (string) ($validated['wi2_footer_legal'] ?? ''),
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
                    'rsvp_form' => $validated['rsvp_form'],
                ];

                InvitationCustomizationPersistenceValidator::validate($newCustomization);

                if ($priorCustomization !== []) {
                    $fresh->invitation_customization_previous = $priorCustomization;
                    $fresh->invitation_customization_previous_captured_at = now();
                    $fresh->invitation_customization_previous_captured_by_user_id = $request->user()->id;
                }

                $fresh->invitation_customization = $newCustomization;

                $fresh->save();

                // Inside the transaction on purpose: if the save rolls back, the rows
                // survive and the redisplayed form still knows about its uploads.
                if ($consumedStagedIds !== []) {
                    StagedMedia::query()
                        ->whereIn('id', array_values(array_unique($consumedStagedIds)))
                        ->delete();
                }

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
                'staged_media_consumed' => count($validated['staged_media'] ?? []),
                'gallery_removals' => count($validated['gallery_remove'] ?? []),
                'hero_portrait_upload' => $request->hasFile('invitation_hero_portrait'),
                'couple_uploads' => count($request->file('couple_photos') ?: []),
                'cleared_video' => $request->boolean('clear_video'),
                'cleared_audio' => $request->boolean('clear_audio'),
                'video_background_youtube_set' => trim((string) ($validated['video_background_youtube'] ?? '')) !== '',
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
}

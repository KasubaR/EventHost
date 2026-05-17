<?php

namespace App\Console\Commands;

use App\Models\Event;
use App\Support\InvitationVideoBackground;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Deletes invitation media files that are no longer referenced in any event's
 * invitation_customization (or its previous snapshot), and are older than a
 * configurable grace window.
 *
 * The grace window protects files that were written inside an open transaction
 * (or dispatched to a queued job) but whose DB row has not yet been committed.
 * Default: 60 minutes.
 *
 * Directories scanned on the 'public' disk:
 *   invitation-gallery/{event_id}/   — gallery images and gal_src_* originals
 *   invitation-hero/{event_id}/       — optional hero portrait overrides and hero_src_* originals
 *   invitation-couple/{event_id}/     — optional couple portrait uploads and couple_src_* originals
 *   invitation-media/{event_id}/       — video and audio uploads
 */
class PruneOrphanedInvitationFilesCommand extends Command
{
    protected $signature = 'invitation:prune-orphaned-files
                            {--grace=60 : Minimum age in minutes before a file is considered orphaned}
                            {--dry-run  : List orphans without deleting them}';

    protected $description = 'Delete orphaned invitation media files not referenced in any event customization';

    public function handle(): int
    {
        $grace = max(1, (int) $this->option('grace'));
        $dryRun = (bool) $this->option('dry-run');
        $cutoff = now()->subMinutes($grace);

        $this->line("Grace window: {$grace} min | Dry run: ".($dryRun ? 'yes' : 'no'));

        // Build the complete set of referenced paths from every event's
        // active and previous customization blobs.
        $referenced = $this->buildReferencedPathSet();
        $this->line('Referenced paths across all events: '.count($referenced));

        $disk = Storage::disk('public');
        $deleted = 0;
        $errors = 0;

        foreach (['invitation-gallery', 'invitation-hero', 'invitation-couple', 'invitation-media'] as $baseDir) {
            if (! $disk->exists($baseDir)) {
                continue;
            }

            foreach ($disk->allFiles($baseDir) as $path) {
                if (isset($referenced[$path])) {
                    continue;
                }

                // Check age via last-modified timestamp.
                $lastModified = $disk->lastModified($path);
                if ($lastModified === false || $lastModified > $cutoff->timestamp) {
                    continue; // Too new — still within grace window.
                }

                if ($dryRun) {
                    $this->line("[dry-run] would delete: {$path}");
                    $deleted++;

                    continue;
                }

                if ($disk->delete($path)) {
                    Log::info('invitation.orphan_pruned', ['path' => $path]);
                    $deleted++;
                } else {
                    Log::warning('invitation.orphan_prune_failed', ['path' => $path]);
                    $this->warn("Failed to delete: {$path}");
                    $errors++;
                }
            }
        }

        $label = $dryRun ? 'Orphans found' : 'Orphans deleted';
        $this->info("{$label}: {$deleted}".($errors > 0 ? " | Errors: {$errors}" : ''));

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return array<string, true>
     */
    private function buildReferencedPathSet(): array
    {
        $set = [];

        Event::query()
            ->whereNotNull('invitation_customization')
            ->orWhereNotNull('invitation_customization_previous')
            ->select(['invitation_customization', 'invitation_customization_previous'])
            ->chunk(200, function ($events) use (&$set): void {
                foreach ($events as $event) {
                    $this->extractPaths($event->invitation_customization, $set);
                    $this->extractPaths($event->invitation_customization_previous, $set);
                }
            });

        return $set;
    }

    /**
     * @param  mixed  $customization  Already-decoded value from the JSON cast.
     * @param  array<string, true>  $set
     */
    private function extractPaths(mixed $customization, array &$set): void
    {
        if (! is_array($customization)) {
            return;
        }

        foreach ($customization['media']['gallery'] ?? [] as $path) {
            if (is_string($path) && $path !== '') {
                $set[$path] = true;
            }
        }

        $hero = $customization['media']['hero_portrait'] ?? null;
        if (is_string($hero) && $hero !== '') {
            $set[$hero] = true;
        }

        foreach ($customization['media']['couple_photos'] ?? [] as $path) {
            if (is_string($path) && $path !== '') {
                $set[$path] = true;
            }
        }

        $effects = $customization['effects'] ?? [];
        foreach (['video_background', 'audio_track'] as $key) {
            $val = $effects[$key] ?? null;
            if ($key === 'video_background' && InvitationVideoBackground::isYoutube(is_string($val) ? $val : null)) {
                continue;
            }
            if (is_string($val) && $val !== '') {
                $set[$val] = true;
            }
        }
    }
}

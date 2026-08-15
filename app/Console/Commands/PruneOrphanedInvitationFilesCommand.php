<?php

namespace App\Console\Commands;

use App\Models\Event;
use App\Models\StagedMedia;
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
 *
 * Staged uploads live in those same directories before the save that references
 * them, so a live staged_media row counts as a reference — without that, a user
 * who picks photos and then goes to lunch loses them an hour later. The opposite
 * sweep runs here too: a staged row nobody saved is abandoned after its TTL, and
 * both the row and its file go.
 */
class PruneOrphanedInvitationFilesCommand extends Command
{
    protected $signature = 'invitation:prune-orphaned-files
                            {--grace=60 : Minimum age in minutes before a file is considered orphaned}
                            {--staged-ttl= : Minutes before an unsaved staged upload is abandoned (default: config)}
                            {--dry-run  : List orphans without deleting them}';

    protected $description = 'Delete orphaned invitation media files not referenced in any event customization';

    public function handle(): int
    {
        $grace = max(1, (int) $this->option('grace'));
        $dryRun = (bool) $this->option('dry-run');
        $cutoff = now()->subMinutes($grace);

        $this->line("Grace window: {$grace} min | Dry run: ".($dryRun ? 'yes' : 'no'));

        // Expire abandoned staged rows first, so files they were protecting become
        // eligible for the orphan sweep below in the same run.
        $this->pruneAbandonedStagedMedia($dryRun);

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
     * A staged upload whose form was never saved. The TTL is generous because the
     * cost of being wrong is a user losing photos they can see on screen.
     */
    private function pruneAbandonedStagedMedia(bool $dryRun): void
    {
        $ttl = $this->option('staged-ttl') !== null
            ? max(1, (int) $this->option('staged-ttl'))
            : max(1, (int) config('invitations.staged_media_ttl_minutes', 1440));

        $expired = StagedMedia::query()
            ->where('created_at', '<', now()->subMinutes($ttl))
            ->get();

        if ($expired->isEmpty()) {
            $this->line("Abandoned staged uploads (TTL {$ttl} min): 0");

            return;
        }

        foreach ($expired as $row) {
            if ($dryRun) {
                $this->line("[dry-run] would abandon staged #{$row->id}: {$row->path}");

                continue;
            }

            // Row first: while it exists the path counts as referenced, so a failure
            // between the two leaves a protected file rather than a broken reference.
            $path = $row->path;
            $row->delete();

            if (! Storage::disk('public')->delete($path)) {
                Log::warning('invitation.staged_prune_failed', ['path' => $path]);
            }
        }

        Log::info('invitation.staged_pruned', ['count' => $expired->count(), 'ttl_minutes' => $ttl]);
        $this->line("Abandoned staged uploads (TTL {$ttl} min): ".$expired->count());
    }

    /**
     * @return array<string, true>
     */
    private function buildReferencedPathSet(): array
    {
        $set = [];

        // Live staged uploads are on disk but not yet in any customization blob.
        StagedMedia::query()
            ->select(['path'])
            ->chunk(500, function ($rows) use (&$set): void {
                foreach ($rows as $row) {
                    if (is_string($row->path) && $row->path !== '') {
                        $set[$row->path] = true;
                    }
                }
            });

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

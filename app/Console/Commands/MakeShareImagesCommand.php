<?php

namespace App\Console\Commands;

use App\Jobs\GenerateEventShareImageJob;
use App\Models\Event;
use App\Services\InvitationCustomizationService;
use App\Support\InvitationShareImage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Writes the link-preview JPEG for events that were saved before share images existed. New and edited events get theirs from
 * GenerateEventShareImageJob. Safe to re-run: an event whose JPEG already exists is skipped.
 * plans/invitation-page-compatibility.md Phase 5.
 */
class MakeShareImagesCommand extends Command
{
    protected $signature = 'invitation:make-share-images
        {--dry-run : List what would be written without writing it}';

    protected $description = 'Create the link-preview JPEG (1200x630) for existing invitation events';

    public function handle(InvitationCustomizationService $customization): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $disk = Storage::disk('public');
        $written = 0;
        $skipped = 0;

        Event::query()
            ->where('is_published', true)
            ->chunkById(100, function ($events) use ($customization, $dryRun, $disk, &$written, &$skipped): void {
                foreach ($events as $event) {
                    if ($event->isTicketed()) {
                        continue;
                    }

                    $media = InvitationCustomizationService::storedMedia($customization->storedCustomization($event));
                    $source = InvitationShareImage::sourcePath($event, $media);
                    $target = $source !== null ? InvitationShareImage::pathFor($event->id, $source) : null;

                    if ($target === null || $disk->exists($target)) {
                        $skipped++;

                        continue;
                    }

                    if ($dryRun) {
                        $this->line("[dry-run] would write: {$target}");
                    } else {
                        (new GenerateEventShareImageJob($event->id))->handle();
                    }
                    $written++;
                }
            });

        $label = $dryRun ? 'Would write' : 'Written';
        $this->info("{$label}: {$written} | Skipped: {$skipped}");

        return self::SUCCESS;
    }
}

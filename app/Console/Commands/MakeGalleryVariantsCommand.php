<?php

namespace App\Console\Commands;

use App\Models\Event;
use App\Support\InvitationMediaUrl;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;
use Throwable;

/**
 * Writes the small (600px) copy of every gallery photo saved before small copies existed, so phones stop downloading
 * the 1200px file. New photos get theirs from ProcessInvitationDesignImageJob. Safe to re-run: a photo that already
 * has its copy, or is not wider than 600px, is skipped. plans/invitation-page-resilience.md Phase 5.
 */
class MakeGalleryVariantsCommand extends Command
{
    protected $signature = 'invitation:make-gallery-variants
        {--dry-run : List what would be written without writing it}';

    protected $description = 'Create the small (600px) copy of existing invitation gallery photos';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $disk = Storage::disk('public');
        $manager = extension_loaded('imagick') ? ImageManager::imagick() : ImageManager::gd();

        $written = 0;
        $skipped = 0;
        $failed = 0;

        Event::query()
            ->withTrashed()
            ->whereNotNull('invitation_customization')
            ->select(['id', 'invitation_customization'])
            ->chunkById(100, function ($events) use ($disk, $manager, $dryRun, &$written, &$skipped, &$failed): void {
                foreach ($events as $event) {
                    $gallery = $event->invitation_customization['media']['gallery'] ?? [];
                    if (! is_array($gallery)) {
                        continue;
                    }

                    foreach ($gallery as $path) {
                        if (! is_string($path)) {
                            continue;
                        }

                        $variant = InvitationMediaUrl::variantName($path);
                        if ($variant === null || ! $disk->exists($path) || $disk->exists($variant)) {
                            $skipped++;

                            continue;
                        }

                        try {
                            $image = $manager->read($disk->get($path));
                            if ($image->width() <= InvitationMediaUrl::SMALL_WIDTH) {
                                $skipped++;

                                continue;
                            }

                            if ($dryRun) {
                                $this->line("[dry-run] would write: {$variant}");
                                $written++;

                                continue;
                            }

                            $image->scaleDown(width: InvitationMediaUrl::SMALL_WIDTH);
                            $disk->put($variant, $image->toWebp(80)->toString());
                            $written++;
                        } catch (Throwable $e) {
                            Log::warning('invitation.gallery_variant_backfill_failed', ['event_id' => $event->id, 'path' => $path, 'exception_class' => $e::class]);
                            $this->warn("Failed: {$path}");
                            $failed++;
                        }
                    }
                }
            });

        $label = $dryRun ? 'Would write' : 'Written';
        $this->info("{$label}: {$written} | Skipped: {$skipped}".($failed > 0 ? " | Failed: {$failed}" : ''));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}

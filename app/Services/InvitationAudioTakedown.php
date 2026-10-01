<?php

namespace App\Services;

use App\Models\Event;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Removes the background music from an invitation. The one place that does it, so the admin event
 * page and the copyright-report queue cannot drift. Idempotent: an event with no track is a no-op.
 */
class InvitationAudioTakedown
{
    /**
     * @return bool true when a track was removed
     */
    public function remove(Event $event): bool
    {
        $path = null;

        DB::transaction(function () use ($event, &$path): void {
            $locked = Event::query()->withTrashed()->lockForUpdate()->find($event->id);
            if ($locked === null) {
                return;
            }

            $customization = $locked->invitation_customization ?? [];
            $path = $customization['effects']['audio_track'] ?? null;
            if (! is_string($path) || $path === '') {
                $path = null;
            }

            // The previous-version snapshot is what "revert" restores, so a revert would bring the
            // removed track straight back.
            $previous = $locked->invitation_customization_previous;
            $previousHadIt = is_array($previous) && ($previous['effects']['audio_track'] ?? null) !== null;

            if ($path === null && ! $previousHadIt) {
                return;
            }

            if ($path !== null) {
                $customization['effects']['audio_track'] = null;
                $locked->invitation_customization = $customization;
            }
            if ($previousHadIt) {
                $previous['effects']['audio_track'] = null;
                $locked->invitation_customization_previous = $previous;
            }
            $locked->save();
        });

        if ($path !== null) {
            try {
                Storage::disk('public')->delete($path);
            } catch (\Throwable $e) {
                Log::warning('invitation.audio_takedown_file_failed', ['path' => $path, 'exception' => $e->getMessage()]);
            }
        }

        $event->refresh();

        return $path !== null;
    }
}

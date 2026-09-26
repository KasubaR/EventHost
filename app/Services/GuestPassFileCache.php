<?php

namespace App\Services;

use App\Models\Guest;
use App\Support\GuestPassCard;
use Closure;
use Illuminate\Support\Facades\Storage;

/**
 * Disk cache shared by the guest-pass PDF and PNG renderers.
 *
 * Files live on the private local disk, one directory per invitation token, named
 * by GuestPassCard::fingerprint(): `{root}/{token}/{fingerprint}.{ext}`. Whatever
 * is printed on the card can change after the first render (table assigned,
 * plus-one edited, event moved, guest checked in), so a changed card is a new
 * path, never a stale hit — and writing it drops the guest's older files, so the
 * directory holds one file per format, not one per edit.
 *
 * The PDF and the image are cached under separate roots but keyed on the same
 * fingerprint, so the two can never disagree about what the card says.
 *
 * Bump a root's version suffix when that renderer's layout changes: the
 * fingerprint covers content, not layout.
 */
class GuestPassFileCache
{
    public const PDF = 'guest-pass-pdfs/v1';

    public const IMAGE = 'guest-pass-images/v1';

    /** Every root, for the orphan sweep in invitation:prune-orphaned-files. */
    public const ROOTS = [self::PDF, self::IMAGE];

    /**
     * @param  Closure(): string  $make  Produces the file's bytes on a miss.
     */
    public function remember(string $root, Guest $guest, GuestPassCard $card, string $extension, Closure $make): string
    {
        $disk = Storage::disk('local');
        $directory = $this->directory($root, (string) $guest->invitation_token);
        $path = $directory.'/'.$card->fingerprint().'.'.$extension;

        if ($disk->exists($path)) {
            return (string) $disk->get($path);
        }

        $binary = $make();

        foreach ($disk->files($directory) as $stale) {
            $disk->delete($stale);
        }

        $disk->put($path, $binary);

        return $binary;
    }

    public function path(string $root, Guest $guest, GuestPassCard $card, string $extension): string
    {
        return $this->directory($root, (string) $guest->invitation_token).'/'.$card->fingerprint().'.'.$extension;
    }

    public function directory(string $root, string $token): string
    {
        // Tokens are random alphanumerics; this only guarantees nothing odd can reach a path.
        return $root.'/'.(preg_replace('/[^A-Za-z0-9_-]/', '', $token) ?? '');
    }
}

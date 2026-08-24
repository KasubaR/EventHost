<?php

namespace App\Services;

use App\Http\Requests\UpdateProfileRequest;
use App\Models\User;
use App\Notifications\EmailChangedNotification;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Intervention\Image\ImageManager;

class ProfileService
{
    public function update(User $user, UpdateProfileRequest $request): User
    {
        $newProfilePhotoPath = null;

        try {
            return DB::transaction(function () use ($user, $request, &$newProfilePhotoPath) {
                $data = $request->safe()->except(['profile_photo']);
                $oldEmail = $user->email;

                $emailChanged = ($data['email'] ?? $oldEmail) !== $oldEmail;

                $previousProfilePhoto = null;
                if ($request->hasFile('profile_photo')) {
                    $previousProfilePhoto = $user->profile_photo;
                    $newProfilePhotoPath = $this->storePhoto($request->file('profile_photo'), $user);
                    $data['profile_photo'] = $newProfilePhotoPath;
                }

                $user->fill($data);

                if ($emailChanged) {
                    $user->email_verified_at = null;
                }

                $user->save();

                if ($previousProfilePhoto) {
                    DB::afterCommit(function () use ($previousProfilePhoto) {
                        Storage::disk('public')->delete($previousProfilePhoto);
                    });
                }

                if ($emailChanged) {
                    $user->sendEmailVerificationNotification();
                    Notification::route('mail', $oldEmail)->notify(new EmailChangedNotification($user->name, $user->email));
                }

                return $user->fresh();
            });
        } catch (\Throwable $e) {
            if ($newProfilePhotoPath !== null) {
                Storage::disk('public')->delete($newProfilePhotoPath);
            }

            throw $e;
        }
    }

    /**
     * There is no way back to the default avatar once a photo is uploaded —
     * "Change photo" only ever replaces. This clears the column and drops
     * the stored file, same cleanup ProfileService::update() already does
     * for a replaced photo.
     */
    public function removePhoto(User $user): User
    {
        $currentPhoto = $user->profile_photo;

        if ($currentPhoto === null) {
            return $user;
        }

        $user->forceFill(['profile_photo' => null])->save();

        Storage::disk('public')->delete($currentPhoto);

        return $user;
    }

    /**
     * Merge submitted toggles over the stored set. Only keys actually present
     * are overwritten, so a preference added to the defaults later keeps its
     * default for existing users instead of silently becoming false.
     *
     * @param  array<string, bool>  $submitted
     */
    public function updateNotificationPreferences(User $user, array $submitted): User
    {
        $prefs = $user->notification_preferences ?? User::defaultNotificationPreferences();

        foreach (array_keys(User::defaultNotificationPreferences()) as $key) {
            if (array_key_exists($key, $submitted)) {
                $prefs[$key] = (bool) $submitted[$key];
            }
        }

        $user->notification_preferences = $prefs;
        $user->save();

        return $user;
    }

    private function storePhoto(UploadedFile $file, User $user): string
    {
        $manager = extension_loaded('imagick')
            ? ImageManager::imagick()
            : ImageManager::gd();

        // The 'image'/'mimes'/'dimensions' rules on profile_photo only check
        // the declared mime type and header dimensions — a file that passes
        // those can still be corrupt, truncated, or use a codec the current
        // driver can't decode (e.g. GD without WebP support). Without this,
        // that failure was an uncaught RuntimeException bubbling into a bare
        // 500 instead of a normal, field-level validation error.
        try {
            $image = $manager->read($file->getRealPath());
            $image->cover(400, 400);
            $webp = $image->toWebp(85);
        } catch (\RuntimeException $e) {
            throw ValidationException::withMessages([
                'profile_photo' => 'We couldn\'t process that image. Please try a different file.',
            ]);
        }

        $path = 'profile-photos/'.$user->id.'-'.time().'.webp';
        Storage::disk('public')->put($path, $webp->toString());

        return $path;
    }
}

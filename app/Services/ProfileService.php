<?php

namespace App\Services;

use App\Http\Requests\UpdateProfileRequest;
use App\Models\User;
use App\Notifications\EmailChangedNotification;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;

class ProfileService
{
    public function update(User $user, UpdateProfileRequest $request): User
    {
        $newProfilePhotoPath = null;

        try {
            return DB::transaction(function () use ($user, $request, &$newProfilePhotoPath) {
                $data = $request->safe()->except(['profile_photo', 'notification_preferences']);
                $oldEmail = $user->email;

                $prefs = $user->notification_preferences ?? User::defaultNotificationPreferences();
                $inputPrefs = $request->input('notification_preferences');
                if (is_array($inputPrefs)) {
                    foreach (array_keys(User::defaultNotificationPreferences()) as $key) {
                        if (array_key_exists($key, $inputPrefs)) {
                            $prefs[$key] = (bool) $inputPrefs[$key];
                        }
                    }
                }
                $data['notification_preferences'] = $prefs;

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

    private function storePhoto(UploadedFile $file, User $user): string
    {
        $manager = extension_loaded('imagick')
            ? ImageManager::imagick()
            : ImageManager::gd();
        $image = $manager->read($file->getRealPath());
        $image->cover(400, 400);
        $webp = $image->toWebp(85);

        $path = 'profile-photos/'.$user->id.'-'.time().'.webp';
        Storage::disk('public')->put($path, $webp->toString());

        return $path;
    }
}

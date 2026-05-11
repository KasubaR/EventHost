<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdatePlatformSettingsRequest;
use App\Models\PlatformSetting;
use App\Support\AdminActivity;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class SettingController extends Controller
{
    private const KEY_SITE_NAME = 'site_name';

    private const KEY_WHATSAPP_DEFAULT = 'whatsapp_default_message';

    public function edit(): View
    {
        return view('admin.settings.edit', [
            'siteName' => PlatformSetting::getValue(self::KEY_SITE_NAME, config('app.name')),
            'whatsappDefaultMessage' => PlatformSetting::getValue(self::KEY_WHATSAPP_DEFAULT, ''),
        ]);
    }

    public function update(UpdatePlatformSettingsRequest $request): RedirectResponse
    {
        $data = $request->validated();

        PlatformSetting::setValue(self::KEY_SITE_NAME, $data['site_name'], 'string');
        PlatformSetting::setValue(
            self::KEY_WHATSAPP_DEFAULT,
            $data['whatsapp_default_message'] ?? '',
            'string'
        );

        AdminActivity::log('Admin updated platform settings', [
            'keys' => [self::KEY_SITE_NAME, self::KEY_WHATSAPP_DEFAULT],
        ]);

        return redirect()->route('admin.settings.edit')->with('status', 'settings-updated');
    }
}

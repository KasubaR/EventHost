<?php

namespace Tests\Unit\Providers;

use App\Services\NullWhatsAppService;
use App\Services\WhatsAppService;
use Tests\TestCase;

class WhatsAppServiceBindingTest extends TestCase
{
    public function test_binds_null_when_whatsapp_is_disabled(): void
    {
        config([
            'communications.whatsapp.enabled' => false,
            'services.twilio.account_sid' => 'ACxxxxxxxx',
            'services.twilio.api_key_sid' => 'SKxxxxxxxx',
            'services.twilio.api_key_secret' => 'secret',
            'services.twilio.whatsapp_from' => 'whatsapp:+260970000000',
        ]);

        $this->app->forgetInstance(WhatsAppService::class);

        $this->assertInstanceOf(NullWhatsAppService::class, $this->app->make(WhatsAppService::class));
    }

    public function test_binds_null_when_credentials_are_incomplete(): void
    {
        config([
            'communications.whatsapp.enabled' => true,
            'services.twilio.account_sid' => 'ACxxxxxxxx',
            'services.twilio.api_key_sid' => null,
            'services.twilio.api_key_secret' => 'secret',
            'services.twilio.whatsapp_from' => 'whatsapp:+260970000000',
        ]);

        $this->app->forgetInstance(WhatsAppService::class);

        $this->assertInstanceOf(NullWhatsAppService::class, $this->app->make(WhatsAppService::class));
    }
}

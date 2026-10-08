<?php

namespace Tests\Unit\Support;

use App\Support\GuestPhone;
use App\Support\WhatsAppInviteLink;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class GuestPhoneTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function acceptedNumbers(): array
    {
        return [
            'local mobile' => ['0971234567'],
            'local with dashes' => ['097-123-4567'],
            'country code spaced' => ['+260 97 123 4567'],
            'country code without plus' => ['260971234567'],
            'double zero country code' => ['00260971234567'],
            'subscriber number only' => ['971234567'],
            'landline' => ['0211234567'],
            'international' => ['+44 7700 900123'],
            'international with 00' => ['0044 7700 900123'],
        ];
    }

    #[DataProvider('acceptedNumbers')]
    public function test_accepts_one_dialable_number(string $phone): void
    {
        $this->assertNull(GuestPhone::problem($phone));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function refusedNumbers(): array
    {
        return [
            'two numbers with slash' => ['0971111111 / 0972222222', GuestPhone::MULTIPLE_MESSAGE],
            'two numbers with comma' => ['0971111111, 0972222222', GuestPhone::MULTIPLE_MESSAGE],
            'two numbers with or' => ['0971111111 or 0972222222', GuestPhone::MULTIPLE_MESSAGE],
            'two numbers run together' => ['0971111111 0972222222', GuestPhone::MULTIPLE_MESSAGE],
            'letters' => ['call me', GuestPhone::CHARACTERS_MESSAGE],
            'not applicable' => ['n/a', GuestPhone::CHARACTERS_MESSAGE],
            'one digit short' => ['097123456', GuestPhone::ZAMBIAN_MESSAGE],
            'one digit long' => ['09712345678', GuestPhone::ZAMBIAN_MESSAGE],
            'country code too short' => ['+26097123', GuestPhone::ZAMBIAN_MESSAGE],
            'not a zambian prefix' => ['0571234567', GuestPhone::ZAMBIAN_MESSAGE],
            'short digits' => ['12345', GuestPhone::UNKNOWN_MESSAGE],
            'international too short' => ['+4412', GuestPhone::INTERNATIONAL_MESSAGE],
            'plus only' => ['++260', GuestPhone::CHARACTERS_MESSAGE],
        ];
    }

    #[DataProvider('refusedNumbers')]
    public function test_refuses_with_a_reason(string $phone, string $message): void
    {
        $this->assertSame($message, GuestPhone::problem($phone));
    }

    public function test_the_same_zambian_number_in_any_form_has_one_key(): void
    {
        $key = GuestPhone::key('0971234567');

        $this->assertSame('260971234567', $key);
        $this->assertSame($key, GuestPhone::key('+260 97 123 4567'));
        $this->assertSame($key, GuestPhone::key('00260971234567'));
        $this->assertSame($key, GuestPhone::key('971234567'));
    }

    public function test_a_foreign_number_ending_in_the_same_nine_digits_is_a_different_number(): void
    {
        $this->assertNotSame(GuestPhone::key('0971234567'), GuestPhone::key('+1 971234567'));
        $this->assertNotSame(GuestPhone::key('0971234567'), GuestPhone::key('+44 971 234 567'));
    }

    public function test_a_number_with_no_digits_has_no_key(): void
    {
        $this->assertNull(GuestPhone::key('TBD'));
        $this->assertNull(GuestPhone::key(null));
    }

    public function test_whatsapp_links_dial_the_country_code_or_nothing(): void
    {
        $this->assertStringStartsWith('https://wa.me/260971234567?', (string) WhatsAppInviteLink::url('0971234567', 'Hi'));
        $this->assertStringStartsWith('https://wa.me/447700900123?', (string) WhatsAppInviteLink::url('+44 7700 900123', 'Hi'));
        $this->assertNull(WhatsAppInviteLink::url('0971111111 / 0972222222', 'Hi'));
        $this->assertNull(WhatsAppInviteLink::url('097123456', 'Hi'));
        $this->assertNull(WhatsAppInviteLink::url(null, 'Hi'));
    }
}

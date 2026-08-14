<?php

namespace Database\Seeders;

use App\Models\Faq;
use Illuminate\Database\Seeder;

class FaqSeeder extends Seeder
{
    /**
     * The copy that used to be hardcoded in home.blade.php and
     * contact.blade.php. Admins edit these from /admin/faqs after seeding.
     *
     * @var list<array{placement: string, question: string, answer: string}>
     */
    private const FAQS = [
        [
            'placement' => 'homepage',
            'question' => 'Is Event Host really free to use?',
            'answer' => 'Creating an account is free. Each event requires a paid credit — Base from K450, Pro from K750, or Pro+ from K1500. Pay with MTN, Airtel, or bank transfer after you sign up.',
        ],
        [
            'placement' => 'homepage',
            'question' => 'Can I manage RSVPs from my guests?',
            'answer' => 'Absolutely. Your dashboard updates in real time as guests respond. You can see confirmed, declined, and awaiting responses, send reminders, and export the full list.',
        ],
        [
            'placement' => 'homepage',
            'question' => 'Do guests need to create an account to RSVP?',
            'answer' => "No account needed. Guests simply tap the link in their WhatsApp or email, view the invitation, and confirm their attendance in one tap. It's designed to be frictionless.",
        ],
        [
            'placement' => 'homepage',
            'question' => 'Does it work well on mobile phones?',
            'answer' => 'Every invitation and the RSVP experience is fully mobile-optimized. Whether your guests are on an old Android or the latest iPhone, everything looks and works beautifully.',
        ],
        [
            'placement' => 'homepage',
            'question' => 'Can I send invitations via WhatsApp?',
            'answer' => 'Yes — WhatsApp sharing is built in. You get a shareable link you can forward in any WhatsApp chat or group. Guests can RSVP directly from the link without leaving WhatsApp.',
        ],
        [
            'placement' => 'homepage',
            'question' => 'What payment methods are supported?',
            'answer' => 'We support MTN Mobile Money, Airtel Money, Zamtel Kwacha, Visa & Mastercard, and bank deposits where available. Pro+ plans add invoicing and expanded settlement options across Zambia.',
        ],
        [
            'placement' => 'contact',
            'question' => 'Is Event Host free to use?',
            'answer' => 'You get one free event credit on sign-up. Additional credits can be purchased to create more events.',
        ],
        [
            'placement' => 'contact',
            'question' => 'How do guests RSVP?',
            'answer' => 'Guests click a personalised link — no account required. RSVPs work seamlessly via WhatsApp, SMS, or any browser.',
        ],
        [
            'placement' => 'contact',
            'question' => 'Can I use Event Host for large events?',
            'answer' => 'Absolutely. Event Host handles intimate dinners and 1,000-person weddings with the same ease.',
        ],
        [
            'placement' => 'contact',
            'question' => 'What payment methods are supported?',
            'answer' => 'We support MTN MoMo, Airtel Money, and card payments so guests can pay in the way they prefer.',
        ],
    ];

    public function run(): void
    {
        $order = ['homepage' => 0, 'contact' => 0];

        foreach (self::FAQS as $faq) {
            Faq::query()->firstOrCreate(
                [
                    'placement' => $faq['placement'],
                    'question' => $faq['question'],
                ],
                [
                    'answer' => $faq['answer'],
                    'is_published' => true,
                    'sort_order' => $order[$faq['placement']],
                ]
            );

            $order[$faq['placement']] += 10;
        }
    }
}

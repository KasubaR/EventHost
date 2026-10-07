<?php

namespace App\Support;

/**
 * The banks a host can choose for a payout account. A plain list rather than a table:
 * it changes a few times a decade, and the stored value is the display name so a past
 * payout record still reads correctly if a bank is later renamed or dropped from here.
 * Review it against the Bank of Zambia's list of licensed commercial banks before launch.
 */
class ZambianBanks
{
    /**
     * @var list<string>
     */
    private const NAMES = [
        'Absa Bank Zambia',
        'Access Bank Zambia',
        'AB Bank Zambia',
        'Bank of China Zambia',
        'Citibank Zambia',
        'Ecobank Zambia',
        'First Alliance Bank Zambia',
        'First Capital Bank Zambia',
        'First National Bank (FNB) Zambia',
        'Indo-Zambia Bank',
        'Investrust Bank',
        'National Savings and Credit Bank (NATSAVE)',
        'Stanbic Bank Zambia',
        'Standard Chartered Bank Zambia',
        'United Bank for Africa (UBA) Zambia',
        'Zambia Industrial and Commercial Bank (ZICB)',
        'Zanaco (Zambia National Commercial Bank)',
    ];

    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return self::NAMES;
    }
}

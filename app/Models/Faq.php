<?php

namespace App\Models;

use Database\Factories\FaqFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Faq extends Model
{
    /** @use HasFactory<FaqFactory> */
    use HasFactory;

    /**
     * Where a question is shown. Single source of truth for the admin dropdown,
     * request validation and the seeder.
     *
     * @var array<string, string>
     */
    public const PLACEMENTS = [
        'homepage' => 'Homepage',
        'contact' => 'Contact page',
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'question',
        'answer',
        'placement',
        'is_published',
        'sort_order',
    ];

    /**
     * Questions to render on a given public page, in admin-defined order.
     *
     * @param  Builder<$this>  $query
     */
    public function scopePublishedFor(Builder $query, string $placement): void
    {
        $query->where('placement', $placement)
            ->where('is_published', true)
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function placementLabel(): string
    {
        return self::PLACEMENTS[$this->placement] ?? $this->placement;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_published' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}

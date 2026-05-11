<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class InvitationTemplateCategory extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'slug',
        'name',
        'sort_order',
    ];

    /**
     * @return BelongsToMany<InvitationTemplate, $this>
     */
    public function invitationTemplates(): BelongsToMany
    {
        return $this->belongsToMany(
            InvitationTemplate::class,
            'inv_tpl_cat'
        );
    }

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }
}

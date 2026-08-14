<?php

namespace Database\Factories;

use App\Models\Faq;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Faq>
 */
class FaqFactory extends Factory
{
    protected $model = Faq::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'question' => rtrim(fake()->unique()->sentence(6), '.').'?',
            'answer' => fake()->paragraph(),
            'placement' => 'homepage',
            'is_published' => true,
            'sort_order' => 0,
        ];
    }

    public function contact(): static
    {
        return $this->state(fn (): array => ['placement' => 'contact']);
    }

    public function unpublished(): static
    {
        return $this->state(fn (): array => ['is_published' => false]);
    }
}

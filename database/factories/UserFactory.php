<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'google_id' => fake()->unique()->numerify('##################'),
            'username' => fake()->unique()->userName(),
            'email' => fake()->unique()->safeEmail(),
            'avatar' => null,
            'highest_wpm' => 0,
            'total_xp' => 0,
            'is_admin' => false,
            'preferences' => null,
            'remember_token' => Str::random(10),
            // A factory user represents an active account: present, so isOnline() is true
            // and the multiplayer stale-member sweep won't reap them from a room. Tests that
            // need an offline/absent user set last_seen_at explicitly (null or a past time)
            // -- see PresenceTest and the offline() helper in MultiplayerStaleSweepTest.
            'last_seen_at' => now(),
        ];
    }

    /** An absent/offline user: last_seen_at is null, so isOnline() is false. */
    public function offline(): static
    {
        return $this->state(fn (array $attributes) => [
            'last_seen_at' => null,
        ]);
    }

    /**
     * Indicate that the user is an admin.
     */
    public function admin(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_admin' => true,
        ]);
    }
}

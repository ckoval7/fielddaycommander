<?php

namespace Database\Factories;

use App\Models\ShiftAssignment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ShiftAssignment>
 */
class ShiftAssignmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'shift_id' => \App\Models\Shift::factory(),
            'user_id' => User::factory(),
            'status' => ShiftAssignment::STATUS_SCHEDULED,
            'checked_in_at' => null,
            'checked_out_at' => null,
            'confirmed_by_user_id' => null,
            'confirmed_at' => null,
            'signup_type' => ShiftAssignment::SIGNUP_TYPE_ASSIGNED,
        ];
    }

    /**
     * Indicate this assignment was self-signup.
     */
    public function selfSignup(): static
    {
        return $this->state(fn (array $attributes) => [
            'signup_type' => ShiftAssignment::SIGNUP_TYPE_SELF_SIGNUP,
        ]);
    }

    /**
     * Indicate the user has checked in.
     */
    public function checkedIn(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ShiftAssignment::STATUS_CHECKED_IN,
            'checked_in_at' => now(),
        ]);
    }

    /**
     * Indicate the user has checked out.
     */
    public function checkedOut(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ShiftAssignment::STATUS_CHECKED_OUT,
            'checked_in_at' => now()->subHours(2),
            'checked_out_at' => now(),
        ]);
    }

    /**
     * Indicate the assignment has been confirmed by a manager.
     */
    public function confirmed(?User $manager = null): static
    {
        return $this->state(fn (array $attributes) => [
            'confirmed_by_user_id' => $manager?->id ?? User::factory(),
            'confirmed_at' => now(),
        ]);
    }

    /**
     * Indicate the user was a no-show.
     */
    public function noShow(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ShiftAssignment::STATUS_NO_SHOW,
        ]);
    }
}

<?php

namespace Database\Factories;

use App\Enums\MessageFormat;
use App\Enums\MessagePrecedence;
use App\Enums\MessageRole;
use App\Models\EventConfiguration;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Message> */
class MessageFactory extends Factory
{
    public function definition(): array
    {
        return [
            'event_configuration_id' => EventConfiguration::factory(),
            'user_id' => User::factory(),
            'format' => MessageFormat::Radiogram,
            'role' => MessageRole::Originated,
            'is_sm_message' => false,
            'message_number' => fake()->numberBetween(1, 999),
            'precedence' => MessagePrecedence::Routine,
            'hx_code' => null,
            'station_of_origin' => strtoupper(fake()->bothify('??#???')),
            'check' => (string) fake()->numberBetween(5, 25),
            'place_of_origin' => fake()->city().', '.fake()->stateAbbr(),
            'filed_at' => now(),
            'addressee_name' => fake()->name(),
            'addressee_address' => fake()->streetAddress(),
            'addressee_city' => fake()->city(),
            'addressee_state' => fake()->stateAbbr(),
            'addressee_zip' => fake()->postcode(),
            'addressee_phone' => fake()->phoneNumber(),
            'message_text' => strtoupper(implode(' X ', fake()->words(10))),
            'signature' => fake()->name(),
            'sent_to' => strtoupper(fake()->bothify('??#???')),
            'received_from' => null,
        ];
    }

    public function smMessage(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_sm_message' => true,
            'role' => MessageRole::Originated,
        ]);
    }

    public function ics213(): static
    {
        return $this->state(fn (array $attributes) => [
            'format' => MessageFormat::Ics213,
            'precedence' => null,
            'hx_code' => null,
            'station_of_origin' => null,
            'check' => null,
            'place_of_origin' => null,
            'addressee_address' => null,
            'addressee_city' => null,
            'addressee_state' => null,
            'addressee_zip' => null,
            'addressee_phone' => null,
            'sent_to' => null,
            'received_from' => null,
            'ics_to_position' => fake()->jobTitle(),
            'ics_from_position' => fake()->jobTitle(),
            'ics_subject' => fake()->sentence(4),
            'message_text' => fake()->paragraph(),
        ]);
    }

    public function originated(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => MessageRole::Originated,
            'received_from' => null,
        ]);
    }

    public function relayed(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => MessageRole::Relayed,
            'received_from' => strtoupper(fake()->bothify('??#???')),
        ]);
    }

    public function receivedDelivered(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => MessageRole::ReceivedDelivered,
            'received_from' => strtoupper(fake()->bothify('??#???')),
            'sent_to' => null,
        ]);
    }
}

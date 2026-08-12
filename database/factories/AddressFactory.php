<?php

namespace Database\Factories;

use App\Models\Address;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class AddressFactory extends Factory
{
    protected $model = Address::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'label' => $this->faker->randomElement(['Home', 'Work', 'Other']),
            'address_line_1' => $this->faker->streetAddress(),
            'address_line_2' => $this->faker->boolean(40) ? $this->faker->secondaryAddress() : null,
            'city' => $this->faker->city(),
            'state' => $this->faker->state(),
            'pincode' => $this->faker->numerify('######'),
            'landmark' => $this->faker->boolean(50) ? $this->faker->streetName() : null,
            'is_default' => false,
        ];
    }
}

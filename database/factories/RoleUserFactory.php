<?php

namespace Database\Factories;

use App\Models\Role;
use App\Models\RoleUser;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RoleUser>
 */
class RoleUserFactory extends Factory
{
    public function definition(): array
    {
        return [
            'role_id' => Role::factory(),
            'user_id' => User::factory(),
        ];
    }

    public function grantedBy(User $user): static
    {
        return $this->state(fn (array $attributes) => ['granted_by_user_id' => $user->id]);
    }
}

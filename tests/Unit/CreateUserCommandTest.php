<?php

namespace Tests\Unit;

use App\Console\Commands\CreateUserCommand;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Symfony\Component\Console\Command\Command;
use Tests\TestCase;

class CreateUserCommandTest extends TestCase
{
    use RefreshDatabase;
    use WithFaker;

    public function test_user_can_be_created_with_arguments()
    {
        $name = $this->faker->name;
        $email = $this->faker->safeEmail;
        $password = 'password123';

        $this->artisan('pr0:create-user', [
            'name' => $name,
            'email' => $email,
            'password' => $password,
        ])->assertExitCode(CreateUserCommand::SUCCESS);

        $this->assertDatabaseHas('users', [
            'name' => $name,
            'email' => $email,
        ]);
    }

    public function test_user_can_be_created_interactively()
    {
        $name = $this->faker->name;
        $email = $this->faker->safeEmail;
        $password = 'password123';

        $this->artisan('pr0:create-user')
            ->expectsQuestion('Name', $name)
            ->expectsQuestion('Email', $email)
            ->expectsQuestion('Password', $password)
            ->assertExitCode(Command::SUCCESS);

        $this->assertDatabaseHas('users', [
            'name' => $name,
            'email' => $email,
        ]);
    }

    public function test_user_creation_fails_with_invalid_data()
    {
        // Create a user to test uniqueness validation
        User::factory()->create(['email' => 'john@example.com']);

        $name = 'test';
        $email = 'invalid-email';
        $password = 'short';

        $this->artisan('pr0:create-user', [
            'name' => $name,
            'email' => $email,
            'password' => $password,
        ])->assertExitCode(Command::FAILURE);

        $this->assertDatabaseMissing('users', [
            'email' => $email,
        ]);
    }
}

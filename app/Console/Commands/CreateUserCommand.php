<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

class CreateUserCommand extends Command
{
    protected $signature = 'pr0:create-user {name?} {email?} {password?}';

    protected $description = 'Create a new user';

    public function handle(): int
    {
        $name = $this->argument('name');
        $email = $this->argument('email');
        $password = $this->argument('password');

        if (! $name) {
            $name = $this->ask('Name');
        }
        if (! $email) {
            $email = $this->ask('Email');
        }
        if (! $password) {
            $password = $this->secret('Password');
        }

        $validations = [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8',
        ];

        $validator = Validator::make([
            'name' => $name,
            'email' => $email,
            'password' => $password,
        ], $validations);

        if ($validator->passes()) {
            User::create([
                'name' => $name,
                'email' => $email,
                'password' => \Hash::make($password),
            ]);

            $this->info('Done!');

        } else {
            $this->error('Validation failed!');
            $this->error($validator->errors()->first());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}

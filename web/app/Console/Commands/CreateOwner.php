<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\User;
use App\Notifications\SetUpAccountNotification;
use App\Services\Users\UserManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Password;

class CreateOwner extends Command
{
    protected $signature = 'app:create-owner
        {name : Full name of the Owner}
        {email : Email address of the Owner}
        {--print-link : Print the password set-up link instead of emailing it}';

    protected $description = 'Create an Owner account (the first one, since there is no public sign-up)';

    public function handle(UserManager $users): int
    {
        $email = mb_strtolower(trim((string) $this->argument('email')));

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->components->error('That is not a valid email address.');

            return self::FAILURE;
        }

        if (User::where('email', $email)->exists()) {
            $this->components->error('A user with that email address already exists.');

            return self::FAILURE;
        }

        $user = $users->create((string) $this->argument('name'), $email, Role::Owner);
        $token = Password::broker()->createToken($user);

        if ($this->option('print-link')) {
            $this->components->info('Owner created. Open this link to choose a password:');
            $this->line(route('password.reset', ['token' => $token]));

            return self::SUCCESS;
        }

        $user->notify(new SetUpAccountNotification($token));
        $this->components->info('Owner created. A set-up email has been sent.');

        return self::SUCCESS;
    }
}

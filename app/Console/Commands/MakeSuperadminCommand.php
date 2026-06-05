<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class MakeSuperadminCommand extends Command
{
    protected $signature = 'pl:make-superadmin {email : User email to promote}';

    protected $description = 'Promote an existing user to superadmin role for the admin area.';

    public function handle(): int
    {
        $email = trim(strtolower((string) $this->argument('email')));

        $user = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();
        if (! $user) {
            $this->error('User not found for email: ' . $email);

            return self::FAILURE;
        }

        $user->is_admin = true;
        $user->admin_role = 'superadmin';
        $user->save();

        $this->info('User promoted to superadmin: ' . $user->email);

        return self::SUCCESS;
    }
}


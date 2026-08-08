<?php

namespace App\Console\Commands;

use App\Actions\Installation\ProvisionFirstOwner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CreateUser extends Command
{
    protected $signature = 'user:create
        {email : The user email address}
        {password : The user password}
        {team : The team name}';

    protected $description = 'Create a user and a team owned by that user';

    public function handle(ProvisionFirstOwner $provisionFirstOwner): int
    {
        $email = (string) $this->argument('email');
        $password = (string) $this->argument('password');
        $teamName = (string) $this->argument('team');

        try {
            Validator::make(
                ['team' => $teamName],
                ['team' => ['required', 'string', 'max:255']],
            )->validate();

            $user = $provisionFirstOwner->provision(
                name: Str::before($email, '@'),
                email: $email,
                password: $password,
                teamName: $teamName,
            );
        } catch (ValidationException $exception) {
            foreach ($exception->validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Created user %s with team "%s".',
            $user->email,
            (string) $user->currentTeam?->name,
        ));

        return self::SUCCESS;
    }
}

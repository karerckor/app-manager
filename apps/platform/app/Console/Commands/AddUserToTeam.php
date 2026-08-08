<?php

namespace App\Console\Commands;

use App\Actions\Fortify\CreateNewUser;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AddUserToTeam extends Command
{
    protected $signature = 'user:add-to-team
        {email : The user email address}
        {password : The user password}
        {team : The team slug}
        {role : The team role (admin, contributor or viewer)}';

    protected $description = 'Create a user and add them to an existing team';

    public function handle(CreateNewUser $createNewUser): int
    {
        $email = mb_strtolower(trim((string) $this->argument('email')));
        $password = (string) $this->argument('password');
        $teamSlug = trim((string) $this->argument('team'));
        $role = (string) $this->argument('role');

        try {
            Validator::make(
                ['role' => $role],
                ['role' => ['required', Rule::enum(TeamRole::class), Rule::notIn([TeamRole::Owner->value])]],
            )->validate();

            $team = Team::query()->where('slug', $teamSlug)->first();

            if ($team === null) {
                $this->error(sprintf('Team with slug "%s" was not found.', $teamSlug));

                return self::FAILURE;
            }

            $user = DB::transaction(function () use ($createNewUser, $email, $password, $team, $role): User {
                $user = $createNewUser->create([
                    'name' => Str::before($email, '@'),
                    'email' => $email,
                    'password' => $password,
                    'password_confirmation' => $password,
                ]);

                $team->addUser($user, $role);
                $team->recordMembershipAudit($user, 'membership_added', $role);
                $user->update(['current_team_id' => $team->id]);

                return $user;
            });
        } catch (ValidationException $exception) {
            foreach ($exception->validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Created user %s in team "%s" with role "%s".',
            $user->email,
            $team->name,
            $role,
        ));

        return self::SUCCESS;
    }
}

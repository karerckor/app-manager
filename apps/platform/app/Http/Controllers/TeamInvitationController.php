<?php

namespace App\Http\Controllers;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Notifications\TeamInvitationNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TeamInvitationController extends Controller
{
    public function store(Request $request, Team $team): RedirectResponse
    {
        $this->authorize('manageTeam', $team);
        $request->merge([
            'email' => mb_strtolower(trim((string) $request->input('email'))),
        ]);

        $data = $request->validate([
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255'],
            'role' => [
                'required',
                Rule::enum(TeamRole::class),
                Rule::notIn([TeamRole::Owner->value]),
            ],
        ]);

        if ($team->users()->whereRaw('LOWER(email) = ?', [$data['email']])->exists()) {
            throw ValidationException::withMessages([
                'email' => 'This user is already a team member.',
            ]);
        }

        $invitation = $team->invitations()->updateOrCreate(
            ['email' => $data['email']],
            [
                'role' => TeamRole::from($data['role']),
                'invited_by' => $request->user()->id,
                'expires_at' => now()->addDays(7),
                'accepted_at' => null,
            ],
        );

        $notifiable = User::whereRaw('LOWER(email) = ?', [$data['email']])->first();

        if ($notifiable) {
            $notifiable->notify(new TeamInvitationNotification($invitation));
        } else {
            Notification::route('mail', $data['email'])
                ->notify(new TeamInvitationNotification($invitation));
        }

        return back()->with('status', 'Invitation sent.');
    }

    public function destroy(Team $team, TeamInvitation $invitation): RedirectResponse
    {
        $this->authorize('manageTeam', $team);
        abort_unless($invitation->team_id === $team->id, 404);
        $invitation->delete();

        return back()->with('status', 'Invitation revoked.');
    }

    public function accept(Request $request, TeamInvitation $invitation): RedirectResponse
    {
        abort_if($invitation->accepted_at || $invitation->expires_at->isPast(), 410);

        if (strcasecmp($request->user()->email, $invitation->email) !== 0) {
            abort(403, 'This invitation was sent to another email address.');
        }

        $invitation->team->addUser($request->user(), $invitation->role);
        $invitation->update(['accepted_at' => now()]);
        $request->user()->update(['current_team_id' => $invitation->team_id]);

        return redirect()
            ->route('accounts.access', $invitation->team)
            ->with('status', 'Invitation accepted.');
    }
}

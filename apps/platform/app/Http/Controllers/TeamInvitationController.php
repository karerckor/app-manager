<?php

namespace App\Http\Controllers;

use App\Enums\TeamPermission;
use App\Enums\TeamRole;
use App\Enums\WebhookEventType;
use App\Integrations\Webhooks\WebhookEventRecorder;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Notifications\TeamInvitationNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class TeamInvitationController extends Controller
{
    public function __construct(private readonly WebhookEventRecorder $webhookEvents) {}

    public function show(Request $request, TeamInvitation $invitation): Response
    {
        $this->ensureInvitationsEnabled();

        return Inertia::render('Team/AcceptInvitation', [
            'invitation' => [
                'email' => $invitation->email,
                'role' => $invitation->role,
                'team_name' => $invitation->team->name,
                'expires_at' => $invitation->expires_at,
                'is_pending' => $invitation->isPending(),
                'email_matches' => hash_equals(mb_strtolower($invitation->email), mb_strtolower($request->user()->email)),
                'accept_url' => route('team-invitations.accept', [
                    'invitation' => $invitation->plainTextToken,
                ]),
            ],
        ]);
    }

    public function store(Request $request, Team $team): RedirectResponse
    {
        $this->ensureInvitationsEnabled();
        $this->authorizeManagement($request, $team);

        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'max:255'],
            'role' => ['required', Rule::enum(TeamRole::class), Rule::notIn([TeamRole::Owner->value])],
        ]);
        $email = mb_strtolower(trim($data['email']));

        if ($team->users()->whereRaw('LOWER(email) = ?', [$email])->exists()) {
            return back()->withErrors(['email' => 'This person is already a team member.']);
        }

        if ($team->invitations()->whereRaw('LOWER(email) = ?', [$email])->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now())->exists()) {
            return back()->withErrors(['email' => 'A pending invitation already exists for this email.']);
        }

        $invitation = $team->invitations()->create([
            'invited_by' => $request->user()->id,
            'email' => $email,
            'role' => $data['role'],
            'expires_at' => now()->addDays(7),
        ]);
        $invitation->load(['team', 'inviter']);

        Notification::route('mail', $email)->notify(new TeamInvitationNotification(
            $invitation,
            $invitation->plainTextToken,
        ));
        $this->webhookEvents->record($team, WebhookEventType::TeamInvitationCreated, $invitation, [
            'id' => $invitation->id,
            'email' => $invitation->email,
            'role' => (string) $invitation->role,
            'expires_at' => $invitation->expires_at->toISOString(),
        ]);

        return back()->with('status', "Invitation sent to {$email}.");
    }

    public function destroy(Request $request, Team $team, TeamInvitation $invitation): RedirectResponse
    {
        $this->ensureInvitationsEnabled();
        $this->authorizeManagement($request, $team);
        abort_unless($invitation->team_id === $team->id && $invitation->isPending(), 404);

        $invitation->update(['revoked_at' => now()]);

        return back()->with('status', "Invitation for {$invitation->email} revoked.");
    }

    public function accept(Request $request, TeamInvitation $invitation): RedirectResponse
    {
        $this->ensureInvitationsEnabled();
        abort_unless(hash_equals(mb_strtolower($invitation->email), mb_strtolower($request->user()->email)), 403);

        DB::transaction(function () use ($request, $invitation) {
            $invitation->refresh();
            abort_unless($invitation->isPending(), 410, 'This invitation is no longer active.');

            $invitation->team->addUser($request->user(), $invitation->role);
            $invitation->team->recordMembershipAudit($request->user(), 'membership_added', $invitation->role);
            $invitation->update(['accepted_at' => now()]);
            $request->user()->update(['current_team_id' => $invitation->team_id]);
        });

        return redirect()->route('accounts.apps.index', ['team' => $invitation->team])
            ->with('status', "You joined {$invitation->team->name}.");
    }

    private function authorizeManagement(Request $request, Team $team): void
    {
        abort_unless($team->allows($request->user(), TeamPermission::ManageTeam), 403);
    }

    private function ensureInvitationsEnabled(): void
    {
        abort_unless(config('team.invitations_enabled', true), 404);
    }
}

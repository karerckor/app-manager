<script>
    import { router, useForm } from '@inertiajs/svelte';
    import AppShell from '../../Components/AppShell.svelte';

    export let auth;
    export let flash = {};
    export let navigationTeams = [];
    export let navigationTeam = null;
    export let team;
    export let members = [];
    export let invitations = [];
    export let roles = [];
    export let inviteUrl;

    const canManageTeam = auth?.team?.permissions?.manage_team ?? false;
    const inviteForm = useForm({ email: '', role: 'viewer' });
    let confirmingRemoval = null;
    let removing = false;

    const roleFor = (value) => roles.find((role) => role.value === value)
        ?? (value === 'owner' ? { label: 'Owner', description: 'Full access, including closing the team.' } : { label: value, description: '' });
    const initials = (name = '') => name.split(/\s+/).map((part) => part[0]).join('').slice(0, 2).toUpperCase();
    const formatDate = (value) => new Intl.DateTimeFormat('en', { month: 'short', day: 'numeric', year: 'numeric' }).format(new Date(value));
    const invite = () => inviteForm.post(inviteUrl, {
        preserveScroll: true,
        onSuccess: () => inviteForm.reset('email'),
    });
    const updateRole = (member, event) => router.patch(member.update_url, {
        role: event.currentTarget.value,
    }, { preserveScroll: true });
    const revokeInvitation = (invitation) => router.delete(invitation.destroy_url, { preserveScroll: true });
    const removeMember = () => router.delete(confirmingRemoval.destroy_url, {
        onStart: () => removing = true,
        onSuccess: () => confirmingRemoval = null,
        onFinish: () => removing = false,
    });
</script>

<svelte:head><title>Team access · {team.name}</title></svelte:head>

<AppShell {auth} teams={navigationTeams} currentTeam={navigationTeam} active="access">
    <main class="mx-auto max-w-[1120px] px-5 py-8 sm:px-8 lg:px-10 lg:py-10">
        <header class="mb-8 flex flex-wrap items-end justify-between gap-4">
            <div>
                <p class="eyebrow mb-2">Workspace security</p>
                <div class="flex flex-wrap items-center gap-3"><h1 class="text-3xl font-bold tracking-tight text-slate-950">Team access</h1><span class="rounded-full bg-indigo-50 px-2.5 py-1 text-[10px] font-bold text-indigo-700 uppercase">{roleFor(auth.team.role).label}</span></div>
                <p class="mt-2 text-sm text-slate-500">Manage who can access {team.name} and what they can change.</p>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-right shadow-sm"><p class="text-lg font-bold text-slate-900">{members.length}</p><p class="text-[10px] font-semibold tracking-wide text-slate-400 uppercase">member{members.length === 1 ? '' : 's'}</p></div>
        </header>

        {#if flash?.status}<div class="mb-6 flex items-center gap-3 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800"><span class="grid h-5 w-5 place-items-center rounded-full bg-emerald-500 text-xs text-white">✓</span>{flash.status}</div>{/if}

        <div class="grid gap-6 {canManageTeam ? 'xl:grid-cols-[minmax(0,1fr)_360px]' : ''}">
            <section class="card overflow-hidden p-0">
                <div class="border-b border-slate-100 px-5 py-4"><h2 class="text-sm font-bold">Members</h2><p class="mt-0.5 text-xs text-slate-500">Roles control access across the web interface and API.</p></div>
                <div class="divide-y divide-slate-100">
                    {#each members as member}
                        <div class="flex flex-wrap items-center gap-4 px-5 py-4">
                            <span class="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-gradient-to-br from-indigo-100 to-violet-100 text-xs font-bold text-indigo-700">{initials(member.name)}</span>
                            <div class="min-w-0 flex-1"><div class="flex items-center gap-2"><p class="truncate text-sm font-semibold text-slate-900">{member.name}</p>{#if member.is_current_user}<span class="rounded bg-slate-100 px-1.5 py-0.5 text-[9px] font-bold text-slate-500 uppercase">You</span>{/if}</div><p class="mt-0.5 truncate text-xs text-slate-500">{member.email}</p></div>
                            <div class="w-full sm:w-44">
                                {#if canManageTeam && !member.is_owner}
                                    <select class="py-2 text-xs font-semibold" value={member.role} on:change={(event) => updateRole(member, event)}>
                                        {#each roles as role}<option value={role.value}>{role.label}</option>{/each}
                                    </select>
                                {:else}
                                    <div class="rounded-lg bg-slate-50 px-3 py-2"><p class="text-xs font-bold text-slate-700">{roleFor(member.role).label}</p><p class="mt-0.5 text-[10px] text-slate-400">{member.is_owner ? 'Team owner' : 'Assigned role'}</p></div>
                                {/if}
                            </div>
                            {#if canManageTeam && !member.is_owner}<button type="button" class="ghost px-2.5 py-2 text-xs text-rose-600 hover:bg-rose-50" on:click={() => confirmingRemoval = member}>{member.is_current_user ? 'Leave' : 'Remove'}</button>{/if}
                        </div>
                    {/each}
                </div>
            </section>

            {#if canManageTeam}
                <aside class="space-y-6">
                    <section class="card">
                        <div class="mb-5"><h2 class="text-sm font-bold">Invite member</h2><p class="mt-1 text-xs leading-5 text-slate-500">They will receive a signed link valid for seven days.</p></div>
                        <form class="space-y-4" on:submit|preventDefault={invite}>
                            <label><span class="label">Email address</span><input type="email" bind:value={inviteForm.email} placeholder="developer@example.com" required />{#if inviteForm.errors.email}<span class="error">{inviteForm.errors.email}</span>{/if}</label>
                            <label><span class="label">Role</span><select bind:value={inviteForm.role}>{#each roles as role}<option value={role.value}>{role.label}</option>{/each}</select>{#if inviteForm.errors.role}<span class="error">{inviteForm.errors.role}</span>{/if}</label>
                            <div class="rounded-xl bg-indigo-50/70 p-3 text-[11px] leading-5 text-indigo-800"><strong>{roleFor(inviteForm.role).label}:</strong> {roleFor(inviteForm.role).description}</div>
                            <button class="w-full" disabled={inviteForm.processing}>{inviteForm.processing ? 'Sending…' : 'Send invitation'}</button>
                        </form>
                    </section>

                    <section class="card p-0">
                        <div class="border-b border-slate-100 px-5 py-4"><h2 class="text-sm font-bold">Pending invitations</h2><p class="mt-0.5 text-xs text-slate-500">{invitations.length} awaiting response</p></div>
                        {#if invitations.length}
                            <div class="divide-y divide-slate-100">
                                {#each invitations as invitation}
                                    <div class="px-5 py-4"><div class="flex items-start justify-between gap-3"><div class="min-w-0"><p class="truncate text-xs font-semibold">{invitation.email}</p><p class="mt-1 text-[10px] text-slate-400">{roleFor(invitation.role).label} · expires {formatDate(invitation.expires_at)}</p></div><button type="button" class="ghost px-2 py-1 text-[10px] text-rose-600" on:click={() => revokeInvitation(invitation)}>Revoke</button></div></div>
                                {/each}
                            </div>
                        {:else}<p class="px-5 py-7 text-center text-xs text-slate-400">No pending invitations.</p>{/if}
                    </section>
                </aside>
            {/if}
        </div>
    </main>
</AppShell>

{#if confirmingRemoval}
    <div class="modal-backdrop" role="presentation" on:click={(event) => event.target === event.currentTarget && (confirmingRemoval = null)}>
        <div class="modal">
            <div class="border-b border-slate-100 px-6 py-5"><h2 class="text-lg font-bold">{confirmingRemoval.is_current_user ? `Leave ${team.name}?` : `Remove ${confirmingRemoval.name}?`}</h2><p class="mt-1 text-sm text-slate-500">{confirmingRemoval.is_current_user ? 'You will lose access to this team.' : 'This member will immediately lose access to team applications and credentials.'}</p></div>
            <div class="flex justify-end gap-3 bg-slate-50/70 px-6 py-4"><button type="button" class="secondary" on:click={() => confirmingRemoval = null}>Cancel</button><button type="button" class="bg-rose-600 text-white hover:bg-rose-700" disabled={removing} on:click={removeMember}>{removing ? 'Removing…' : confirmingRemoval.is_current_user ? 'Leave team' : 'Remove member'}</button></div>
        </div>
    </div>
{/if}

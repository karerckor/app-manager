<script>
    import { router, useForm } from '@inertiajs/svelte';
    import { Check, MailPlus, Search, ShieldCheck, UserMinus, Users, X } from '@lucide/svelte';
    import AppShell from '../../Components/AppShell.svelte';

    export let auth;
    export let flash = {};
    export let navigationTeams = [];
    export let navigationTeam = null;
    export let members = [];
    export let invitations = [];
    export let roles = [];
    export let canManage = false;
    export let checkCredentialUniquenessByDefault = true;
    export let updateTeamUrl;
    export let inviteUrl;

    let showInvite = false;
    let search = '';
    const renameForm = useForm({ name: navigationTeam?.name ?? '' });
    const uniquenessForm = useForm({
        check_credential_uniqueness_by_default: checkCredentialUniquenessByDefault,
    });
    const inviteForm = useForm({ email: '', role: 'contributor' });

    $: filteredMembers = members.filter((member) =>
        `${member.name} ${member.email}`.toLowerCase().includes(search.trim().toLowerCase())
    );

    const initials = (name = '') => name.split(/\s+/).map((part) => part[0]).join('').slice(0, 2).toUpperCase();
    const formatDate = (value) => new Intl.DateTimeFormat('en', { dateStyle: 'medium' }).format(new Date(value));
    const renameTeam = () => renameForm.patch(updateTeamUrl, { preserveScroll: true });
    const saveCredentialUniqueness = () => uniquenessForm.patch(updateTeamUrl, { preserveScroll: true });
    const sendInvite = () => inviteForm.post(inviteUrl, {
        preserveScroll: true,
        onSuccess: () => {
            inviteForm.reset();
            inviteForm.role = 'contributor';
            showInvite = false;
        },
    });
    const closeInvite = () => {
        showInvite = false;
        inviteForm.clearErrors();
    };
    const changeRole = (member, role) => router.patch(member.update_url, { role }, { preserveScroll: true });
    const removeMember = (member) => {
        if (window.confirm(`Remove ${member.name} from ${navigationTeam.name}? They will immediately lose access.`)) {
            router.delete(member.remove_url, { preserveScroll: true });
        }
    };
    const revokeInvite = (invitation) => {
        if (window.confirm(`Revoke the invitation for ${invitation.email}?`)) {
            router.delete(invitation.revoke_url, { preserveScroll: true });
        }
    };
</script>

<svelte:head><title>Team access · App Manager</title></svelte:head>

<AppShell {auth} teams={navigationTeams} currentTeam={navigationTeam} active="team">
    <main class="mx-auto max-w-[1240px] px-5 py-8 sm:px-8 lg:px-10 lg:py-10">
        <div class="mb-8 flex flex-wrap items-end justify-between gap-4">
            <div>
                <p class="eyebrow mb-2">Workspace settings</p>
                <h1 class="text-3xl font-bold tracking-tight text-slate-950">Team access</h1>
                <p class="mt-2 max-w-2xl text-sm text-slate-500">Manage who can access this workspace and what they can do.</p>
            </div>
            {#if canManage}
                <button class="flex items-center gap-2" on:click={() => showInvite = true}>
                    <MailPlus class="h-4 w-4" strokeWidth={2.2} />
                    Invite member
                </button>
            {/if}
        </div>

        {#if flash?.status}
            <div class="mb-6 flex items-center gap-3 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800">
                <span class="grid h-5 w-5 place-items-center rounded-full bg-emerald-500 text-white"><Check class="h-3 w-3" strokeWidth={3} /></span>
                {flash.status}
            </div>
        {/if}

        <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_320px] lg:items-start">
            <section class="card overflow-hidden p-0">
                <div class="flex flex-col gap-3 border-b border-slate-100 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h2 class="text-sm font-bold">Members <span class="ml-1 font-medium text-slate-400">{members.length}</span></h2>
                        <p class="mt-1 text-xs text-slate-500">People with access to {navigationTeam?.name}.</p>
                    </div>
                    <label class="relative block sm:w-56">
                        <span class="sr-only">Search members</span>
                        <Search class="pointer-events-none absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-slate-400" strokeWidth={1.8} />
                        <input class="py-2 pl-9 text-xs" bind:value={search} placeholder="Search members" />
                    </label>
                </div>

                {#if filteredMembers.length}
                    <div class="divide-y divide-slate-100">
                        {#each filteredMembers as member}
                            <article class="flex flex-col gap-4 px-5 py-4 sm:flex-row sm:items-center">
                                <span class="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-indigo-50 text-xs font-bold text-indigo-700">{initials(member.name)}</span>
                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <h3 class="truncate text-sm font-semibold text-slate-900">{member.name}</h3>
                                        {#if member.is_current_user}<span class="rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-semibold text-slate-500">You</span>{/if}
                                        {#if member.is_owner}<span class="rounded-full bg-violet-50 px-2 py-0.5 text-[10px] font-semibold text-violet-700">Owner</span>{/if}
                                    </div>
                                    <p class="mt-0.5 truncate text-xs text-slate-500">{member.email} · Joined {formatDate(member.joined_at)}</p>
                                </div>
                                {#if canManage && !member.is_owner && !member.is_current_user}
                                    <div class="flex items-center gap-2">
                                        <select class="w-36 py-2 text-xs" value={member.role} aria-label={`Role for ${member.name}`} on:change={(event) => changeRole(member, event.currentTarget.value)}>
                                            {#each roles as role}<option value={role.value}>{role.label}</option>{/each}
                                        </select>
                                        <button class="ghost p-2 text-slate-400 hover:bg-rose-50 hover:text-rose-600" aria-label={`Remove ${member.name}`} title="Remove member" on:click={() => removeMember(member)}>
                                            <UserMinus class="h-4 w-4" strokeWidth={1.9} />
                                        </button>
                                    </div>
                                {:else}
                                    <span class="rounded-lg bg-slate-50 px-2.5 py-1.5 text-xs font-semibold capitalize text-slate-600">{member.role}</span>
                                {/if}
                            </article>
                        {/each}
                    </div>
                {:else}
                    <div class="px-6 py-14 text-center">
                        <Users class="mx-auto h-6 w-6 text-slate-300" />
                        <p class="mt-3 text-sm font-semibold">No members found</p>
                        <p class="mt-1 text-xs text-slate-500">Try a different name or email.</p>
                    </div>
                {/if}
            </section>

            <div class="space-y-6">
                <section class="card">
                    <div class="mb-4 flex items-center gap-3">
                        <span class="grid h-9 w-9 place-items-center rounded-xl bg-indigo-50 text-indigo-600"><ShieldCheck class="h-5 w-5" strokeWidth={1.8} /></span>
                        <div><h2 class="text-sm font-bold">Workspace name</h2><p class="text-xs text-slate-500">Visible to every member.</p></div>
                    </div>
                    <form on:submit|preventDefault={renameTeam}>
                        <input bind:value={renameForm.name} disabled={!canManage} aria-label="Workspace name" required />
                        {#if renameForm.errors.name}<span class="error">{renameForm.errors.name}</span>{/if}
                        {#if canManage}<button class="mt-3 w-full" disabled={renameForm.processing || renameForm.name === navigationTeam?.name}>{renameForm.processing ? 'Saving…' : 'Save name'}</button>{/if}
                    </form>
                </section>

                <section class="card">
                    <div class="mb-4 flex items-center gap-3">
                        <span class="grid h-9 w-9 place-items-center rounded-xl bg-emerald-50 text-emerald-600"><Check class="h-5 w-5" strokeWidth={2.2} /></span>
                        <div><h2 class="text-sm font-bold">Credential uniqueness</h2><p class="text-xs text-slate-500">Default for new credentials.</p></div>
                    </div>
                    <form on:submit|preventDefault={saveCredentialUniqueness}>
                        <label class="flex cursor-pointer items-start justify-between gap-4 rounded-xl border border-slate-200 bg-slate-50/70 px-3.5 py-3">
                            <span>
                                <span class="block text-xs font-semibold text-slate-800">Check by default</span>
                                <span class="mt-1 block text-[10px] leading-4 text-slate-500">New credential forms will look for the same type, file, and identifying fields in this team vault.</span>
                            </span>
                            <span class="relative mt-0.5 shrink-0">
                                <input
                                    class="sr-only"
                                    type="checkbox"
                                    role="switch"
                                    bind:checked={uniquenessForm.check_credential_uniqueness_by_default}
                                    disabled={!canManage}
                                />
                                <span class="block h-5 w-9 rounded-full transition {uniquenessForm.check_credential_uniqueness_by_default ? 'bg-indigo-600' : 'bg-slate-300'}">
                                    <span class="block h-4 w-4 translate-y-0.5 rounded-full bg-white shadow-sm transition {uniquenessForm.check_credential_uniqueness_by_default ? 'translate-x-[18px]' : 'translate-x-0.5'}"></span>
                                </span>
                            </span>
                        </label>
                        {#if uniquenessForm.errors.check_credential_uniqueness_by_default}<span class="error">{uniquenessForm.errors.check_credential_uniqueness_by_default}</span>{/if}
                        {#if canManage}
                            <button
                                class="mt-3 w-full"
                                disabled={uniquenessForm.processing || uniquenessForm.check_credential_uniqueness_by_default === checkCredentialUniquenessByDefault}
                            >{uniquenessForm.processing ? 'Saving…' : 'Save default'}</button>
                        {/if}
                    </form>
                </section>

                {#if canManage}
                    <section class="card overflow-hidden p-0">
                        <div class="border-b border-slate-100 px-5 py-4">
                            <h2 class="text-sm font-bold">Pending invitations <span class="ml-1 font-medium text-slate-400">{invitations.length}</span></h2>
                        </div>
                        {#if invitations.length}
                            <div class="divide-y divide-slate-100">
                                {#each invitations as invitation}
                                    <div class="px-5 py-4">
                                        <div class="flex items-start justify-between gap-2">
                                            <div class="min-w-0"><p class="truncate text-xs font-semibold text-slate-800">{invitation.email}</p><p class="mt-1 text-[11px] capitalize text-slate-500">{invitation.role} · expires {formatDate(invitation.expires_at)}</p></div>
                                            <button class="ghost -mr-2 p-2 text-slate-400 hover:bg-rose-50 hover:text-rose-600" aria-label={`Revoke invitation for ${invitation.email}`} on:click={() => revokeInvite(invitation)}><X class="h-4 w-4" /></button>
                                        </div>
                                    </div>
                                {/each}
                            </div>
                        {:else}
                            <div class="px-5 py-8 text-center"><p class="text-xs text-slate-500">No pending invitations.</p></div>
                        {/if}
                    </section>
                {/if}
            </div>
        </div>
    </main>
</AppShell>

{#if showInvite}
    <div class="modal-backdrop" role="presentation" on:click={(event) => event.target === event.currentTarget && closeInvite()}>
        <form class="modal" on:submit|preventDefault={sendInvite}>
            <div class="flex items-start justify-between border-b border-slate-100 px-6 py-5">
                <div><h2 class="text-lg font-bold">Invite a team member</h2><p class="mt-1 text-sm text-slate-500">They will receive an email valid for 7 days.</p></div>
                <button type="button" class="ghost -mr-2 p-2" on:click={closeInvite} aria-label="Close"><X class="h-4 w-4" /></button>
            </div>
            <div class="space-y-5 p-6">
                <label><span class="label">Email address</span><input type="email" bind:value={inviteForm.email} placeholder="teammate@company.com" required />{#if inviteForm.errors.email}<span class="error">{inviteForm.errors.email}</span>{/if}</label>
                <label><span class="label">Role</span><select bind:value={inviteForm.role}>{#each roles as role}<option value={role.value}>{role.label}</option>{/each}</select>{#if inviteForm.errors.role}<span class="error">{inviteForm.errors.role}</span>{/if}</label>
                <div class="rounded-xl bg-slate-50 p-3 text-xs leading-5 text-slate-500"><strong class="text-slate-700">Admin</strong> manages the workspace and team. <strong class="text-slate-700">Contributor</strong> manages credentials. <strong class="text-slate-700">Viewer</strong> has read-only access.</div>
            </div>
            <div class="flex justify-end gap-3 border-t border-slate-100 bg-slate-50/70 px-6 py-4"><button type="button" class="secondary" on:click={closeInvite}>Cancel</button><button disabled={inviteForm.processing}>{inviteForm.processing ? 'Sending…' : 'Send invitation'}</button></div>
        </form>
    </div>
{/if}

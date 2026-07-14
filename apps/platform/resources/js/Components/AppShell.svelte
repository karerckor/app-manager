<script>
    import { Link, router } from '@inertiajs/svelte';

    export let auth;
    export let teams = [];
    export let currentTeam = null;
    export let active = 'apps';

    $: appsUrl = currentTeam?.slug ? `/accounts/${currentTeam.slug}/apps` : '/dashboard';
    const switchTeam = (event) => router.put(`/accounts/${event.currentTarget.value}/switch`);
    const logout = () => router.post('/logout');
    const initials = (name = '') => name.split(/\s+/).map((part) => part[0]).join('').slice(0, 2).toUpperCase();
</script>

<div class="min-h-screen lg:grid lg:grid-cols-[256px_minmax(0,1fr)]">
    <aside class="hidden min-h-screen flex-col border-r border-white/10 bg-[#111827] px-4 py-5 text-white lg:flex">
        <Link href={appsUrl} class="mb-8 flex items-center gap-3 px-2">
            <span class="grid h-9 w-9 grid-cols-2 gap-0.5 rounded-xl bg-indigo-500 p-2 shadow-lg shadow-indigo-950/30">
                <i class="rounded-[2px] bg-white"></i><i class="rounded-[2px] bg-white/60"></i><i class="rounded-[2px] bg-white/60"></i><i class="rounded-[2px] bg-white"></i>
            </span>
            <span><strong class="block text-sm tracking-wide">App Manager</strong><small class="text-[11px] text-slate-400">Developer Console</small></span>
        </Link>

        <div class="mb-7 rounded-xl border border-white/10 bg-white/[0.04] p-2.5">
            <label for="team" class="mb-1 block px-1 text-[10px] font-semibold tracking-widest text-slate-500 uppercase">Workspace</label>
            <select id="team" class="border-0 bg-transparent p-1 text-xs font-semibold text-white shadow-none focus:ring-0" value={currentTeam?.slug ?? ''} on:change={switchTeam}>
                {#each teams as team}<option class="text-slate-900" value={team.slug}>{team.name}</option>{/each}
            </select>
        </div>

        <nav class="space-y-1">
            <Link href={appsUrl} class="flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition {active === 'apps' ? 'bg-indigo-500 text-white shadow-lg shadow-indigo-950/20' : 'text-slate-400 hover:bg-white/[0.06] hover:text-white'}">
                <svg viewBox="0 0 24 24" class="h-4.5 w-4.5" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="3" width="7" height="7" rx="2"/><rect x="14" y="3" width="7" height="7" rx="2"/><rect x="3" y="14" width="7" height="7" rx="2"/><rect x="14" y="14" width="7" height="7" rx="2"/></svg>
                Applications
            </Link>
            <span class="flex cursor-default items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium text-slate-500">
                <svg viewBox="0 0 24 24" class="h-4.5 w-4.5" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 3 4 7v5c0 5 3.4 8 8 9 4.6-1 8-4 8-9V7l-8-4Z"/><path d="m9 12 2 2 4-4"/></svg>
                Team access <span class="ml-auto rounded bg-white/5 px-1.5 py-0.5 text-[9px] uppercase">Soon</span>
            </span>
        </nav>

        <div class="mt-auto border-t border-white/10 pt-4">
            <div class="flex items-center gap-3 px-2">
                <span class="grid h-9 w-9 place-items-center rounded-full bg-indigo-400/15 text-xs font-bold text-indigo-300">{initials(auth.user.name)}</span>
                <div class="min-w-0 flex-1"><p class="truncate text-xs font-semibold">{auth.user.name}</p><p class="truncate text-[11px] text-slate-500">{auth.user.email}</p></div>
                <button aria-label="Sign out" title="Sign out" class="ghost p-2 text-slate-500 hover:bg-white/5 hover:text-white" on:click={logout}>
                    <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 17l5-5-5-5M15 12H3"/><path d="M14 3h5a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-5"/></svg>
                </button>
            </div>
        </div>
    </aside>

    <div class="min-w-0">
        <header class="flex items-center justify-between border-b border-slate-200 bg-white px-4 py-3 lg:hidden">
            <Link href={appsUrl} class="flex items-center gap-2 text-sm font-bold"><span class="grid h-7 w-7 place-items-center rounded-lg bg-indigo-600 text-xs text-white">AM</span> App Manager</Link>
            <button class="ghost px-2.5 py-1.5" on:click={logout}>Sign out</button>
        </header>
        <slot />
    </div>
</div>

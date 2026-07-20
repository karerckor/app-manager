<script>
    import { Link } from '@inertiajs/svelte';
    import { ScrollText } from '@lucide/svelte';
    import AppShell from '../../Components/AppShell.svelte';

    export let auth;
    export let flash = {};
    export let navigationTeams = [];
    export let navigationTeam = null;
    export let audits = {};

    const formatDate = (value) => value
        ? new Intl.DateTimeFormat('en', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value))
        : '—';
    const shortId = (value) => typeof value === 'string' && value.length > 8 ? `${value.slice(0, 8)}…` : value;
</script>

<svelte:head><title>Audit log · App Manager</title></svelte:head>

<AppShell {auth} teams={navigationTeams} currentTeam={navigationTeam} active="audit">
    <main class="mx-auto max-w-[1120px] px-5 py-8 sm:px-8 lg:px-10 lg:py-10">
        <div class="mb-8 flex flex-wrap items-end justify-between gap-4">
            <div>
                <p class="eyebrow mb-2">Workspace security</p>
                <h1 class="text-3xl font-bold tracking-tight text-slate-950">Audit log</h1>
                <p class="mt-2 max-w-2xl text-sm text-slate-500">A record of changes made across {navigationTeam?.name}.</p>
            </div>
        </div>

        {#if flash?.status}
            <div class="mb-6 flex items-center gap-3 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800"><span class="grid h-5 w-5 place-items-center rounded-full bg-emerald-500 text-xs text-white">✓</span>{flash.status}</div>
        {/if}

        <section class="card overflow-hidden p-0">
            <div class="border-b border-slate-100 px-5 py-4">
                <h2 class="text-sm font-bold">Activity for {navigationTeam?.name}</h2>
                <p class="mt-1 text-xs text-slate-500">Every recorded change to this workspace's apps, builds, and credentials.</p>
            </div>

            {#if audits.data?.length}
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead>
                            <tr class="border-b border-slate-100 text-[11px] font-semibold tracking-wide text-slate-400 uppercase">
                                <th class="px-5 py-3">Actor</th>
                                <th class="px-5 py-3">Event</th>
                                <th class="px-5 py-3">Target</th>
                                <th class="px-5 py-3">Source</th>
                                <th class="px-5 py-3">When</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            {#each audits.data as entry}
                                <tr>
                                    <td class="px-5 py-3.5 text-slate-700">{entry.actor?.name ?? '—'}</td>
                                    <td class="px-5 py-3.5"><span class="rounded-full bg-slate-100 px-2.5 py-1 text-[11px] font-medium text-slate-600">{entry.event}</span></td>
                                    <td class="px-5 py-3.5 text-slate-700">{entry.auditable_type} #{shortId(entry.auditable_id)}</td>
                                    <td class="px-5 py-3.5 text-slate-500">{entry.source ?? '—'}</td>
                                    <td class="px-5 py-3.5 text-slate-500">{formatDate(entry.created_at)}</td>
                                </tr>
                            {/each}
                        </tbody>
                    </table>
                </div>

                <div class="flex items-center justify-between border-t border-slate-100 px-5 py-4">
                    {#if audits.prev_page_url}
                        <Link href={audits.prev_page_url} class="secondary" preserveScroll>Previous</Link>
                    {:else}
                        <span class="secondary pointer-events-none opacity-40">Previous</span>
                    {/if}
                    {#if audits.next_page_url}
                        <Link href={audits.next_page_url} class="secondary" preserveScroll>Next</Link>
                    {:else}
                        <span class="secondary pointer-events-none opacity-40">Next</span>
                    {/if}
                </div>
            {:else}
                <div class="px-6 py-16 text-center">
                    <span class="mx-auto mb-4 grid h-14 w-14 place-items-center rounded-2xl bg-indigo-50 text-indigo-600"><ScrollText class="h-6 w-6" strokeWidth={1.7} /></span>
                    <h3 class="text-sm font-bold">No activity recorded yet</h3>
                    <p class="mx-auto mt-2 max-w-sm text-sm text-slate-500">Changes to apps, builds, and credentials in this workspace will show up here.</p>
                </div>
            {/if}
        </section>
    </main>
</AppShell>

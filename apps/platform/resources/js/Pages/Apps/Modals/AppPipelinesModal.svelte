<script>
    import { Link, router } from '@inertiajs/svelte';
    import { GitBranch, Pencil, Play, Plus, Trash2 } from '@lucide/svelte';

    export let open = false;
    export let app;
    export let configuration = null;
    export let onClose = () => {};
    export let onConfigure = () => {};
    export let onRun = () => {};

    const removePipeline = (pipeline) => {
        if (confirm(`Remove pipeline “${pipeline.name}”? Historical builds will be preserved.`)) {
            router.delete(pipeline.destroy_url, { preserveScroll: true });
        }
    };
</script>

{#if open && configuration}
    <div class="modal-backdrop" role="presentation" on:click={(event) => event.target === event.currentTarget && onClose()}>
        <div class="modal max-w-3xl" role="dialog" aria-modal="true" aria-labelledby="app-pipelines-title">
            <div class="flex items-start justify-between border-b border-border-subtle px-6 py-5">
                <div>
                    <p class="eyebrow mb-1">{app.name}</p>
                    <h2 id="app-pipelines-title" class="text-lg font-bold">Build pipelines</h2>
                    <p class="mt-1 text-sm text-muted">Configure provider pipelines for this application only.</p>
                </div>
                <button type="button" class="ghost p-2" aria-label="Close pipelines" on:click={onClose}>✕</button>
            </div>

            <div class="max-h-[70vh] space-y-4 overflow-y-auto p-6">
                {#if configuration.integrations?.length}
                    {#each configuration.integrations as integration}
                        <article class="overflow-hidden rounded-md border border-border bg-surface-raised">
                            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-border-subtle px-4 py-3">
                                <div class="flex min-w-0 items-center gap-3">
                                    <span class="grid h-8 w-8 shrink-0 place-items-center rounded-md bg-text font-mono text-[9px] font-black text-white">{integration.provider === 'github' ? 'GH' : integration.provider === 'gitlab' ? 'GL' : 'EAS'}</span>
                                    <div class="min-w-0">
                                        <h3 class="truncate text-xs font-bold text-text">{integration.name}</h3>
                                        <p class="mt-0.5 truncate font-mono text-[10px] text-faint">{integration.provider_name}{#if integration.account_name} · {integration.account_name}{/if}</p>
                                    </div>
                                </div>
                                <button type="button" class="flex items-center gap-1.5 px-3 py-2 text-xs" on:click={() => onConfigure(integration, null)}>
                                    <Plus class="h-3.5 w-3.5" strokeWidth={2.2} />
                                    Add pipeline
                                </button>
                            </div>

                            {#if integration.pipelines.length}
                                <div class="divide-y divide-border-subtle">
                                    {#each integration.pipelines as pipeline}
                                        <div class="grid gap-3 px-4 py-3 md:grid-cols-[minmax(0,1fr)_auto] md:items-center">
                                            <div class="flex min-w-0 items-start gap-3">
                                                <span class="mt-0.5 grid h-7 w-7 shrink-0 place-items-center rounded-md bg-brand-soft text-brand ring-1 ring-inset ring-brand-soft-line"><GitBranch class="h-3.5 w-3.5" strokeWidth={1.9} /></span>
                                                <div class="min-w-0">
                                                    <div class="flex min-w-0 flex-wrap items-center gap-1.5">
                                                        <p class="truncate text-xs font-semibold text-text">{pipeline.name}</p>
                                                        <span class="rounded bg-brand-soft px-1.5 py-0.5 text-[9px] font-bold text-brand uppercase">{pipeline.reporting_mode}</span>
                                                        {#if !pipeline.enabled}<span class="rounded bg-border-subtle px-1.5 py-0.5 text-[9px] font-bold text-muted">Disabled</span>{/if}
                                                    </div>
                                                    <p class="mt-0.5 truncate font-mono text-[10px] text-faint">{pipeline.repository_full_name} · {pipeline.workflow_name} · {pipeline.default_ref}</p>
                                                    <p class="mt-1 truncate text-[10px] text-muted">{pipeline.target_name || 'All targets'} · {pipeline.profile_type}</p>
                                                </div>
                                            </div>
                                            <div class="flex flex-wrap justify-end gap-1.5">
                                                <button type="button" class="flex items-center gap-1.5 px-2.5 py-2 text-xs" disabled={!pipeline.enabled} on:click={() => onRun(pipeline)}><Play class="h-3.5 w-3.5" fill="currentColor" />Run</button>
                                                <button type="button" class="secondary flex items-center gap-1.5 px-2.5 py-2 text-xs" on:click={() => onConfigure(integration, pipeline)}><Pencil class="h-3.5 w-3.5" />Edit</button>
                                                <button type="button" class="ghost flex items-center gap-1.5 px-2 py-2 text-xs text-danger" on:click={() => removePipeline(pipeline)}><Trash2 class="h-3.5 w-3.5" />Remove</button>
                                            </div>
                                        </div>
                                    {/each}
                                </div>
                            {:else}
                                <div class="flex items-center gap-3 px-4 py-4 text-faint">
                                    <span class="grid h-7 w-7 shrink-0 place-items-center rounded-md bg-border-subtle"><GitBranch class="h-3.5 w-3.5" strokeWidth={1.8} /></span>
                                    <div><p class="text-xs font-semibold text-muted">No pipeline for {app.name}</p><p class="mt-0.5 text-[10px]">Choose a repository and provider workflow to enable automatic builds.</p></div>
                                </div>
                            {/if}
                        </article>
                    {/each}
                {:else}
                    <div class="rounded-md border border-dashed border-border px-6 py-12 text-center">
                        <span class="mx-auto grid h-10 w-10 place-items-center rounded-md bg-brand-soft text-brand"><GitBranch class="h-5 w-5" strokeWidth={1.8} /></span>
                        <h3 class="mt-4 text-sm font-bold text-text">Connect a CI/CD provider first</h3>
                        <p class="mx-auto mt-1 max-w-sm text-xs leading-5 text-muted">GitHub Actions, GitLab CI/CD, and Expo EAS connections are managed at workspace level.</p>
                        <Link href={configuration.integrations_url} class="mt-4 inline-flex rounded-md bg-brand px-3 py-2 text-xs font-semibold text-white hover:bg-brand-hover">Open Integrations</Link>
                    </div>
                {/if}
            </div>

            <div class="flex justify-end border-t border-border-subtle bg-surface-sunken px-6 py-4">
                <button type="button" class="secondary" on:click={onClose}>Done</button>
            </div>
        </div>
    </div>
{/if}

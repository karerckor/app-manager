<script>
    import { router, useForm } from '@inertiajs/svelte';
    import { formatMetadata, parseMetadata } from '../lib.js';

    export let app;
    export let open;
    export let onClose;

    let confirmingDelete = false;
    let deleting = false;
    let metadataText = formatMetadata(app.metadata);
    let metadataError = '';

    const editForm = useForm({ name: app.name, description: app.description ?? '', metadata: app.metadata ?? null, blueprint_id: app.blueprint_id ?? null });

    let wasOpen = false;
    $: if (open !== wasOpen) {
        wasOpen = open;

        if (open) {
            editForm.name = app.name;
            editForm.description = app.description ?? '';
            editForm.metadata = app.metadata ?? null;
            editForm.blueprint_id = app.blueprint_id ?? null;
            metadataText = formatMetadata(app.metadata);
            metadataError = '';
            editForm.clearErrors();
            confirmingDelete = false;
        }
    }

    const closeEdit = () => {
        confirmingDelete = false;
        metadataError = '';
        editForm.clearErrors();
        onClose();
    };
    const saveEdit = () => {
        const metadata = parseMetadata(metadataText, (message) => metadataError = message);

        if (metadata === undefined) return;

        editForm.metadata = metadata;
        editForm.patch(app.urls.update, { preserveScroll: true, onSuccess: closeEdit });
    };
    const deleteApp = () => router.delete(app.urls.destroy, { onStart: () => deleting = true, onFinish: () => deleting = false });
</script>

{#if open}
    <div class="modal-backdrop" role="presentation" on:click={(event) => event.target === event.currentTarget && closeEdit()}>
        <form class="modal" on:submit|preventDefault={saveEdit}>
            <div class="flex items-start justify-between border-b border-border-subtle px-6 py-5">
                <div><h2 class="text-lg font-bold">Edit application</h2><p class="mt-1 text-sm text-muted">Update the project details and metadata.</p></div>
                <button type="button" class="ghost -mr-2 p-2" on:click={closeEdit}>✕</button>
            </div>
            <div class="space-y-5 p-6">
                <label><span class="label">Name</span><input bind:value={editForm.name} required />{#if editForm.errors.name}<span class="error">{editForm.errors.name}</span>{/if}</label>
                <label><span class="label">Description <span class="font-normal text-faint">(optional)</span></span><textarea class="min-h-24" bind:value={editForm.description} placeholder="What is this project?"></textarea>{#if editForm.errors.description}<span class="error">{editForm.errors.description}</span>{/if}</label>
                <label>
                    <span class="label">Blueprint <span class="font-normal text-faint">(optional)</span></span>
                    <select bind:value={editForm.blueprint_id}>
                        <option value={null}>No blueprint</option>
                        {#each app.blueprint_options ?? [] as blueprint}
                            <option value={blueprint.value}>{blueprint.label}</option>
                        {/each}
                    </select>
                    <span class="mt-1 block text-2xs text-faint">Attach now or later. Missing JSON, files, and platforms will not block saving.</span>
                    {#if editForm.errors.blueprint_id}<span class="error">{editForm.errors.blueprint_id}</span>{/if}
                </label>
                <label><span class="label">Metadata (JSON) <span class="font-normal text-faint">(optional)</span></span><textarea class="min-h-40 font-mono text-xs leading-5" bind:value={metadataText} spellcheck="false" placeholder={'{\n  "owner": "mobile-team",\n  "channel": "production"\n}'}></textarea><span class="mt-1 block text-[10px] text-faint">Add any JSON object with application-specific values.</span>{#if metadataError || editForm.errors.metadata}<span class="error">{metadataError || editForm.errors.metadata}</span>{/if}</label>
                <div class="rounded-md border border-danger/30 bg-danger/10 p-4">
                    {#if confirmingDelete}
                        <p class="text-xs font-semibold text-danger">Delete this application? This can't be undone.</p>
                        <div class="mt-3 flex gap-2">
                            <button type="button" class="secondary px-3 py-2 text-xs" on:click={() => confirmingDelete = false}>Cancel</button>
                            <button type="button" class="danger px-3 py-2 text-xs" disabled={deleting} on:click={deleteApp}>Confirm delete</button>
                        </div>
                    {:else}
                        <div class="flex items-center justify-between gap-3">
                            <div><p class="text-xs font-bold text-danger">Danger zone</p><p class="mt-0.5 text-[11px] text-danger">Permanently delete this application and its data.</p></div>
                            <button type="button" class="secondary border-danger/40 text-danger hover:border-danger hover:bg-danger/10" on:click={() => confirmingDelete = true}>Delete application</button>
                        </div>
                    {/if}
                </div>
            </div>
            <div class="flex justify-end gap-3 border-t border-border-subtle bg-surface-sunken px-6 py-4"><button type="button" class="secondary" on:click={closeEdit}>Cancel</button><button disabled={editForm.processing}>{editForm.processing ? 'Saving…' : 'Save changes'}</button></div>
        </form>
    </div>
{/if}

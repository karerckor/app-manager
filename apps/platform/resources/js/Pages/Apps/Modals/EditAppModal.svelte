<script>
    import { router, useForm } from '@inertiajs/svelte';

    export let app;
    export let open;
    export let onClose;

    let confirmingDelete = false;
    let deleting = false;

    const editForm = useForm({ name: app.name, description: app.description ?? '' });

    let wasOpen = false;
    $: if (open !== wasOpen) {
        wasOpen = open;

        if (open) {
            editForm.name = app.name;
            editForm.description = app.description ?? '';
            editForm.clearErrors();
            confirmingDelete = false;
        }
    }

    const closeEdit = () => {
        confirmingDelete = false;
        editForm.clearErrors();
        onClose();
    };
    const saveEdit = () => editForm.patch(app.urls.update, { preserveScroll: true, onSuccess: closeEdit });
    const deleteApp = () => router.delete(app.urls.destroy, { onStart: () => deleting = true, onFinish: () => deleting = false });
</script>

{#if open}
    <div class="modal-backdrop" role="presentation" on:click={(event) => event.target === event.currentTarget && closeEdit()}>
        <form class="modal" on:submit|preventDefault={saveEdit}>
            <div class="flex items-start justify-between border-b border-slate-100 px-6 py-5">
                <div><h2 class="text-lg font-bold">Edit application</h2><p class="mt-1 text-sm text-slate-500">Update the project name and description.</p></div>
                <button type="button" class="ghost -mr-2 p-2" on:click={closeEdit}>✕</button>
            </div>
            <div class="space-y-5 p-6">
                <label><span class="label">Name</span><input bind:value={editForm.name} required />{#if editForm.errors.name}<span class="error">{editForm.errors.name}</span>{/if}</label>
                <label><span class="label">Description <span class="font-normal text-slate-400">(optional)</span></span><textarea class="min-h-24" bind:value={editForm.description} placeholder="What is this project?"></textarea>{#if editForm.errors.description}<span class="error">{editForm.errors.description}</span>{/if}</label>
                <div class="rounded-xl border border-rose-200 bg-rose-50/60 p-4">
                    {#if confirmingDelete}
                        <p class="text-xs font-semibold text-rose-800">Delete this application? This can't be undone.</p>
                        <div class="mt-3 flex gap-2">
                            <button type="button" class="secondary px-3 py-2 text-xs" on:click={() => confirmingDelete = false}>Cancel</button>
                            <button type="button" class="bg-rose-600 px-3 py-2 text-xs text-white hover:bg-rose-700" disabled={deleting} on:click={deleteApp}>Confirm delete</button>
                        </div>
                    {:else}
                        <div class="flex items-center justify-between gap-3">
                            <div><p class="text-xs font-bold text-rose-800">Danger zone</p><p class="mt-0.5 text-[11px] text-rose-700/80">Permanently delete this application and its data.</p></div>
                            <button type="button" class="border border-rose-300 bg-white px-3 py-2 text-xs font-semibold text-rose-700 hover:bg-rose-100" on:click={() => confirmingDelete = true}>Delete application</button>
                        </div>
                    {/if}
                </div>
            </div>
            <div class="flex justify-end gap-3 border-t border-slate-100 bg-slate-50/70 px-6 py-4"><button type="button" class="secondary" on:click={closeEdit}>Cancel</button><button disabled={editForm.processing}>{editForm.processing ? 'Saving…' : 'Save changes'}</button></div>
        </form>
    </div>
{/if}

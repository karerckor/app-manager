<script>
    import { useForm } from '@inertiajs/svelte';

    export let selection = null;
    export let url = '';
    export let onClose;
    export let onComplete;

    let lastSelection = null;
    const renameForm = useForm({ pipeline_ids: [], name: '' });

    $: if (selection !== lastSelection) {
        lastSelection = selection;

        if (selection) {
            const names = [...new Set(selection.pipelines.map((pipeline) => pipeline.name))];
            renameForm.pipeline_ids = selection.pipelines.map((pipeline) => pipeline.id);
            renameForm.name = names.length === 1 ? names[0] : '';
            renameForm.clearErrors();
        }
    }

    const close = () => {
        renameForm.clearErrors();
        onClose();
    };

    const finish = () => {
        if (onComplete) onComplete();
        close();
    };

    const submit = () => renameForm.patch(url, {
        preserveScroll: true,
        onSuccess: finish,
    });
</script>

{#if selection}
    <div class="modal-backdrop" role="presentation" on:click={(event) => event.target === event.currentTarget && close()}>
        <form class="modal max-w-md" on:submit|preventDefault={submit}>
            <div class="flex items-start justify-between border-b border-slate-100 px-6 py-5">
                <div><h2 class="text-lg font-bold">Rename selected pipelines</h2><p class="mt-1 text-sm text-slate-500">Set one name for {selection.pipelines.length} application bindings.</p></div>
                <button type="button" class="ghost -mr-2 p-2" on:click={close}>✕</button>
            </div>
            <div class="space-y-4 p-6">
                <div class="flex flex-wrap gap-2">{#each selection.pipelines as pipeline}<span class="rounded-full bg-slate-100 px-2.5 py-1 text-[10px] font-semibold text-slate-600">{pipeline.app_name}</span>{/each}</div>
                <label class="block"><span class="label">Pipeline name</span><input bind:value={renameForm.name} required />{#if renameForm.errors.name}<span class="error">{renameForm.errors.name}</span>{/if}</label>
                {#if renameForm.errors.pipeline_ids}<span class="error">{renameForm.errors.pipeline_ids}</span>{/if}
            </div>
            <div class="flex justify-end gap-3 border-t border-slate-100 bg-slate-50/70 px-6 py-4">
                <button type="button" class="secondary" on:click={close}>Cancel</button>
                <button disabled={renameForm.processing}>{renameForm.processing ? 'Saving…' : 'Rename selected'}</button>
            </div>
        </form>
    </div>
{/if}

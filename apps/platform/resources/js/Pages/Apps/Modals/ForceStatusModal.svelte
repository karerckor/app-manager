<script>
    import { useForm } from '@inertiajs/svelte';
    import Select from '../../../Components/Select.svelte';

    export let build = null;
    export let onClose;

    const forceStatusForm = useForm({ status: 'cancelled' });

    const openForceStatus = (currentBuild) => {
        forceStatusForm.status = currentBuild.status === 'cancel_requested' ? 'cancelled' : 'failed';
        forceStatusForm.clearErrors();
    };

    let lastBuild = null;
    $: if (build !== lastBuild) {
        lastBuild = build;

        if (build) openForceStatus(build);
    }

    const closeForceStatus = () => {
        forceStatusForm.clearErrors();
        onClose();
    };
    const forceBuildStatus = () => forceStatusForm.patch(build.force_status_url, {
        preserveScroll: true,
        onSuccess: closeForceStatus,
    });
</script>

{#if build}
    <div class="modal-backdrop" role="presentation" on:click={(event) => event.target === event.currentTarget && closeForceStatus()}>
        <form class="modal max-w-lg" aria-labelledby="force-status-title" on:submit|preventDefault={forceBuildStatus}>
            <div class="flex items-start justify-between border-b border-border-subtle px-6 py-5"><div><h2 id="force-status-title" class="text-lg font-bold">Force set build status</h2><p class="mt-1 text-sm text-muted">Use this when the manual runner will not report a final status.</p></div><button type="button" class="ghost -mr-2 p-2" aria-label="Close" on:click={closeForceStatus}>✕</button></div>
            <div class="space-y-5 p-6">
                <div class="rounded-md border border-warn-fill/40 bg-warn-fill/10 px-4 py-3 text-xs leading-5 text-warn-text">This bypasses runner confirmation and finishes the build immediately. A build completion webhook will be sent.</div>
                <label><span class="label">Final status</span><Select bind:value={forceStatusForm.status} options={[{ value: 'completed', label: 'Completed' }, { value: 'failed', label: 'Failed' }, { value: 'cancelled', label: 'Cancelled' }]} ariaLabel="Final status" menuPlacement="top" />{#if forceStatusForm.errors.status}<span class="error">{forceStatusForm.errors.status}</span>{/if}</label>
            </div>
            <div class="flex justify-end gap-3 border-t border-border-subtle bg-surface-sunken px-6 py-4"><button type="button" class="secondary" on:click={closeForceStatus}>Keep waiting</button><button class="bg-warn-fill text-nav hover:bg-warn-fill/90" disabled={forceStatusForm.processing}>{forceStatusForm.processing ? 'Updating…' : 'Force status'}</button></div>
        </form>
    </div>
{/if}

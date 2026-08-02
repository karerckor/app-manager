<script>
    import { TriangleAlert, X } from '@lucide/svelte';
    import { tick } from 'svelte';

    export let open = false;
    export let title = 'Confirm action';
    export let description = '';
    export let confirmLabel = 'Confirm';
    export let busyLabel = 'Working…';
    export let cancelLabel = 'Cancel';
    export let busy = false;
    export let onConfirm = () => {};
    export let onCancel = () => {};

    let cancelButton;
    let wasOpen = false;

    $: if (open && !wasOpen) {
        wasOpen = true;
        tick().then(() => cancelButton?.focus());
    } else if (!open && wasOpen) {
        wasOpen = false;
    }

    const close = () => {
        if (!busy) onCancel();
    };

    const handleKeydown = (event) => {
        if (event.key === 'Escape') close();
    };
</script>

<svelte:window on:keydown={(event) => open && handleKeydown(event)} />

{#if open}
    <div class="modal-backdrop" role="presentation" on:click={(event) => event.target === event.currentTarget && close()}>
        <div class="modal max-w-lg" role="dialog" aria-modal="true" aria-labelledby="confirm-dialog-title" aria-describedby="confirm-dialog-description">
            <div class="flex items-start justify-between border-b border-border-subtle px-6 py-5">
                <div class="flex items-start gap-3">
                    <span class="grid h-10 w-10 shrink-0 place-items-center rounded-sm bg-danger/10 text-danger">
                        <TriangleAlert class="h-5 w-5" />
                    </span>
                    <div>
                        <h2 id="confirm-dialog-title" class="text-lg font-bold text-text">{title}</h2>
                        <p id="confirm-dialog-description" class="mt-1 text-sm leading-6 text-muted">{description}</p>
                    </div>
                </div>
                <button type="button" class="ghost -mr-2 p-2" aria-label="Close confirmation" disabled={busy} on:click={close}>
                    <X class="h-4 w-4" />
                </button>
            </div>

            <div class="flex justify-end gap-3 bg-surface-sunken px-6 py-4">
                <button bind:this={cancelButton} type="button" class="secondary" disabled={busy} on:click={close}>{cancelLabel}</button>
                <button type="button" class="danger" disabled={busy} on:click={onConfirm}>{busy ? busyLabel : confirmLabel}</button>
            </div>
        </div>
    </div>
{/if}

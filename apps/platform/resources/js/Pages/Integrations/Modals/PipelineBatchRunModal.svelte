<script>
    import { useForm } from '@inertiajs/svelte';
    import { firstFormError, formatParameters, parseParameters } from '../../Apps/lib.js';

    export let selection = null;
    export let url = '';
    export let onClose;
    export let onComplete;

    let mode = 'shared';
    let sharedRef = '';
    let sharedInputsText = '{}';
    let sharedInputsError = '';
    let overrides = [];
    let lastSelection = null;

    const batchRunForm = useForm({ runs: [] });

    const initialize = (current) => {
        const first = current.pipelines[0];
        mode = 'shared';
        sharedRef = first.default_ref;
        sharedInputsText = formatParameters(first.input_defaults);
        sharedInputsError = '';
        overrides = current.pipelines.map((pipeline) => ({
            pipeline,
            request_uuid: crypto.randomUUID(),
            ref: pipeline.default_ref,
            inputsText: formatParameters(pipeline.input_defaults),
            error: '',
        }));
        batchRunForm.runs = [];
        batchRunForm.clearErrors();
    };

    $: if (selection !== lastSelection) {
        lastSelection = selection;
        if (selection) initialize(selection);
    }

    const close = () => {
        batchRunForm.clearErrors();
        sharedInputsError = '';
        onClose();
    };

    const finish = () => {
        if (onComplete) onComplete();
        close();
    };

    const submit = () => {
        let runs;

        if (mode === 'shared') {
            const inputs = parseParameters(sharedInputsText, (message) => sharedInputsError = message);
            if (inputs === null) return;

            runs = overrides.map((override) => ({
                pipeline_id: override.pipeline.id,
                request_uuid: override.request_uuid,
                ref: sharedRef,
                inputs,
            }));
        } else {
            let invalid = false;
            runs = overrides.map((override) => {
                const inputs = parseParameters(override.inputsText, (message) => override.error = message);
                if (inputs === null) invalid = true;

                return {
                    pipeline_id: override.pipeline.id,
                    request_uuid: override.request_uuid,
                    ref: override.ref,
                    inputs: inputs ?? {},
                };
            });
            overrides = [...overrides];
            if (invalid) return;
        }

        batchRunForm.runs = runs;
        batchRunForm.post(url, {
            preserveScroll: true,
            onSuccess: finish,
        });
    };
</script>

{#if selection}
    <div class="modal-backdrop" role="presentation" on:click={(event) => event.target === event.currentTarget && close()}>
        <form class="modal max-w-2xl" on:submit|preventDefault={submit}>
            <div class="flex items-start justify-between border-b border-slate-100 px-6 py-5">
                <div>
                    <h2 class="text-lg font-bold">Run {selection.pipelines.length} selected builds</h2>
                    <p class="mt-1 text-sm text-slate-500">{selection.pipelines.map((pipeline) => pipeline.app_name).join(', ')}</p>
                </div>
                <button type="button" class="ghost -mr-2 p-2" on:click={close}>✕</button>
            </div>

            <div class="space-y-5 p-6">
                <fieldset>
                    <legend class="label">Parameters</legend>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <label class="flex cursor-pointer items-start gap-3 rounded-xl border p-4 transition {mode === 'shared' ? 'border-indigo-400 bg-indigo-50 ring-1 ring-indigo-400' : 'border-slate-200'}">
                            <input class="mt-0.5 h-4 w-4 shrink-0 accent-indigo-600 shadow-none" type="radio" bind:group={mode} value="shared" />
                            <span><strong class="block text-sm text-slate-900">Same for all</strong><span class="mt-1 block text-xs leading-5 text-slate-500">One branch and input set is used for every selected application.</span></span>
                        </label>
                        <label class="flex cursor-pointer items-start gap-3 rounded-xl border p-4 transition {mode === 'per_app' ? 'border-indigo-400 bg-indigo-50 ring-1 ring-indigo-400' : 'border-slate-200'}">
                            <input class="mt-0.5 h-4 w-4 shrink-0 accent-indigo-600 shadow-none" type="radio" bind:group={mode} value="per_app" />
                            <span><strong class="block text-sm text-slate-900">Per-app overrides</strong><span class="mt-1 block text-xs leading-5 text-slate-500">Customize branch and inputs separately for each application.</span></span>
                        </label>
                    </div>
                </fieldset>

                {#if mode === 'shared'}
                    <label class="block"><span class="label">Branch or tag</span><input bind:value={sharedRef} required /></label>
                    <label class="block"><span class="label">Workflow inputs (JSON)</span><textarea class="min-h-44 font-mono text-xs leading-5" bind:value={sharedInputsText} spellcheck="false" placeholder={'{}'}></textarea>{#if sharedInputsError}<span class="error">{sharedInputsError}</span>{/if}</label>
                {:else}
                    <div class="space-y-4">
                        {#each overrides as override, index}
                            <section class="rounded-xl border border-slate-200 bg-slate-50/70 p-4">
                                <div class="mb-3"><p class="text-sm font-semibold text-slate-900">{override.pipeline.app_name}</p><p class="mt-0.5 text-xs text-slate-500">{override.pipeline.name}</p></div>
                                <div class="space-y-3">
                                    <label class="block"><span class="label">Branch or tag</span><input bind:value={override.ref} required /></label>
                                    <label class="block"><span class="label">Workflow inputs (JSON)</span><textarea class="min-h-32 font-mono text-xs leading-5" bind:value={override.inputsText} spellcheck="false" placeholder={'{}'}></textarea>{#if override.error || firstFormError(batchRunForm, `runs.${index}`)}<span class="error">{override.error || firstFormError(batchRunForm, `runs.${index}`)}</span>{/if}</label>
                                </div>
                            </section>
                        {/each}
                    </div>
                {/if}

                {#if Object.keys(selection.pipelines[0].input_schema ?? {}).length}
                    <div class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3">
                        <p class="text-[10px] font-bold tracking-wider text-slate-500 uppercase">Declared inputs</p>
                        <div class="mt-2 flex flex-wrap gap-2">{#each Object.entries(selection.pipelines[0].input_schema) as [name, definition]}<span class="rounded-md bg-white px-2 py-1 font-mono text-[10px] text-slate-600 ring-1 ring-slate-200">{name}: {definition.type || 'string'}{definition.required ? ' · required' : ''}</span>{/each}</div>
                    </div>
                {/if}

                {#if firstFormError(batchRunForm, 'runs')}<span class="error">{firstFormError(batchRunForm, 'runs')}</span>{/if}
            </div>

            <div class="flex justify-end gap-3 border-t border-slate-100 bg-slate-50/70 px-6 py-4">
                <button type="button" class="secondary" on:click={close}>Cancel</button>
                <button disabled={batchRunForm.processing}>{batchRunForm.processing ? 'Starting…' : `Run ${selection.pipelines.length} builds`}</button>
            </div>
        </form>
    </div>
{/if}

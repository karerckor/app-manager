<script>
    import { useForm } from '@inertiajs/svelte';
    import { formatParameters, parseParameters, firstFormError } from '../lib.js';

    export let pipeline = null;
    export let onClose;

    let pipelineParametersText = '{}';
    let pipelineParametersError = '';

    const pipelineBuildForm = useForm({ request_uuid: '', ref: '', inputs: {} });

    const openPipelineBuild = (currentPipeline) => {
        pipelineBuildForm.request_uuid = crypto.randomUUID();
        pipelineBuildForm.ref = currentPipeline.default_ref;
        pipelineBuildForm.inputs = currentPipeline.input_defaults ?? {};
        pipelineBuildForm.clearErrors();
        pipelineParametersText = formatParameters(currentPipeline.input_defaults);
        pipelineParametersError = '';
    };

    let lastPipeline = null;
    $: if (pipeline !== lastPipeline) {
        lastPipeline = pipeline;

        if (pipeline) openPipelineBuild(pipeline);
    }

    const closePipelineBuild = () => {
        pipelineBuildForm.clearErrors();
        pipelineParametersError = '';
        onClose();
    };
    const runPipeline = () => {
        const inputs = parseParameters(pipelineParametersText, (message) => pipelineParametersError = message);

        if (inputs === null) return;

        pipelineBuildForm.inputs = inputs;
        pipelineBuildForm.post(pipeline.run_url, {
            preserveScroll: true,
            onSuccess: closePipelineBuild,
        });
    };
</script>

{#if pipeline}
    <div class="modal-backdrop" role="presentation" on:click={(event) => event.target === event.currentTarget && closePipelineBuild()}>
        <form class="modal" on:submit|preventDefault={runPipeline}>
            <div class="flex items-start justify-between border-b border-slate-100 px-6 py-5"><div><h2 class="text-lg font-bold">Run {pipeline.name}</h2><p class="mt-1 text-sm text-slate-500">{pipeline.repository} · {pipeline.workflow}</p></div><button type="button" class="ghost -mr-2 p-2" on:click={closePipelineBuild}>✕</button></div>
            <div class="space-y-5 p-6">
                <label><span class="label">Branch or tag</span><input bind:value={pipelineBuildForm.ref} required />{#if pipelineBuildForm.errors.ref}<span class="error">{pipelineBuildForm.errors.ref}</span>{/if}</label>
                <label><span class="label">Workflow inputs (JSON)</span><textarea class="min-h-52 font-mono text-xs leading-5" bind:value={pipelineParametersText} spellcheck="false" placeholder={'{}'}></textarea><span class="mt-1 block text-[10px] text-slate-400">Only inputs declared by the GitHub Actions workflow are accepted. Resolved values are saved with the build and included in webhooks.</span>{#if pipelineParametersError || firstFormError(pipelineBuildForm, 'inputs')}<span class="error">{pipelineParametersError || firstFormError(pipelineBuildForm, 'inputs')}</span>{/if}</label>
                {#if Object.keys(pipeline.input_schema ?? {}).length}<div class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3"><p class="text-[10px] font-bold tracking-wider text-slate-500 uppercase">Declared inputs</p><div class="mt-2 flex flex-wrap gap-2">{#each Object.entries(pipeline.input_schema) as [name, definition]}<span class="rounded-md bg-white px-2 py-1 font-mono text-[10px] text-slate-600 ring-1 ring-slate-200">{name}: {definition.type || 'string'}{definition.required ? ' · required' : ''}</span>{/each}</div></div>{/if}
            </div>
            <div class="flex justify-end gap-3 border-t border-slate-100 bg-slate-50/70 px-6 py-4"><button type="button" class="secondary" on:click={closePipelineBuild}>Cancel</button><button disabled={pipelineBuildForm.processing}>{pipelineBuildForm.processing ? 'Starting…' : 'Run build'}</button></div>
        </form>
    </div>
{/if}

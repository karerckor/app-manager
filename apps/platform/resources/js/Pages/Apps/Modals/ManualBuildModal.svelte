<script>
    import { useForm } from '@inertiajs/svelte';
    import { parseParameters } from '../lib.js';
    import Select from '../../../Components/Select.svelte';

    export let app;
    export let identifiers = [];
    export let buildProfiles = [];
    export let open;
    export let onClose;

    let manualParametersText = '{}';
    let manualParametersError = '';

    const profilesForIdentifier = (identifierId) => {
        const identifier = identifiers.find((item) => String(item.id) === String(identifierId));

        if (identifier) return buildProfiles.filter((profile) => profile.platform === identifier.platform);

        return buildProfiles.filter((profile, index, items) => items.findIndex((item) => item.value === profile.value) === index);
    };
    const initialIdentifier = identifiers[0] ?? null;
    const initialManualProfiles = profilesForIdentifier(initialIdentifier?.id ?? '');
    const manualBuildForm = useForm({
        request_uuid: '',
        app_identifier_id: initialIdentifier?.id ?? '',
        profile_type: initialManualProfiles[0]?.value ?? '',
        parameters: {},
    });

    $: manualProfileOptions = profilesForIdentifier(manualBuildForm.app_identifier_id);

    const openManualBuild = () => {
        manualBuildForm.request_uuid = crypto.randomUUID();
        manualBuildForm.app_identifier_id = identifiers[0]?.id ?? '';
        manualBuildForm.profile_type = profilesForIdentifier(manualBuildForm.app_identifier_id)[0]?.value ?? '';
        manualBuildForm.parameters = {};
        manualBuildForm.clearErrors();
        manualParametersText = '{}';
        manualParametersError = '';
    };
    const closeManualBuild = () => {
        manualBuildForm.clearErrors();
        manualParametersError = '';
        onClose();
    };

    let wasOpen = false;
    $: if (open !== wasOpen) {
        wasOpen = open;

        if (open) openManualBuild();
    }

    const changeManualIdentifier = (event) => {
        manualBuildForm.app_identifier_id = event.detail;
        const profiles = profilesForIdentifier(manualBuildForm.app_identifier_id);

        if (!profiles.some((profile) => profile.value === manualBuildForm.profile_type)) {
            manualBuildForm.profile_type = profiles[0]?.value ?? '';
        }
    };
    const createManualBuild = () => {
        const parameters = parseParameters(manualParametersText, (message) => manualParametersError = message);

        if (parameters === null) return;

        manualBuildForm.parameters = parameters;
        manualBuildForm.post(app.urls.manual_builds, {
            preserveScroll: true,
            onSuccess: closeManualBuild,
        });
    };
</script>

{#if open}
    <div class="modal-backdrop" role="presentation" on:click={(event) => event.target === event.currentTarget && closeManualBuild()}>
        <form class="modal" on:submit|preventDefault={createManualBuild}>
            <div class="flex items-start justify-between border-b border-slate-100 px-6 py-5"><div><h2 class="text-lg font-bold">Create manual build</h2><p class="mt-1 text-sm text-slate-500">This creates a build directly in App Manager and does not start GitHub Actions.</p></div><button type="button" class="ghost -mr-2 p-2" on:click={closeManualBuild}>✕</button></div>
            <div class="space-y-5 p-6">
                <div class="grid gap-4 sm:grid-cols-2">
                    <label><span class="label">App identifier <span class="font-normal text-slate-400">(optional)</span></span><Select bind:value={manualBuildForm.app_identifier_id} options={[{ value: '', label: 'No app identifier' }, ...identifiers.map((identifier) => ({ value: identifier.id, label: `${identifier.platform.toUpperCase()} · ${identifier.identifier}` }))]} ariaLabel="App identifier" on:change={changeManualIdentifier} />{#if manualBuildForm.errors.app_identifier_id}<span class="error">{manualBuildForm.errors.app_identifier_id}</span>{/if}</label>
                    <label><span class="label">Build profile</span><Select bind:value={manualBuildForm.profile_type} options={manualProfileOptions.map((profile) => ({ value: profile.value, label: `${profile.label}${manualBuildForm.app_identifier_id ? '' : ` · ${profile.platform}`}` }))} ariaLabel="Build profile" required />{#if manualBuildForm.errors.profile_type}<span class="error">{manualBuildForm.errors.profile_type}</span>{/if}</label>
                </div>
                <label><span class="label">Parameters (JSON)</span><textarea class="min-h-48 font-mono text-xs leading-5" bind:value={manualParametersText} spellcheck="false" placeholder={'{\n  "channel": "internal"\n}'}></textarea><span class="mt-1 block text-[10px] text-slate-400">These values are saved with the build and included in its webhook payloads.</span>{#if manualParametersError || manualBuildForm.errors.parameters}<span class="error">{manualParametersError || manualBuildForm.errors.parameters}</span>{/if}</label>
            </div>
            <div class="flex justify-end gap-3 border-t border-slate-100 bg-slate-50/70 px-6 py-4"><button type="button" class="secondary" on:click={closeManualBuild}>Cancel</button><button disabled={manualBuildForm.processing || !manualBuildForm.profile_type}>{manualBuildForm.processing ? 'Creating…' : 'Create build'}</button></div>
        </form>
    </div>
{/if}

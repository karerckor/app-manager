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

    const platformOptions = [...new Set(buildProfiles.map((profile) => profile.platform))]
        .map((platform) => ({ value: platform, label: platform === 'ios' ? 'iOS' : 'Android' }));
    const mobileIdentifiers = identifiers.filter((identifier) => platformOptions.some((option) => option.value === identifier.platform));
    const initialPlatform = mobileIdentifiers.find((identifier) => identifier.is_default)?.platform
        ?? mobileIdentifiers[0]?.platform
        ?? platformOptions[0]?.value
        ?? 'ios';
    const profilesForPlatform = (platform) => buildProfiles.filter((profile) => profile.platform === platform);
    const identifiersForPlatform = (platform) => identifiers.filter((identifier) => identifier.platform === platform);
    const defaultIdentifierLabel = (platform) => {
        const candidates = identifiersForPlatform(platform);
        const target = candidates.find((identifier) => identifier.is_default)
            ?? (candidates.length === 1 ? candidates[0] : null);

        if (target) return `Default · ${target.identifier}`;
        if (candidates.length) return 'Default identifier · not set';

        return 'Default identifier · none configured';
    };
    const initialManualProfiles = profilesForPlatform(initialPlatform);
    const manualBuildForm = useForm({
        request_uuid: '',
        platform: initialPlatform,
        app_identifier_id: '',
        profile_type: initialManualProfiles[0]?.value ?? '',
        parameters: {},
    });

    $: platformIdentifiers = identifiersForPlatform(manualBuildForm.platform);
    $: manualProfileOptions = profilesForPlatform(manualBuildForm.platform);

    const openManualBuild = () => {
        manualBuildForm.request_uuid = crypto.randomUUID();
        manualBuildForm.platform = mobileIdentifiers.find((identifier) => identifier.is_default)?.platform
            ?? mobileIdentifiers[0]?.platform
            ?? platformOptions[0]?.value
            ?? 'ios';
        manualBuildForm.app_identifier_id = '';
        manualBuildForm.profile_type = profilesForPlatform(manualBuildForm.platform)[0]?.value ?? '';
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

    const changeManualPlatform = (event) => {
        manualBuildForm.platform = event.detail;
        manualBuildForm.app_identifier_id = '';
        manualBuildForm.profile_type = profilesForPlatform(manualBuildForm.platform)[0]?.value ?? '';
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
                    <label><span class="label">Platform</span><Select bind:value={manualBuildForm.platform} options={platformOptions} ariaLabel="Build platform" on:change={changeManualPlatform} required />{#if manualBuildForm.errors.platform}<span class="error">{manualBuildForm.errors.platform}</span>{/if}</label>
                    <label><span class="label">Build profile</span><Select bind:value={manualBuildForm.profile_type} options={manualProfileOptions.map((profile) => ({ value: profile.value, label: profile.label }))} ariaLabel="Build profile" required />{#if manualBuildForm.errors.profile_type}<span class="error">{manualBuildForm.errors.profile_type}</span>{/if}</label>
                </div>
                <label><span class="label">App identifier <span class="font-normal text-slate-400">(optional override)</span></span><Select bind:value={manualBuildForm.app_identifier_id} options={[{ value: '', label: defaultIdentifierLabel(manualBuildForm.platform) }, ...platformIdentifiers.map((identifier) => ({ value: identifier.id, label: `${identifier.identifier}${identifier.is_default ? ' · Default' : ''}` }))]} ariaLabel="App identifier" />{#if manualBuildForm.errors.app_identifier_id || manualBuildForm.errors.identifier}<span class="error">{manualBuildForm.errors.app_identifier_id || manualBuildForm.errors.identifier}</span>{/if}<span class="mt-1 block text-[10px] text-slate-400">Leave the override empty to use the platform's default identifier.</span></label>
                <label><span class="label">Parameters (JSON)</span><textarea class="min-h-48 font-mono text-xs leading-5" bind:value={manualParametersText} spellcheck="false" placeholder={'{\n  "channel": "internal"\n}'}></textarea><span class="mt-1 block text-[10px] text-slate-400">These values are saved with the build and included in its webhook payloads.</span>{#if manualParametersError || manualBuildForm.errors.parameters}<span class="error">{manualParametersError || manualBuildForm.errors.parameters}</span>{/if}</label>
            </div>
            <div class="flex justify-end gap-3 border-t border-slate-100 bg-slate-50/70 px-6 py-4"><button type="button" class="secondary" on:click={closeManualBuild}>Cancel</button><button disabled={manualBuildForm.processing || !manualBuildForm.profile_type}>{manualBuildForm.processing ? 'Creating…' : 'Create build'}</button></div>
        </form>
    </div>
{/if}

<script>
    import { onDestroy } from 'svelte';
    import { Link, router, useForm } from '@inertiajs/svelte';
    import { DraftingCompass, Plus, Pencil, Trash2, Workflow, Play, FileJson, File, Smartphone, CircleCheck, CircleAlert, ArrowRight, Search } from '@lucide/svelte';
    import WorkspacePage from '../../Components/WorkspacePage.svelte';
    import TagEditor from '../../Components/TagEditor.svelte';

    export let auth;
    export let flash = {};
    export let blueprints = [];
    export let selectedBlueprintId = null;
    export let applications = { data: [], links: [], total: 0 };
    export let applicationFilters = {};
    export let indexUrl;
    export let storeUrl;
    export let conditionOptions = [];
    export let actionOptions = [];
    export let platformOptions = [];
    export let navigationTeams = [];
    export let navigationTeam = null;
    export let tagSuggestions = [];

    let selectedId = selectedBlueprintId ?? blueprints[0]?.id ?? null;
    let applicationSearch = applicationFilters.search ?? '';
    let searchTimer;
    let showBlueprintForm = false;
    let editingBlueprint = null;
    let schemaText = '';
    let schemaError = '';
    let filesText = '';
    const blueprintForm = useForm({ name: '', description: '', json_schema: null, required_files: [], platforms: [], requires_expo: null });

    let showHookForm = false;
    let editingHook = null;
    const hookForm = useForm({
        name: '',
        condition: 'blueprint_mismatch',
        condition_config: {},
        actions: [{ type: 'add_tag', tags: [] }],
        enabled: true,
    });

    $: selected = blueprints.find((blueprint) => blueprint.id === selectedId) ?? blueprints[0] ?? null;
    $: applicationItems = applications?.data ?? [];
    $: applicationPageLinks = (applications?.links ?? []).slice(1, -1);
    $: selectedCondition = conditionOptions.find((option) => option.value === hookForm.condition);
    $: canManage = Boolean(auth?.team?.permissions?.manage_applications);

    const formatSchema = (schema) => schema == null ? '' : JSON.stringify(schema, null, 2);
    const openBlueprint = (blueprint = null) => {
        editingBlueprint = blueprint;
        blueprintForm.name = blueprint?.name ?? '';
        blueprintForm.description = blueprint?.description ?? '';
        blueprintForm.json_schema = blueprint?.json_schema ?? null;
        blueprintForm.required_files = [...(blueprint?.required_files ?? [])];
        blueprintForm.platforms = [...(blueprint?.platforms ?? [])];
        blueprintForm.requires_expo = blueprint?.requires_expo ?? null;
        schemaText = formatSchema(blueprint?.json_schema);
        filesText = (blueprint?.required_files ?? []).join('\n');
        schemaError = '';
        blueprintForm.clearErrors();
        showBlueprintForm = true;
    };
    const closeBlueprint = () => {
        showBlueprintForm = false;
        editingBlueprint = null;
        schemaError = '';
    };
    const togglePlatform = (value) => {
        blueprintForm.platforms = blueprintForm.platforms.includes(value)
            ? blueprintForm.platforms.filter((platform) => platform !== value)
            : [...blueprintForm.platforms, value];
    };
    const saveBlueprint = () => {
        if (schemaText.trim()) {
            try {
                const schema = JSON.parse(schemaText);
                if (schema === null || Array.isArray(schema) || typeof schema !== 'object') {
                    schemaError = 'JSON Schema must be an object.';
                    return;
                }
                blueprintForm.json_schema = schema;
                schemaError = '';
            } catch (error) {
                schemaError = `Invalid JSON: ${error.message}`;
                return;
            }
        } else {
            blueprintForm.json_schema = null;
            schemaError = '';
        }

        blueprintForm.required_files = [...new Set(filesText.split('\n').map((name) => name.trim()).filter(Boolean))];
        const options = { preserveScroll: true, onSuccess: closeBlueprint };
        editingBlueprint ? blueprintForm.patch(editingBlueprint.update_url, options) : blueprintForm.post(storeUrl, options);
    };
    const deleteBlueprint = (blueprint) => {
        if (confirm(`Delete blueprint “${blueprint.name}”? Attached applications will remain available without a blueprint.`)) {
            router.delete(blueprint.destroy_url, { preserveScroll: true });
        }
    };
    const selectBlueprint = (blueprint) => {
        selectedId = blueprint.id;
        applicationSearch = '';
        router.get(indexUrl, { blueprint: blueprint.id }, { preserveState: true, preserveScroll: true, replace: true });
    };
    const scheduleApplicationSearch = () => {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(() => {
            router.get(indexUrl, {
                blueprint: selected?.id,
                apps_search: applicationSearch.trim() || undefined,
            }, {
                only: ['applications', 'applicationFilters', 'selectedBlueprintId'],
                preserveState: true,
                preserveScroll: true,
                replace: true,
            });
        }, 350);
    };

    const openHook = (hook = null) => {
        editingHook = hook;
        hookForm.name = hook?.name ?? '';
        hookForm.condition = hook?.condition ?? 'blueprint_mismatch';
        hookForm.condition_config = { ...(hook?.condition_config ?? {}) };
        hookForm.actions = (hook?.actions?.length ? hook.actions : [{ type: 'add_tag', tags: [] }])
            .map((action) => ({
                type: action.type,
                tags: (action.tags ?? []).map((tag) => [...tag]),
            }));
        hookForm.enabled = hook?.enabled ?? true;
        hookForm.clearErrors();
        showHookForm = true;
    };
    const closeHook = () => {
        showHookForm = false;
        editingHook = null;
    };
    const saveHook = () => {
        const key = selectedCondition?.config_key;
        hookForm.condition_config = key ? { [key]: hookForm.condition_config?.[key] ?? '' } : {};
        const options = { preserveScroll: true, onSuccess: closeHook };
        editingHook ? hookForm.patch(editingHook.update_url, options) : hookForm.post(selected.hooks_store_url, options);
    };
    const addAction = () => {
        hookForm.actions = [...hookForm.actions, { type: 'add_tag', tags: [] }];
    };
    const removeAction = (index) => {
        if (hookForm.actions.length === 1) return;
        hookForm.actions = hookForm.actions.filter((_, actionIndex) => actionIndex !== index);
    };
    const updateActionTags = (index, tags) => {
        hookForm.actions = hookForm.actions.map((action, actionIndex) => actionIndex === index
            ? { ...action, tags }
            : action);
    };
    const deleteHook = (hook) => {
        if (confirm(`Delete hook “${hook.name}”? Existing tags will not be changed.`)) {
            router.delete(hook.destroy_url, { preserveScroll: true });
        }
    };
    const conditionLabel = (hook) => conditionOptions.find((option) => option.value === hook.condition)?.label ?? hook.condition;
    const actionLabel = (type) => actionOptions.find((option) => option.value === type)?.label ?? type;
    const actionError = (index) => hookForm.errors[`actions.${index}.tags`]
        || hookForm.errors[`actions.${index}.tags.0.0`]
        || hookForm.errors[`actions.${index}.tags.0.1`]
        || hookForm.errors[`actions.${index}.tags.0.2`];
    const conditionTarget = (hook) => hook.condition_config?.file_name ?? hook.condition_config?.platform ?? null;
    onDestroy(() => clearTimeout(searchTimer));
</script>

<svelte:head><title>Blueprints · App Manager</title></svelte:head>

<WorkspacePage {auth} {navigationTeams} {navigationTeam} active="blueprints" eyebrow="Application standards" title="Blueprints" description="Describe the expected JSON, exact files, and target platforms without blocking incomplete applications." {flash}>
    {#snippet actions()}
        {#if canManage}<button class="flex items-center gap-2" on:click={() => openBlueprint()}><Plus class="h-4 w-4" />New blueprint</button>{/if}
    {/snippet}

    {#if blueprints.length}
        <div class="grid gap-5 lg:grid-cols-[280px_minmax(0,1fr)]">
            <aside class="card h-fit overflow-clip p-0">
                <div class="border-b border-border-subtle bg-surface-sunken px-4 py-2.5 font-condensed text-2xs font-semibold tracking-widest text-faint uppercase">Drawing register</div>
                <div class="divide-y divide-border-subtle">
                    {#each blueprints as blueprint (blueprint.id)}
                        <button type="button" class="flex w-full items-start gap-3 rounded-none bg-transparent px-4 py-3 text-left shadow-none hover:bg-surface-sunken" class:bg-brand-soft={selected?.id === blueprint.id} on:click={() => selectBlueprint(blueprint)}>
                            <DraftingCompass class="mt-0.5 h-4 w-4 shrink-0 {selected?.id === blueprint.id ? 'text-brand' : 'text-faint'}" strokeWidth={1.8} />
                            <span class="min-w-0 flex-1"><strong class="block truncate text-xs text-text">{blueprint.name}</strong><small class="mt-0.5 block font-mono text-2xs text-faint">{blueprint.apps_count} apps · {blueprint.hooks.length} hooks</small></span>
                        </button>
                    {/each}
                </div>
            </aside>

            {#if selected}
                <div class="min-w-0 space-y-5">
                    <section class="card overflow-clip p-0">
                        <div class="flex flex-wrap items-start justify-between gap-3 border-b border-border-subtle px-5 py-4">
                            <div><h2 class="text-lg font-bold text-text">{selected.name}</h2><p class="mt-1 max-w-2xl text-sm text-muted">{selected.description || 'No description yet. The blueprint can stay partial while the standard is being defined.'}</p></div>
                            {#if canManage}<div class="flex gap-2"><button type="button" class="secondary flex items-center gap-1.5 px-3 py-2 text-xs" on:click={() => openBlueprint(selected)}><Pencil class="h-3.5 w-3.5" />Edit</button><button type="button" class="secondary grid h-8 w-8 place-items-center p-0 text-danger" aria-label="Delete blueprint" on:click={() => deleteBlueprint(selected)}><Trash2 class="h-3.5 w-3.5" /></button></div>{/if}
                        </div>
                        <div class="grid divide-y divide-border-subtle sm:grid-cols-2 lg:grid-cols-4 lg:divide-x lg:divide-y-0">
                            <div class="px-5 py-4"><div class="flex items-center gap-2"><FileJson class="h-4 w-4 text-brand" /><span class="font-condensed text-2xs font-semibold tracking-widest text-faint uppercase">JSON schema</span></div><p class="mt-3 text-sm font-semibold">{selected.json_schema ? 'Defined' : 'Not defined'}</p><p class="mt-1 text-xs text-muted">{selected.json_schema?.required?.length ?? 0} required root keys</p></div>
                            <div class="px-5 py-4"><div class="flex items-center gap-2"><File class="h-4 w-4 text-brand" /><span class="font-condensed text-2xs font-semibold tracking-widest text-faint uppercase">Exact files</span></div><p class="mt-3 font-mono text-sm font-semibold">{selected.required_files.length}</p><p class="mt-1 truncate text-xs text-muted">{selected.required_files.join(', ') || 'None specified'}</p></div>
                            <div class="px-5 py-4"><div class="flex items-center gap-2"><Smartphone class="h-4 w-4 text-brand" /><span class="font-condensed text-2xs font-semibold tracking-widest text-faint uppercase">Platforms</span></div><p class="mt-3 font-mono text-sm font-semibold">{selected.platforms.length}</p><p class="mt-1 text-xs text-muted">{selected.platforms.map((value) => value.toUpperCase()).join(', ') || 'None specified'}</p></div>
                            <div class="px-5 py-4"><div class="flex items-center gap-2"><span class="grid h-4 w-4 place-items-center rounded-sm bg-brand-soft-line font-mono text-[8px] font-black text-brand">E</span><span class="font-condensed text-2xs font-semibold tracking-widest text-faint uppercase">Expo</span></div><p class="mt-3 text-sm font-semibold">{selected.requires_expo === null ? 'Optional' : selected.requires_expo ? 'Required' : 'Not allowed'}</p><p class="mt-1 text-xs text-muted">{selected.requires_expo === null ? 'No Expo link rule' : selected.requires_expo ? 'Must be linked to Expo' : 'Must not be linked to Expo'}</p></div>
                        </div>
                        {#if selected.json_schema}<details class="group border-t border-border-subtle"><summary class="cursor-pointer px-5 py-3 text-xs font-semibold text-brand">View JSON Schema</summary><pre class="max-h-80 overflow-auto border-t border-border-subtle bg-surface-sunken p-5 font-mono text-xs leading-5 text-text">{JSON.stringify(selected.json_schema, null, 2)}</pre></details>{/if}
                    </section>

                    <section class="card overflow-clip p-0">
                        <div class="flex items-center justify-between gap-3 border-b border-border-subtle px-5 py-3.5"><div><h2 class="text-sm font-bold">Conditional hooks</h2><p class="mt-0.5 text-xs text-muted">Each hook reads like a small IF → THEN rule and runs when relevant application data changes.</p></div>{#if canManage}<button type="button" class="secondary flex items-center gap-1.5 px-3 py-2 text-xs" on:click={() => openHook()}><Plus class="h-3.5 w-3.5" />Add hook</button>{/if}</div>
                        {#if selected.hooks.length}
                            <div class="divide-y divide-border-subtle">
                                {#each selected.hooks as hook (hook.id)}
                                    <div class="flex flex-wrap items-center gap-3 px-5 py-3.5 {hook.enabled ? '' : 'opacity-55'}">
                                        <span class="grid h-8 w-8 shrink-0 place-items-center rounded-md bg-brand-soft text-brand"><Workflow class="h-4 w-4" /></span>
                                        <div class="min-w-0 flex-1">
                                            <div class="flex items-center gap-2"><strong class="truncate text-xs">{hook.name}</strong>{#if !hook.enabled}<span class="rounded-sm bg-surface-sunken px-1.5 py-0.5 text-2xs text-faint">Disabled</span>{/if}</div>
                                            <div class="mt-1 flex flex-wrap items-center gap-1.5 text-xs text-muted"><span class="font-mono text-2xs font-semibold text-brand">IF</span><span>{conditionLabel(hook)}</span>{#if conditionTarget(hook)}<code class="rounded-sm bg-surface-sunken px-1.5 py-0.5 text-2xs text-text">{conditionTarget(hook)}</code>{/if}</div>
                                            <div class="mt-1.5 space-y-1.5 border-l border-brand-soft-line pl-3">
                                                {#each hook.actions as action, actionIndex}
                                                    <div class="flex flex-wrap items-center gap-1.5 text-xs text-muted"><span class="font-mono text-2xs font-semibold text-brand">THEN {actionIndex + 1}</span><span>{actionLabel(action.type).toLowerCase()}</span><TagEditor tags={action.tags} disabled quiet /></div>
                                                {/each}
                                            </div>
                                        </div>
                                        {#if canManage}<div class="flex gap-1"><button type="button" class="ghost p-2" aria-label="Edit hook" on:click={() => openHook(hook)}><Pencil class="h-3.5 w-3.5" /></button><button type="button" class="ghost p-2 text-danger" aria-label="Delete hook" on:click={() => deleteHook(hook)}><Trash2 class="h-3.5 w-3.5" /></button></div>{/if}
                                    </div>
                                {/each}
                            </div>
                        {:else}<div class="px-6 py-10 text-center"><Workflow class="mx-auto h-6 w-6 text-faint" /><p class="mt-3 text-sm font-semibold">No conditional hooks</p><p class="mt-1 text-xs text-muted">Add a rule to keep tags aligned with blueprint compliance.</p></div>{/if}
                        {#if canManage && selected.apps_count}<div class="flex items-center justify-between gap-3 border-t border-border-subtle bg-surface-sunken px-5 py-3"><p class="text-xs text-muted">Run all enabled hooks against {selected.apps_count} attached application{selected.apps_count === 1 ? '' : 's'}.</p><button type="button" class="secondary flex items-center gap-1.5 px-3 py-2 text-xs" on:click={() => router.post(selected.evaluate_url, {}, { preserveScroll: true })}><Play class="h-3.5 w-3.5" />Evaluate now</button></div>{/if}
                    </section>

                    <section class="card overflow-clip p-0">
                        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-border-subtle px-5 py-3.5"><div><h2 class="text-sm font-bold">Attached applications <span class="ml-1 font-mono text-2xs font-medium text-faint tabular-nums">{applications.total ?? 0}{applicationFilters.search ? ' matching' : ''}</span></h2><p class="mt-0.5 text-xs text-muted">Compliance is informative. Incomplete applications stay editable and buildable.</p></div><label class="relative w-full sm:w-64"><span class="sr-only">Search attached applications</span><Search class="pointer-events-none absolute top-1/2 left-3 h-3.5 w-3.5 -translate-y-1/2 text-faint" /><input class="py-2 pl-9 text-xs" bind:value={applicationSearch} on:input={scheduleApplicationSearch} type="search" placeholder="Search name or slug…" /></label></div>
                        {#if applicationItems.length}<div class="divide-y divide-border-subtle">{#each applicationItems as app (app.id)}<Link href={app.url} class="flex items-center gap-3 px-5 py-3 hover:bg-surface-sunken"><svelte:component this={app.status.matches ? CircleCheck : CircleAlert} class="h-4 w-4 shrink-0 {app.status.matches ? 'text-ok' : 'text-warn-text'}" /><span class="min-w-0 flex-1"><strong class="block truncate text-xs">{app.name}</strong><small class="font-mono text-2xs text-faint">{app.slug}</small></span><span class="text-right text-2xs text-muted">{app.status.matches ? 'Matches' : `${app.status.missing_files.length} files · ${app.status.missing_platforms.length} platforms${app.status.metadata_matches ? '' : ' · JSON'}${app.status.expo_matches ? '' : ' · Expo'}`}</span></Link>{/each}</div>{:else}<div class="px-6 py-10 text-center"><p class="text-sm font-semibold">{applicationFilters.search ? 'No matching applications' : 'No applications attached'}</p><p class="mt-1 text-xs text-muted">{applicationFilters.search ? 'Try a different name or slug.' : "Attach this blueprint from an application's Edit dialog whenever it is ready."}</p></div>{/if}
                        {#if applications.last_page > 1}<nav class="flex flex-wrap items-center justify-between gap-3 border-t border-border-subtle px-5 py-4" aria-label="Attached application pagination"><p class="font-mono text-2xs text-faint tabular-nums">{applications.from}–{applications.to} of {applications.total}</p><div class="flex items-center gap-1.5">{#if applications.prev_page_url}<Link href={applications.prev_page_url} class="secondary px-3 py-2 text-xs" preserveScroll>Previous</Link>{:else}<span class="secondary pointer-events-none px-3 py-2 text-xs opacity-40">Previous</span>{/if}{#each applicationPageLinks as link}{#if link.url}<Link href={link.url} class="secondary min-w-9 px-3 py-2 text-center text-xs {link.active ? 'border-brand-soft-line bg-brand-soft text-brand' : ''}" preserveScroll aria-current={link.active ? 'page' : undefined}>{link.label}</Link>{:else}<span class="px-1 text-xs text-faint">{link.label}</span>{/if}{/each}{#if applications.next_page_url}<Link href={applications.next_page_url} class="secondary px-3 py-2 text-xs" preserveScroll>Next</Link>{:else}<span class="secondary pointer-events-none px-3 py-2 text-xs opacity-40">Next</span>{/if}</div></nav>{/if}
                    </section>
                </div>
            {/if}
        </div>
    {:else}
        <section class="card border-dashed px-6 py-16 text-center"><span class="mx-auto grid h-14 w-14 place-items-center rounded-md bg-brand-soft text-brand"><DraftingCompass class="h-6 w-6" /></span><h2 class="mt-4 text-lg font-bold">Create the first blueprint</h2><p class="mx-auto mt-2 max-w-lg text-sm text-muted">Start with any one part—JSON Schema, exact file names, or platforms. Everything can be filled in later.</p>{#if canManage}<button class="mt-5" on:click={() => openBlueprint()}><Plus class="h-4 w-4" />New blueprint</button>{/if}</section>
    {/if}
</WorkspacePage>

{#if showBlueprintForm}
    <div class="modal-backdrop" role="presentation" on:click={(event) => event.target === event.currentTarget && closeBlueprint()}>
        <form class="modal max-w-3xl" on:submit|preventDefault={saveBlueprint}>
            <div class="flex items-start justify-between border-b border-border-subtle px-6 py-5"><div><h2 class="text-lg font-bold">{editingBlueprint ? 'Edit blueprint' : 'New blueprint'}</h2><p class="mt-1 text-sm text-muted">Every section is optional after the name. Save a draft and refine it later.</p></div><button type="button" class="ghost -mr-2 p-2" on:click={closeBlueprint} aria-label="Close">✕</button></div>
            <div class="max-h-[70vh] space-y-5 overflow-y-auto p-6">
                <label><span class="label">Name</span><input bind:value={blueprintForm.name} placeholder="e.g. Production mobile app" required />{#if blueprintForm.errors.name}<span class="error">{blueprintForm.errors.name}</span>{/if}</label>
                <label><span class="label">Description <span class="font-normal text-faint">(optional)</span></span><textarea class="min-h-20" bind:value={blueprintForm.description} placeholder="What kind of application should use this blueprint?"></textarea>{#if blueprintForm.errors.description}<span class="error">{blueprintForm.errors.description}</span>{/if}</label>
                <label><span class="label">JSON Schema <span class="font-normal text-faint">(optional)</span></span><textarea class="min-h-56 font-mono text-xs leading-5" bind:value={schemaText} spellcheck="false" placeholder={'{\n  "type": "object",\n  "required": ["owner"],\n  "properties": {\n    "owner": { "type": "string" }\n  }\n}'}></textarea><span class="mt-1 block text-2xs text-faint">Supports type, required, properties, items, enum, const, allOf/anyOf, and common size limits.</span>{#if schemaError || blueprintForm.errors.json_schema}<span class="error">{schemaError || blueprintForm.errors.json_schema}</span>{/if}</label>
                <label><span class="label">Exact file names <span class="font-normal text-faint">(one per line, optional)</span></span><textarea class="min-h-28 font-mono text-xs leading-5" bind:value={filesText} spellcheck="false" placeholder={'associated-domains.json\nprivacy-manifest.json'}></textarea><span class="mt-1 block text-2xs text-faint">Names are case-sensitive and cannot contain a path.</span>{#if blueprintForm.errors.required_files}<span class="error">{blueprintForm.errors.required_files}</span>{/if}</label>
                <fieldset><legend class="label">Platforms <span class="font-normal text-faint">(optional)</span></legend><div class="flex flex-wrap gap-2">{#each platformOptions as platform}<button type="button" class="secondary px-3 py-2 font-mono text-xs" class:border-brand={blueprintForm.platforms.includes(platform.value)} class:bg-brand-soft={blueprintForm.platforms.includes(platform.value)} class:text-brand={blueprintForm.platforms.includes(platform.value)} on:click={() => togglePlatform(platform.value)}>{platform.label}</button>{/each}</div>{#if blueprintForm.errors.platforms}<span class="error">{blueprintForm.errors.platforms}</span>{/if}</fieldset>
                <fieldset><legend class="label">Expo <span class="font-normal text-faint">(optional)</span></legend><p class="mb-2 text-2xs text-faint">Choose whether applications using this blueprint must be linked to a project that exists in Expo.</p><div class="flex flex-wrap gap-2"><button type="button" class="secondary px-3 py-2 text-xs" class:border-brand={blueprintForm.requires_expo === null} class:bg-brand-soft={blueprintForm.requires_expo === null} class:text-brand={blueprintForm.requires_expo === null} on:click={() => blueprintForm.requires_expo = null}>Doesn’t matter</button><button type="button" class="secondary px-3 py-2 text-xs" class:border-brand={blueprintForm.requires_expo === true} class:bg-brand-soft={blueprintForm.requires_expo === true} class:text-brand={blueprintForm.requires_expo === true} on:click={() => blueprintForm.requires_expo = true}>Must be in Expo</button><button type="button" class="secondary px-3 py-2 text-xs" class:border-brand={blueprintForm.requires_expo === false} class:bg-brand-soft={blueprintForm.requires_expo === false} class:text-brand={blueprintForm.requires_expo === false} on:click={() => blueprintForm.requires_expo = false}>Must not be in Expo</button></div>{#if blueprintForm.errors.requires_expo}<span class="error">{blueprintForm.errors.requires_expo}</span>{/if}</fieldset>
            </div>
            <div class="flex justify-end gap-3 border-t border-border bg-surface-sunken px-6 py-4"><button type="button" class="secondary" on:click={closeBlueprint}>Cancel</button><button disabled={blueprintForm.processing}>{blueprintForm.processing ? 'Saving…' : 'Save blueprint'}</button></div>
        </form>
    </div>
{/if}

{#if showHookForm && selected}
    <div class="modal-backdrop" role="presentation" on:click={(event) => event.target === event.currentTarget && closeHook()}>
        <form class="modal max-w-xl" on:submit|preventDefault={saveHook}>
            <div class="flex items-start justify-between border-b border-border-subtle px-6 py-5"><div><h2 class="text-lg font-bold">{editingHook ? 'Edit conditional hook' : 'New conditional hook'}</h2><p class="mt-1 text-sm text-muted">Build one IF condition with ordered actions for {selected.name}.</p></div><button type="button" class="ghost -mr-2 p-2" on:click={closeHook} aria-label="Close">✕</button></div>
            <div class="space-y-5 p-6">
                <label><span class="label">Hook name</span><input bind:value={hookForm.name} placeholder="e.g. Flag missing configuration" required />{#if hookForm.errors.name}<span class="error">{hookForm.errors.name}</span>{/if}</label>
                <div class="rounded-md border border-brand-soft-line bg-brand-soft/45 p-4">
                    <span class="font-mono text-2xs font-semibold text-brand">IF</span>
                    <select class="mt-2" bind:value={hookForm.condition} on:change={() => hookForm.condition_config = {}}>{#each conditionOptions as option}<option value={option.value}>{option.label}</option>{/each}</select>
                    {#if selectedCondition?.config_key === 'file_name'}<select class="mt-2 font-mono" bind:value={hookForm.condition_config.file_name} required><option value="" disabled>Select exact file</option>{#each selected.required_files as file}<option value={file}>{file}</option>{/each}</select>{/if}
                    {#if selectedCondition?.config_key === 'platform'}<select class="mt-2 font-mono" bind:value={hookForm.condition_config.platform} required><option value="" disabled>Select platform</option>{#each selected.platforms as platform}<option value={platform}>{platform.toUpperCase()}</option>{/each}</select>{/if}
                    {#if hookForm.errors['condition_config.file_name'] || hookForm.errors['condition_config.platform']}<span class="error">{hookForm.errors['condition_config.file_name'] || hookForm.errors['condition_config.platform']}</span>{/if}
                    <div class="my-3 flex items-center gap-2"><span class="h-px flex-1 bg-brand-soft-line"></span><ArrowRight class="h-4 w-4 text-brand" /><span class="h-px flex-1 bg-brand-soft-line"></span></div>
                    <div class="flex items-center justify-between gap-3"><span class="font-mono text-2xs font-semibold text-brand">THEN</span><button type="button" class="ghost flex items-center gap-1 px-2 py-1 text-2xs" on:click={addAction}><Plus class="h-3 w-3" />Add action</button></div>
                    <div class="mt-2 space-y-2">
                        {#each hookForm.actions as action, actionIndex}
                            <div class="rounded-md border border-border bg-surface-raised p-3">
                                <div class="mb-2 flex items-center justify-between gap-2"><span class="font-mono text-2xs font-semibold text-faint">ACTION {actionIndex + 1}</span>{#if hookForm.actions.length > 1}<button type="button" class="ghost p-1.5 text-danger" aria-label={`Remove action ${actionIndex + 1}`} on:click={() => removeAction(actionIndex)}><Trash2 class="h-3.5 w-3.5" /></button>{/if}</div>
                                <div class="grid gap-3 sm:grid-cols-[160px_minmax(0,1fr)] sm:items-start"><select bind:value={action.type}>{#each actionOptions as option}<option value={option.value}>{option.label}</option>{/each}</select><div class="rounded-md border border-border-subtle bg-surface-sunken/45 px-3 py-2"><TagEditor tags={action.tags} suggestions={tagSuggestions} onChange={(tags) => updateActionTags(actionIndex, tags)} emptyLabel="Choose tags" /></div></div>
                                {#if actionError(actionIndex)}<span class="error">{actionError(actionIndex)}</span>{/if}
                            </div>
                        {/each}
                    </div>
                    {#if hookForm.errors.actions}<span class="error">{hookForm.errors.actions}</span>{/if}
                </div>
                <label class="flex items-center gap-2"><input type="checkbox" class="h-4 w-4 accent-brand" bind:checked={hookForm.enabled} /><span class="text-xs font-semibold">Enabled</span></label>
            </div>
            <div class="flex justify-end gap-3 border-t border-border bg-surface-sunken px-6 py-4"><button type="button" class="secondary" on:click={closeHook}>Cancel</button><button disabled={hookForm.processing}>{hookForm.processing ? 'Saving…' : 'Save hook'}</button></div>
        </form>
    </div>
{/if}

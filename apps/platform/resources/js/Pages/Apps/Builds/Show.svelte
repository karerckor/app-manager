<script>
    // Placeholder page — the full build detail UI is implemented in a later task.
    export let auth;
    export let app;
    export let build = null;
    export let pipelines = [];
    export let buildProfiles = [];
    export let active = 'builds';
    export let flash = {};
    export let navigationTeams = [];
    export let navigationTeam = null;
</script>

<div>
    <h1>{app?.name} — Build {build?.id}</h1>
</div>

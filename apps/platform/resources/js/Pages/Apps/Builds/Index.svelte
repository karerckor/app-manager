<script>
    // Placeholder page — the full builds list UI is implemented in a later task.
    export let auth;
    export let app;
    export let builds = [];
    export let pipelines = [];
    export let buildProfiles = [];
    export let active = 'builds';
    export let flash = {};
    export let navigationTeams = [];
    export let navigationTeam = null;
</script>

<div>
    <h1>{app?.name} — Builds</h1>
</div>

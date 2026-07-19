<script>
    // Placeholder page — the full overview UI is implemented in a later task.
    export let auth;
    export let app;
    export let pipelines = [];
    export let identifiers = [];
    export let buildProfiles = [];
    export let stats = {};
    export let activity = [];
    export let active = 'overview';
    export let flash = {};
    export let navigationTeams = [];
    export let navigationTeam = null;
</script>

<div>
    <h1>{app?.name} — Overview</h1>
</div>

import assert from 'node:assert/strict';
import test from 'node:test';
import { updateSentryTargetRoutes } from '../../resources/js/Pages/Integrations/sentry-targets.js';

const route = (app, identifier = null) => ({
    mobile_app_id: app,
    app_identifier_id: identifier,
});

test('selecting all identifiers atomically replaces identifier-specific routes', () => {
    assert.deepEqual(
        updateSentryTargetRoutes([route('app-1', 'web'), route('app-2', 'ios')], 'app-1', null, true),
        [route('app-2', 'ios'), route('app-1')],
    );
});

test('selecting identifiers keeps sibling identifiers and removes the all-identifiers route', () => {
    assert.deepEqual(
        updateSentryTargetRoutes([route('app-1')], 'app-1', 'android', true),
        [route('app-1', 'android')],
    );
    assert.deepEqual(
        updateSentryTargetRoutes([route('app-1', 'android')], 'app-1', 'ios', true),
        [route('app-1', 'android'), route('app-1', 'ios')],
    );
});

test('unselecting a target removes only that exact target', () => {
    assert.deepEqual(
        updateSentryTargetRoutes([route('app-1', 'android'), route('app-1', 'ios')], 'app-1', 'android', false),
        [route('app-1', 'ios')],
    );
});

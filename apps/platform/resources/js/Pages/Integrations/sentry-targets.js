const normalizedIdentifierId = (identifierId) => identifierId ?? null;

const isTarget = (route, mobileAppId, identifierId) => route.mobile_app_id === mobileAppId
    && normalizedIdentifierId(route.app_identifier_id) === normalizedIdentifierId(identifierId);

export const updateSentryTargetRoutes = (
    routes,
    mobileAppId,
    identifierId,
    selected,
) => {
    const normalizedIdentifier = normalizedIdentifierId(identifierId);

    if (!selected) {
        return routes.filter((route) => !isTarget(route, mobileAppId, normalizedIdentifier));
    }

    // Selecting the whole application replaces its identifier routes. Selecting
    // an identifier only removes the application-wide route and keeps siblings.
    const compatibleRoutes = routes.filter((route) => route.mobile_app_id !== mobileAppId
        || (normalizedIdentifier !== null
            && normalizedIdentifierId(route.app_identifier_id) !== null));

    if (compatibleRoutes.some((route) => isTarget(route, mobileAppId, normalizedIdentifier))) {
        return compatibleRoutes;
    }

    return [...compatibleRoutes, {
        mobile_app_id: mobileAppId,
        app_identifier_id: normalizedIdentifier,
    }];
};

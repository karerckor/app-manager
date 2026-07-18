<?php

namespace App\Enums;

enum TeamPermission: string
{
    case View = 'view';
    case ManageCredentials = 'manage_credentials';
    case ManageApplications = 'manage_applications';
    case ManageTeam = 'manage_team';
    case CloseTeam = 'close_team';
    case ManageIntegrations = 'manage_integrations';
    case RunPipelines = 'run_pipelines';
}

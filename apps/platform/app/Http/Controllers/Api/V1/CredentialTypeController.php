<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Support\Credentials\CredentialSchema;
use Illuminate\Http\JsonResponse;

class CredentialTypeController extends Controller
{
    public function __invoke(Team $team): JsonResponse
    {
        return response()->json([
            'data' => CredentialSchema::definitions(),
        ]);
    }
}

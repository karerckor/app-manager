<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Integrations\CiCd\GitHub\GitHubWebhookSignature;
use App\Jobs\Integrations\ProcessGitHubWebhook;
use App\Models\GitHubWebhookDelivery;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use JsonException;

class GitHubWebhookController extends Controller
{
    public function __invoke(Request $request, GitHubWebhookSignature $signature): Response
    {
        $raw = $request->getContent();
        abort_if(strlen($raw) > 2 * 1024 * 1024, 413);
        abort_unless($signature->valid($raw, $request->header('X-Hub-Signature-256')), 401);

        $deliveryId = (string) $request->header('X-GitHub-Delivery');
        $event = (string) $request->header('X-GitHub-Event');
        abort_if($deliveryId === '' || strlen($deliveryId) > 100 || $event === '' || strlen($event) > 100, 400);

        try {
            $payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            abort(400, 'Invalid JSON payload.');
        }

        abort_unless(is_array($payload), 400);
        $hash = hash('sha256', $raw);
        $delivery = GitHubWebhookDelivery::query()->firstOrCreate(
            ['delivery_id' => $deliveryId],
            [
                'event' => $event,
                'installation_id' => data_get($payload, 'installation.id') !== null
                    ? (string) data_get($payload, 'installation.id')
                    : null,
                'payload_hash' => $hash,
                'payload' => $payload,
            ],
        );

        abort_if(! hash_equals($delivery->payload_hash, $hash), 409, 'Delivery ID was reused with a different payload.');

        if ($delivery->wasRecentlyCreated) {
            ProcessGitHubWebhook::dispatch($deliveryId);
        }

        return response('', 202);
    }
}

<?php

use App\Models\AppIdentifier;
use App\Models\Profile;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $attachments = DB::table('credentialables')
                ->join('credentials', 'credentials.id', '=', 'credentialables.credential_id')
                ->join('app_identifiers', 'app_identifiers.id', '=', 'credentialables.credentialable_id')
                ->where('credentialables.credentialable_type', AppIdentifier::class)
                ->where(function ($query): void {
                    $query
                        ->where(function ($ios): void {
                            $ios->where('credentials.type', 'ios_certificate')
                                ->where('app_identifiers.platform', 'ios');
                        })
                        ->orWhere(function ($android): void {
                            $android->where('credentials.type', 'android_keystore')
                                ->where('app_identifiers.platform', 'android');
                        });
                })
                ->select([
                    'credentialables.id as attachment_id',
                    'credentialables.credential_id',
                    'app_identifiers.id as app_identifier_id',
                    'credentials.type as credential_type',
                ])
                ->get();

            foreach ($attachments as $attachment) {
                $profileType = $attachment->credential_type === 'ios_certificate'
                    ? 'appstore'
                    : 'production';
                $profileName = $profileType === 'appstore' ? 'App Store' : 'Production';
                $profileId = DB::table('profiles')
                    ->where('app_identifier_id', $attachment->app_identifier_id)
                    ->where('type', $profileType)
                    ->value('id');

                if (! $profileId) {
                    $profileId = (string) Str::uuid7();
                    DB::table('profiles')->insert([
                        'id' => $profileId,
                        'app_identifier_id' => $attachment->app_identifier_id,
                        'type' => $profileType,
                        'name' => $profileName,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                $alreadyAttached = DB::table('credentialables')
                    ->where('credential_id', $attachment->credential_id)
                    ->where('credentialable_id', $profileId)
                    ->where('credentialable_type', Profile::class)
                    ->exists();

                if ($alreadyAttached) {
                    DB::table('credentialables')->where('id', $attachment->attachment_id)->delete();

                    continue;
                }

                DB::table('credentialables')
                    ->where('id', $attachment->attachment_id)
                    ->update([
                        'credentialable_id' => $profileId,
                        'credentialable_type' => Profile::class,
                        'updated_at' => now(),
                    ]);
            }
        });
    }

    public function down(): void
    {
        // Build-target assignment is an intentional data normalization and is
        // not reversed because new profile-scoped credentials may exist.
    }
};

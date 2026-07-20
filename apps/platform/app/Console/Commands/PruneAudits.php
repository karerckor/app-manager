<?php

namespace App\Console\Commands;

use App\Models\Audit;
use Illuminate\Console\Command;

class PruneAudits extends Command
{
    protected $signature = 'audits:prune';

    protected $description = 'Delete audit records older than the configured retention window.';

    public function handle(): int
    {
        $days = (int) config('audit.retention_days', 365);
        $cutoff = now()->subDays($days);

        $deleted = Audit::query()->where('created_at', '<', $cutoff)->delete();

        $this->info("Pruned {$deleted} audit record(s) older than {$days} day(s).");

        return self::SUCCESS;
    }
}

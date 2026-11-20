<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Studio\StudioProduction;
use App\Studio\StudioRun;
use App\Studio\StudioRunJob;
use App\Studio\StudioSetting;
use Illuminate\Console\Command;

class RunStudioSchedule extends Command
{
    protected $signature = 'studio:run-schedule';

    protected $description = 'Queue due workspace Studio productions and recover interrupted runs';

    public function handle(): int
    {
        $now = now()->toImmutable();

        StudioRun::query()->where('status', 'running')->where('updated_at', '<', $now->subMinutes(30))
            ->eachById(fn (StudioRun $run) => StudioRunJob::finish($run, 'interrupted', __('The worker stopped reporting before saving a cut. Resume to check the saved production steps.')));

        StudioRun::query()->where('status', 'queued')->where('updated_at', '<', $now->subMinutes(5))
            ->eachById(function (StudioRun $run) use ($now): void {
                $updated = StudioRun::query()->whereKey($run->id)->where('status', 'queued')
                    ->where('updated_at', '<', $now->subMinutes(5))->update(['updated_at' => $now]);
                if ($updated > 0) {
                    StudioRunJob::dispatch($run->id);
                }
            });

        StudioSetting::query()->where('enabled', true)
            ->eachById(fn (StudioSetting $setting) => StudioProduction::schedule($setting, $now));

        return self::SUCCESS;
    }
}

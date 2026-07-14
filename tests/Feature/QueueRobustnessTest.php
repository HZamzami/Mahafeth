<?php

namespace Tests\Feature;

use App\Jobs\AnalyzePortfolioJob;
use App\Jobs\GenerateChatReplyJob;
use App\Jobs\GenerateInsightsJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class QueueRobustnessTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_queued_analysis_for_a_deleted_user_is_discarded_without_failing(): void
    {
        config(['queue.default' => 'database']);

        $user = User::factory()->create();
        AnalyzePortfolioJob::dispatch($user);
        $user->delete();

        Artisan::call('queue:work', ['--once' => true, '--sleep' => 0]);

        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(0, DB::table('failed_jobs')->count());
    }

    public function test_every_user_bound_job_discards_itself_when_the_user_is_gone(): void
    {
        $user = User::factory()->create();

        $this->assertTrue((new AnalyzePortfolioJob($user))->deleteWhenMissingModels);
        $this->assertTrue((new GenerateInsightsJob($user, 'en'))->deleteWhenMissingModels);
        $this->assertTrue((new GenerateChatReplyJob($user, 'en'))->deleteWhenMissingModels);
    }
}

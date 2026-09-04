<?php

namespace VelaBuild\Core\Tests\Unit\Services;

use VelaBuild\Core\Services\DesignBuildStatus;
use VelaBuild\Core\Tests\PackageTestCase;

/**
 * How far through a build is.
 *
 * A build takes minutes with nothing to look at but a log of tool names, so
 * "is this going anywhere" had no answer on the screen. A bar has to be
 * honest or it is worse than none: what is pinned here is that it cannot
 * exceed itself, cannot claim to be finished while work is left, and cannot
 * go backwards as the phases advance.
 */
class DesignBuildProgressTest extends PackageTestCase
{
    private function at(string $phase, int $done = 0, int $total = 1, string $state = 'running'): array
    {
        return ['state' => $state, 'phase' => $phase, 'phase_done' => $done, 'phase_total' => $total];
    }

    public function test_the_phases_are_a_whole_and_nothing_more(): void
    {
        // The weights ARE the bar. If they do not add to a hundred it drifts,
        // and the drift is only visible at the end of a five-minute wait.
        $this->assertSame(100, array_sum(array_column(DesignBuildStatus::PHASES, 'weight')));
    }

    public function test_progress_only_ever_goes_forward(): void
    {
        $seen = [];

        foreach (array_keys(DesignBuildStatus::PHASES) as $phase) {
            foreach ([0, 1, 2, 3] as $done) {
                $seen[] = DesignBuildStatus::percentOf($this->at($phase, $done, 3));
            }
        }

        $sorted = $seen;
        sort($sorted);

        $this->assertSame($sorted, $seen, 'the bar went backwards as the build advanced');
    }

    public function test_it_never_reaches_a_hundred_before_the_build_is_over(): void
    {
        // The last phase, complete on its own count, is still not the end: the
        // run has to say it finished.
        $this->assertLessThan(100, DesignBuildStatus::percentOf($this->at('finishing', 1, 1)));

        $this->assertSame(100, DesignBuildStatus::percentOf($this->at('finishing', 1, 1, 'done')));
    }

    public function test_a_phase_is_never_credited_in_full_while_it_is_being_worked_in(): void
    {
        // 40 of 40 tool calls is not "the build is over" — the answer to the
        // fortieth is still coming. Showing the next phase's number before
        // that phase begins is what makes a bar appear to stall and then jump.
        $atTheCeiling = DesignBuildStatus::percentOf($this->at('building', 40, 40));
        $nextPhase = DesignBuildStatus::percentOf($this->at('qa', 0, 3));

        $this->assertLessThan($nextPhase, $atTheCeiling);
    }

    public function test_a_status_from_before_any_of_this_existed_still_reads(): void
    {
        // A build already running when this was deployed has no phase in its
        // file. It must report something rather than throwing at the poller.
        $this->assertSame(0, DesignBuildStatus::percentOf(['state' => 'running']));
        $this->assertSame(100, DesignBuildStatus::percentOf(['state' => 'done']));
    }

    public function test_the_qa_phase_counts_rounds_in_words_the_person_chose(): void
    {
        $this->assertSame(
            'Comparing it with your design — round 2 of 3',
            DesignBuildStatus::labelOf($this->at('qa', 1, 3))
        );

        // And never a round beyond the number they asked for.
        $this->assertSame(
            'Comparing it with your design — round 3 of 3',
            DesignBuildStatus::labelOf($this->at('qa', 3, 3))
        );
    }

    public function test_reading_the_file_only_costs_the_arithmetic_when_it_is_wanted(): void
    {
        $path = sys_get_temp_dir() . '/vela-progress-' . uniqid();
        mkdir($path);

        $status = new DesignBuildStatus($path);
        $status->start(3);
        $status->stage('qa', 1, 3);

        $plain = $status->read();
        $withProgress = $status->read(true);

        $this->assertArrayNotHasKey('percent', $plain);
        $this->assertSame(15 + 40 + (int) round(43 / 3), $withProgress['percent']);
        $this->assertStringContainsString('round 2 of 3', $withProgress['phase_label']);

        // The status file lives in a subdirectory the service makes itself,
        // so the tidy-up has to go down as far as it went up.
        exec('rm -rf ' . escapeshellarg($path));
    }
}

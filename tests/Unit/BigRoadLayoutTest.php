<?php

namespace Tests\Unit;

use App\Support\BigRoadLayout;
use Tests\TestCase;

class BigRoadLayoutTest extends TestCase
{
    private function fight(string $status, ?string $winner): object
    {
        return (object) ['status' => $status, 'winner' => $winner, 'fight_number' => null];
    }

    public function test_consecutive_same_results_stack_downward_in_one_column(): void
    {
        $fights = [
            $this->fight('declared', 'meron'),
            $this->fight('declared', 'meron'),
            $this->fight('declared', 'meron'),
        ];

        $layout = BigRoadLayout::build($fights, 6);

        $this->assertCount(1, $layout['columns']);
        $this->assertSame($fights[0], $layout['columns'][0][0]);
        $this->assertSame($fights[1], $layout['columns'][0][1]);
        $this->assertSame($fights[2], $layout['columns'][0][2]);
        $this->assertNull($layout['columns'][0][3]);
    }

    public function test_a_different_result_starts_a_new_column(): void
    {
        $fights = [
            $this->fight('declared', 'meron'),
            $this->fight('declared', 'meron'),
            $this->fight('declared', 'wala'),
        ];

        $layout = BigRoadLayout::build($fights, 6);

        $this->assertCount(2, $layout['columns']);
        $this->assertSame($fights[0], $layout['columns'][0][0]);
        $this->assertSame($fights[1], $layout['columns'][0][1]);
        $this->assertSame($fights[2], $layout['columns'][1][0]);
    }

    public function test_a_streak_longer_than_the_row_count_tails_rightward(): void
    {
        $fights = array_fill(0, 8, null);
        foreach ($fights as $i => $_) {
            $fights[$i] = $this->fight('declared', 'meron');
        }

        $layout = BigRoadLayout::build($fights, 6);

        // 6 fill the first column top-to-bottom, then the 7th and 8th tail
        // rightward along row 5 (the bottom row) instead of starting fresh
        // columns.
        $this->assertCount(3, $layout['columns']);
        for ($r = 0; $r < 6; $r++) {
            $this->assertSame($fights[$r], $layout['columns'][0][$r]);
        }
        $this->assertSame($fights[6], $layout['columns'][1][5]);
        $this->assertSame($fights[7], $layout['columns'][2][5]);
        $this->assertNull($layout['columns'][1][0]);
    }

    public function test_cancelled_fights_are_their_own_result_category(): void
    {
        $fights = [
            $this->fight('declared', 'meron'),
            $this->fight('cancelled', null),
            $this->fight('cancelled', null),
            $this->fight('declared', 'wala'),
        ];

        $layout = BigRoadLayout::build($fights, 6);

        $this->assertCount(3, $layout['columns']);
        $this->assertSame($fights[0], $layout['columns'][0][0]);
        $this->assertSame($fights[1], $layout['columns'][1][0]);
        $this->assertSame($fights[2], $layout['columns'][1][1]);
        $this->assertSame($fights[3], $layout['columns'][2][0]);
    }

    public function test_empty_input_produces_no_columns(): void
    {
        $layout = BigRoadLayout::build([], 6);

        $this->assertSame([], $layout['columns']);
    }
}

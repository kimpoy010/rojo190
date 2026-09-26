<?php

namespace App\Support;

/**
 * Lays out a sequence of settled fights into the classic "Big Road" (大路)
 * scoreboard pattern used by baccarat/sabong result boards: consecutive
 * fights with the same result stack downward in one column; a fight whose
 * result differs from the previous one starts a new column. When a streak
 * runs longer than the board's row count, it "tails" rightward along the
 * bottom row instead of starting a fresh column.
 */
class BigRoadLayout
{
    /**
     * @param  iterable  $fights  Ordered (oldest first) fights, each exposing
     *                            ->status and ->winner.
     * @return array{columns: array<int, array<int, mixed>>, maxRows: int}
     *         `columns` is a list of columns; each column is a maxRows-length
     *         array (row 0 first) of either a fight or null.
     */
    public static function build(iterable $fights, int $maxRows = 6): array
    {
        $columns = [];
        $col = -1;
        $row = 0;
        $prevResult = null;

        foreach ($fights as $fight) {
            $result = static::resultKey($fight);

            if ($col === -1) {
                $col = 0;
                $row = 0;
            } elseif ($result === $prevResult) {
                if ($row + 1 < $maxRows && ! isset($columns[$col][$row + 1])) {
                    $row++;
                } elseif (! isset($columns[$col + 1][$row])) {
                    // Streak overflowed the column height — tail rightward
                    // along the same row instead of starting a new column.
                    $col++;
                } else {
                    $col++;
                    $row = 0;
                }
            } else {
                $col++;
                $row = 0;
            }

            $columns[$col][$row] = $fight;
            $prevResult = $result;
        }

        // Normalize every column to a full maxRows-length array so the view
        // can render fixed-size grid cells without bounds-checking.
        $normalized = [];
        foreach ($columns as $columnCells) {
            $padded = [];
            for ($r = 0; $r < $maxRows; $r++) {
                $padded[$r] = $columnCells[$r] ?? null;
            }
            $normalized[] = $padded;
        }

        return [
            'columns' => $normalized,
            'maxRows' => $maxRows,
        ];
    }

    private static function resultKey(mixed $fight): string
    {
        if ($fight->status === 'cancelled') {
            return 'cancelled';
        }

        return $fight->winner ?? 'cancelled';
    }
}

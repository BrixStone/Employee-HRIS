<?php
/**
 * Sorting DSA — HRIS
 *
 * Bubble Sort    — hr/employees.php, hr/reports.php
 * Insertion Sort — small/nearly sorted datasets
 * Merge Sort     — algorithms/merge_sort.php wrapper
 * Quick Sort     — algorithms/quick_sort.php wrapper
 */

class Sorting
{
    /**
     * BUBBLE SORT
     * O(n²) worst, O(n) best (early exit on no swaps)
     */
    public static function bubbleSort(array $data, string $key, string $direction = 'asc'): array
    {
        $n   = count($data);
        $asc = strtolower($direction) !== 'desc';

        for ($i = 0; $i < $n - 1; $i++) {
            $swapped = false;

            for ($j = 0; $j < $n - $i - 1; $j++) {
                $a = strtolower((string)($data[$j][$key]      ?? ''));
                $b = strtolower((string)($data[$j + 1][$key]  ?? ''));

                $shouldSwap = $asc ? ($a > $b) : ($a < $b);

                if ($shouldSwap) {
                    [$data[$j], $data[$j + 1]] = [$data[$j + 1], $data[$j]];
                    $swapped = true;
                }
            }

            if (!$swapped) break;
        }

        return $data;
    }

    /**
     * INSERTION SORT
     * O(n²) worst, O(n) best — good for small/nearly sorted
     */
    public static function insertionSort(array $data, string $key, string $direction = 'asc'): array
    {
        $n   = count($data);
        $asc = strtolower($direction) !== 'desc';

        for ($i = 1; $i < $n; $i++) {
            $current    = $data[$i];
            $j          = $i - 1;
            $currentVal = strtolower((string)($current[$key] ?? ''));

            while ($j >= 0) {
                $compareVal  = strtolower((string)($data[$j][$key] ?? ''));
                $shouldShift = $asc
                    ? ($compareVal > $currentVal)
                    : ($compareVal < $currentVal);

                if (!$shouldShift) break;

                $data[$j + 1] = $data[$j];
                $j--;
            }

            $data[$j + 1] = $current;
        }

        return $data;
    }

    /**
     * BUBBLE SORT NUMERIC
     * Same as bubbleSort but compares as floats.
     * Used for net_pay, gross_pay, headcount etc.
     */
    public static function bubbleSortNumeric(array $data, string $key, string $direction = 'asc'): array
    {
        $n   = count($data);
        $asc = strtolower($direction) !== 'desc';

        for ($i = 0; $i < $n - 1; $i++) {
            $swapped = false;

            for ($j = 0; $j < $n - $i - 1; $j++) {
                $a = (float)($data[$j][$key]      ?? 0);
                $b = (float)($data[$j + 1][$key]  ?? 0);

                $shouldSwap = $asc ? ($a > $b) : ($a < $b);

                if ($shouldSwap) {
                    [$data[$j], $data[$j + 1]] = [$data[$j + 1], $data[$j]];
                    $swapped = true;
                }
            }

            if (!$swapped) break;
        }

        return $data;
    }

    /**
     * MERGE SORT
     * Divide and conquer — always O(n log n)
     * More efficient than bubble sort on large datasets.
     * Stable sort — equal elements keep their original order.
     *
     * @param  array  $data
     * @param  string $key
     * @param  string $direction 'asc' | 'desc'
     * @return array
     */
    public static function mergeSort(array $data, string $key, string $direction = 'asc'): array
    {
        $n = count($data);

        // Base case — already sorted
        if ($n <= 1) {
            return $data;
        }

        $mid   = intdiv($n, 2);
        $left  = self::mergeSort(array_slice($data, 0, $mid),  $key, $direction);
        $right = self::mergeSort(array_slice($data, $mid),     $key, $direction);

        return self::merge($left, $right, $key, $direction);
    }

    private static function merge(array $left, array $right, string $key, string $direction): array
    {
        $result = [];
        $i      = 0;
        $j      = 0;
        $asc    = strtolower($direction) !== 'desc';

        while ($i < count($left) && $j < count($right)) {
            $a = strtolower((string)($left[$i][$key]  ?? ''));
            $b = strtolower((string)($right[$j][$key] ?? ''));

            $takeLeft = $asc ? ($a <= $b) : ($a >= $b);

            if ($takeLeft) {
                $result[] = $left[$i++];
            } else {
                $result[] = $right[$j++];
            }
        }

        // Append any remaining elements
        while ($i < count($left))  $result[] = $left[$i++];
        while ($j < count($right)) $result[] = $right[$j++];

        return $result;
    }

    /**
     * QUICK SORT
     * Divide and conquer — O(n log n) average, O(n²) worst
     * Faster than merge sort in practice due to low memory overhead.
     * Uses median-of-three pivot selection to avoid worst case.
     *
     * @param  array  $data
     * @param  string $key
     * @param  string $direction 'asc' | 'desc'
     * @return array
     */
    public static function quickSort(array $data, string $key, string $direction = 'asc'): array
    {
        $n = count($data);

        // Base case
        if ($n <= 1) {
            return $data;
        }

        // Median-of-three pivot: compare first, mid, last
        $mid      = intdiv($n, 2);
        $asc      = strtolower($direction) !== 'desc';
        $pivotIdx = self::medianOfThree($data, 0, $mid, $n - 1, $key, $asc);
        $pivot    = $data[$pivotIdx];
        $pivotVal = strtolower((string)($pivot[$key] ?? ''));

        $less    = [];
        $equal   = [];
        $greater = [];

        foreach ($data as $item) {
            $val = strtolower((string)($item[$key] ?? ''));
            $cmp = $val <=> $pivotVal;

            if ($cmp === 0) {
                $equal[] = $item;
            } elseif ($asc ? ($cmp < 0) : ($cmp > 0)) {
                $less[] = $item;
            } else {
                $greater[] = $item;
            }
        }

        return array_merge(
            self::quickSort($less,    $key, $direction),
            $equal,
            self::quickSort($greater, $key, $direction)
        );
    }

    private static function medianOfThree(
        array  $data,
        int    $a,
        int    $b,
        int    $c,
        string $key,
        bool   $asc
    ): int {
        $va = strtolower((string)($data[$a][$key] ?? ''));
        $vb = strtolower((string)($data[$b][$key] ?? ''));
        $vc = strtolower((string)($data[$c][$key] ?? ''));

        // Return index of median value among a, b, c
        if ($asc) {
            if (($va <= $vb && $vb <= $vc) || ($vc <= $vb && $vb <= $va)) return $b;
            if (($vb <= $va && $va <= $vc) || ($vc <= $va && $va <= $vb)) return $a;
            return $c;
        } else {
            if (($va >= $vb && $vb >= $vc) || ($vc >= $vb && $vb >= $va)) return $b;
            if (($vb >= $va && $va >= $vc) || ($vc >= $va && $va >= $vb)) return $a;
            return $c;
        }
    }
}
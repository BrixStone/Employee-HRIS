
<?php

class Sorting
{


    public static function bubbleSort(array $data, string $key, string $direction = 'asc'): array
    {
        $n = count($data);
        $asc = strtolower($direction) !== 'desc';

        for ($i = 0; $i < $n - 1; $i++) {

            $swapped = false;

            for ($j = 0; $j < $n - $i - 1; $j++) {

                $a = strtolower((string)($data[$j][$key] ?? ''));
                $b = strtolower((string)($data[$j + 1][$key] ?? ''));

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


    // Sorts records by a specified key using insertion sort.
    // Useful for small or nearly sorted datasets.
    // Ascending (asc) = A to Z; Descending (desc) = Z to A.

    public static function insertionSort(array $data, string $key, string $direction = 'asc'): array
    {
        $n = count($data);
        $asc = strtolower($direction) !== 'desc';

        for ($i = 1; $i < $n; $i++) {

            $current = $data[$i];
            $j = $i - 1;

            $currentVal = strtolower((string)($current[$key] ?? ''));

            while ($j >= 0) {

                $compareVal = strtolower((string)($data[$j][$key] ?? ''));

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


    // Sorts numerical values such as net pay, gross pay, and headcount.
    // Used in hr/reports.php and can also be used in hr/payroll.php.
    // Ascending (asc) = Lowest to highest; Descending (desc) = Highest to lowest.

    public static function bubbleSortNumeric(array $data, string $key, string $direction = 'asc'): array
    {
        $n = count($data);
        $asc = strtolower($direction) !== 'desc';

        for ($i = 0; $i < $n - 1; $i++) {

            $swapped = false;

            for ($j = 0; $j < $n - $i - 1; $j++) {

                $a = (float)($data[$j][$key] ?? 0);
                $b = (float)($data[$j + 1][$key] ?? 0);

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
}
    /*
     * 1. hr/employees.php
     *    - Uses bubbleSort() to arrange employees by last name.
     *
     * 2. hr/reports.php
     *    - Uses bubbleSortNumeric() to arrange reports by headcount.
     *
     * 3. hr/payroll.php
     *    - Uses bubbleSort() to arrange payslips by employee name.
     *
     * TO USE THIS CLASS:
     * - Navigate to the HRIS project folder.
     * - Open the appropriate HR module file.
     * - Include this Sorting class using require_once.
     * - Call the desired sorting method and provide the data,
     *   column key, and sorting direction.
     *
     * Example:
     * require_once '../classes/Sorting.php';
     * $employees = Sorting::bubbleSort($employees, 'last_name', 'asc');
     */


    // Sorts employee or other records alphabetically using a specified key.
    // Used in hr/employees.php and hr/payroll.php.
    // Ascending (asc) = A to Z; Descending (desc) = Z to A.
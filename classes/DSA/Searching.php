
<?php

class Searching
{


    public static function linearSearch(array $data, string $query, array $keys): array
    {
        if ($query === '') return $data;

        $query = strtolower(trim($query));
        $matches = [];

        foreach ($data as $row) {
            foreach ($keys as $key) {
                $value = strtolower((string)($row[$key] ?? ''));

                if (str_contains($value, $query)) {
                    $matches[] = $row;
                    break;
                }
            }
        }

        return $matches;
    }


    // BINARY SEARCH (NUMERIC)
    // Used in classes/Payroll.php.
    // Locates the appropriate late deduction bracket using numbers.
    // The array must be arranged in ascending order before searching.
    // Returns the index of the matching bracket.

    public static function binarySearchNumeric(array $sortedArr, int $target): int
    {
        $low = 0;
        $high = count($sortedArr) - 1;
        $result = 0;

        while ($low <= $high) {

            $mid = intdiv($low + $high, 2);

            if ($sortedArr[$mid] === $target) {
                return $mid;
            }

            if ($sortedArr[$mid] < $target) {
                $result = $mid;
                $low = $mid + 1;
            } else {
                $high = $mid - 1;
            }
        }

        return $result;
    }


    // BINARY SEARCH (STRING KEY)
    // Used in hr/payroll.php.
    // Finds an employee using an exact matching value such as employee ID.
    // The records must be sorted in ascending order using the search key.
    // Returns the matching employee record or null if not found.

    public static function binarySearchByKey(array $data, string $key, string $target): ?array
    {
        $low = 0;
        $high = count($data) - 1;
        $target = strtolower(trim($target));

        while ($low <= $high) {

            $mid = intdiv($low + $high, 2);
            $val = strtolower((string)($data[$mid][$key] ?? ''));

            if ($val === $target) {
                return $data[$mid];
            }

            if ($val < $target) {
                $low = $mid + 1;
            } else {
                $high = $mid - 1;
            }
        }

        return null;
    }
}

    /*
     * 1. hr/employees.php
     *    - Uses linearSearch() to find employees by name, email, or ID.
     *
     * 2. classes/Payroll.php
     *    - Uses binarySearchNumeric() to locate the appropriate
     *      late deduction bracket.
     *
     * 3. employee/payslips.php
     *    - Uses linearSearch() to find an employee's own payslips.
     *
     * 4. hr/payroll.php
     *    - Uses binarySearchByKey() to find an employee by ID
     *      in a list sorted by employee ID.
     *
     * TO USE THIS CLASS:
     * - Navigate to the HRIS project folder.
     * - Open the appropriate HR or employee module.
     * - Include this Searching class using require_once.
     * - Call the required searching method and provide the data
     *   and search values.
     *
     * Example:
     * require_once '../classes/Searching.php';
     * $results = Searching::linearSearch(
     *     $employees,
     *     'John',
     *     ['first_name', 'last_name', 'email']
     * );
     */


    // LINEAR SEARCH
    // Used in hr/employees.php and employee/payslips.php.
    // Checks records one by one to find matching information.
    // Can search multiple fields such as employee name, email, or ID.
    // Returns all matching records.
<?php
    function linear_search_employees(array $rows, string $q): array {
        $q = trim($q);

        if ($q === '') return $rows;

        $out = [];
        $qL = strtolower($q);

        foreach ($rows as $e) {
            $hay = strtolower(
                ($e['employee_id'] ?? ''). ' ' .
                emp_full_name($e) . ' ' .
                ($e['email'] ?? '')
            );

            if (stripos($hay, $qL) !== false) {
                $out[] = $e;
            }
        }
        return $out;
    }

    
?>
<?php
function bubble_sort_employees(array &$arr, string $key, string $dir = 'asc'): void {
    $n = count($arr);

    if ($n <2) return;

    $asc = ($dir === 'asc');

    for ($i=0; $i < $n - 1; $i++) { 
        $swapped = false;

        for ($j = 0; $j < $n - $i - 1; $j++) { 
            $a = sort_key_value($arr[$j], $key);
            $b = sort_key_value($arr[$j + 1], $key);
            $cmp = $a <=> $b;
            $needSwap = $asc ? ($cmp > 0) : ($cmp <0);
            if ($needSwap) {
                $tmp = $arr[$j];
                $arr[$j] = $arr[$j + 1];
                $arr[$j + 1] = $tmp;
                $swapped = true;
            }
        }
        if (!$swapped) break;
    }
}
?>
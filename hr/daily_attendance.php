<?php
// hr/daily_attendance.php
$_GET['view'] = 'daily';
unset($_GET['date'], $_GET['range'], $_GET['tab']);
require __DIR__ . '/attendance.php';
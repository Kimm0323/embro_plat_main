<?php
session_start();

$query = $_SERVER['QUERY_STRING'] ?? '';
$target = 'design_proofing.php';
if($query !== '') {
    $target .= '?' . $query;
}
        

   header('Location: ' . $target);
exit;

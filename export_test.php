<?php
session_start();
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="test_export.csv"');
echo "test,data,here\n1,2,3\n";
exit;




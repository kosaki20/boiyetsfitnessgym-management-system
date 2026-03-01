<?php
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    echo "Composer Dompdf: Available<br>";
} elseif (file_exists(__DIR__ . '/dompdf/autoload.inc.php')) {
    echo "Manual Dompdf: Available<br>";
} else {
    echo "Dompdf: Not Available (will use HTML fallback)<br>";
}
?>



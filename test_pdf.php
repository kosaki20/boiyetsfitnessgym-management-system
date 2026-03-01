<?php
// Test PDF generation
echo "Testing PDF Generation...<br>";

// Check if Dompdf files exist
$dompdf_path = __DIR__ . '/dompdf/autoload.inc.php';
echo "Dompdf path: " . $dompdf_path . "<br>";
echo "File exists: " . (file_exists($dompdf_path) ? 'Yes' : 'No') . "<br>";

if (file_exists($dompdf_path)) {
    require_once $dompdf_path;
    echo "Dompdf loaded successfully<br>";
    
    // Test basic PDF generation
    try {
        $dompdf = new Dompdf\Dompdf();
        $dompdf->loadHtml('<h1>Test PDF</h1><p>If you can see this, PDF generation is working!</p>');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        
        // Output to browser
        $dompdf->stream("test.pdf", array("Attachment" => false));
        echo "PDF generated successfully!";
    } catch (Exception $e) {
        echo "PDF Error: " . $e->getMessage() . "<br>";
    }
} else {
    echo "Dompdf files not found at the expected location.<br>";
    echo "Current directory: " . __DIR__ . "<br>";
    
    // List files in current directory
    echo "<br>Files in current directory:<br>";
    $files = scandir(__DIR__);
    foreach ($files as $file) {
        if (is_dir($file)) {
            echo "[DIR] " . $file . "<br>";
        }
    }
}
?>




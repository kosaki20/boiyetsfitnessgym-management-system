<?php
function refactorPage($filename) {
    if (!file_exists($filename)) {
        echo "File not found: $filename\n";
        return;
    }
    
    $content = file_get_contents($filename);
    
    // Check if already refactored
    if (strpos($content, 'includes/trainer_header.php') !== false) {
        echo "Already refactored: $filename\n";
        return;
    }

    // Find boundaries
    $html_start = -1;
    if (preg_match('/<!DOCTYPE html>/i', $content, $matches, PREG_OFFSET_CAPTURE)) {
        $html_start = $matches[0][1];
    }
    
    $sidebar_end = -1;
    // Look for the end of the sidebar aside tag
    if (preg_match('/<\/aside>/i', $content, $matches, PREG_OFFSET_CAPTURE)) {
        $sidebar_end = $matches[0][1] + strlen($matches[0][0]);
    }
    
    $body_end = -1;
    if (preg_match('/<\/body>/i', $content, $matches, PREG_OFFSET_CAPTURE)) {
        $body_end = $matches[0][1];
    }
    
    if ($html_start !== -1 && $sidebar_end !== -1 && $body_end !== -1) {
        $php_logic = substr($content, 0, $html_start);
        $main_content = substr($content, $sidebar_end, $body_end - $sidebar_end);
        
        $new_content = $php_logic;
        $new_content .= "<?php require_once 'includes/trainer_header.php'; ?>\n";
        $new_content .= "<?php require_once 'includes/trainer_sidebar.php'; ?>\n";
        $new_content .= $main_content;
        $new_content .= "<?php require_once 'includes/trainer_footer.php'; ?>\n";
        
        // Ensure connection closure if mysqli is used
        if (strpos($content, 'new mysqli') !== false || strpos($content, '$conn->close()') !== false) {
             // If it already has $conn->close() at the very end, we might be duplicating or it might be outside body.
             // We'll just add a safe closure.
             $new_content .= "<?php if(isset(\$conn) && \$conn instanceof mysqli) { \$conn->close(); } ?>\n";
        }
        
        file_put_contents($filename, $new_content);
        echo "Successfully refactored: $filename\n";
    } else {
        echo "Failed to find boundaries in $filename (HTML: $html_start, Sidebar: $sidebar_end, Body: $body_end)\n";
        // Let's print a bit of content around where we expect things to debug
        if ($html_start === -1) echo "  - No DOCTYPE found\n";
        if ($sidebar_end === -1) echo "  - No </aside> found\n";
        if ($body_end === -1) echo "  - No </body> found\n";
    }
}

$trainer_files = [
    'trainerworkout.php',
    'trainermealplan.php',
    'trainermanageqrcodes.php',
    'trainer_equipment_monitoring.php',
    'trainer_maintenance_report.php',
    'feedbackstrainer.php',
    'attendance_logs.php',
    'member_registration.php',
    'membership_status.php',
    'all_members.php',
    'clientprogress.php',
    'countersales.php'
];

foreach ($trainer_files as $file) {
    refactorPage($file);
}
?>

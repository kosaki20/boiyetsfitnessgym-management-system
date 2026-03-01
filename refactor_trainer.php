<?php
function refactorPage($filename) {
    if (!file_exists($filename)) {
        echo "$filename not found\n";
        return;
    }
    
    $lines = file($filename);
    $html_start_idx = -1;
    $end_sidebar_idx = -1;
    $body_close_idx = -1;
    
    for ($i = 0; $i < count($lines); $i++) {
        if ($html_start_idx === -1 && stripos(trim($lines[$i]), '<!DOCTYPE html>') !== false) {
            $html_start_idx = $i;
        }
        if ($end_sidebar_idx === -1 && trim($lines[$i]) === '</aside>') {
            $end_sidebar_idx = $i;
        }
    }
    
    for ($i = count($lines) - 1; $i >= 0; $i--) {
        if ($body_close_idx === -1 && trim($lines[$i]) === '</body>') {
            $body_close_idx = $i;
            break;
        }
    }
    
    if ($html_start_idx !== -1 && $end_sidebar_idx !== -1 && $body_close_idx !== -1) {
        $new_lines = array_slice($lines, 0, $html_start_idx);
        $new_lines[] = "<?php require_once 'includes/trainer_header.php'; ?>\n";
        $new_lines[] = "<?php require_once 'includes/trainer_sidebar.php'; ?>\n";
        
        $middle = array_slice($lines, $end_sidebar_idx + 1, $body_close_idx - ($end_sidebar_idx + 1));
        $new_lines = array_merge($new_lines, $middle);
        
        $new_lines[] = "<?php require_once 'includes/trainer_footer.php'; ?>\n";
        
        // Find if conn->close() exists after body
        $has_close = false;
        for ($i = $body_close_idx; $i < count($lines); $i++) {
            if (strpos($lines[$i], '$conn->close()') !== false) {
                $has_close = true;
                break;
            }
        }
        
        if ($has_close || strpos(file_get_contents($filename), 'new mysqli') !== false) {
            $new_lines[] = "<?php if(isset(\$conn)) { \$conn->close(); } ?>\n";
        }
        
        file_put_contents($filename, implode("", $new_lines));
        echo "$filename refactored successfully\n";
    } else {
        echo "Could not find boundaries for $filename: html=$html_start_idx, sidebar=$end_sidebar_idx, body=$body_close_idx\n";
    }
}

$files = [
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

foreach ($files as $file) {
    if (file_exists($file)) {
        refactorPage($file);
    }
}
?>

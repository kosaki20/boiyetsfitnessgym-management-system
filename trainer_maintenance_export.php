<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'trainer') {
    header("Location: index.php");
    exit();
}

require_once 'includes/db_connection.php';

$type = $_GET['type'] ?? 'equipment'; // 'equipment' or 'facilities'

// Set CSV headers
$filename = ($type === 'facilities')
    ? 'maintenance_facilities_' . date('Y-m-d') . '.csv'
    : 'maintenance_equipment_' . date('Y-m-d') . '.csv';

header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$output = fopen('php://output', 'w');

if ($type === 'facilities') {
    // Facilities export
    fputcsv($output, ['Facility Name', 'Type', 'Condition', 'Capacity', 'Notes', 'Updated By', 'Last Updated']);

    $stmt = $conn->prepare(
        "SELECT f.name, f.facility_type, f.facility_condition, f.capacity,
                f.notes, u.username as updated_by_name, f.updated_at
         FROM facilities f
         LEFT JOIN users u ON f.updated_by = u.id
         WHERE f.facility_condition IN ('Needs Maintenance', 'Under Repair', 'Closed')
         ORDER BY f.name ASC"
    );
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        fputcsv($output, [
            $row['name'],
            $row['facility_type'],
            $row['facility_condition'],
            $row['capacity'],
            $row['notes'] ?? '',
            $row['updated_by_name'] ?? 'N/A',
            $row['updated_at']
        ]);
    }
} else {
    // Equipment export (default)
    fputcsv($output, ['Equipment Name', 'Category', 'Status', 'Priority', 'Location', 'Notes', 'Updated By', 'Last Updated']);

    $stmt = $conn->prepare(
        "SELECT e.name, e.category, e.status, e.priority, e.location,
                e.notes, u.username as updated_by_name, e.last_updated
         FROM equipment e
         LEFT JOIN users u ON e.created_by = u.id
         WHERE e.status IN ('Needs Maintenance', 'Under Repair', 'Broken')
         ORDER BY e.last_updated DESC, e.name ASC"
    );
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        fputcsv($output, [
            $row['name'],
            $row['category'],
            $row['status'],
            $row['priority'] ?? 'Normal',
            $row['location'] ?? 'N/A',
            $row['notes'] ?? '',
            $row['updated_by_name'] ?? 'N/A',
            $row['last_updated']
        ]);
    }
}

fclose($output);
exit();
?>

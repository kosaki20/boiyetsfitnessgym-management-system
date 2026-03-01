<?php
// Start output buffering to prevent header issues
ob_start();

// Enable error reporting for debugging
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') {
    header("Location: index.php");
    exit();
}

require_once 'includes/db_connection.php';
// Currency formatting helper function
function formatCurrency($amount) {
    return '₱' . number_format($amount, 2);
}

// Get export parameters with defaults and validation
$export_type = $_GET['export'] ?? 'excel';
$start_date = $_GET['start_date'] ?? date('Y-m-d', strtotime('-1 month'));
$end_date = $_GET['end_date'] ?? date('Y-m-d');
$tab = $_GET['tab'] ?? 'revenue';
$report_type = $_GET['report_type'] ?? 'detailed';
$category_filter = $_GET['category'] ?? '';
$payment_filter = $_GET['payment_method'] ?? '';
$search = $_GET['search'] ?? '';
$time_filter = $_GET['time_filter'] ?? 'month';

// Validate export type
$allowed_exports = ['excel', 'csv', 'pdf'];
if (!in_array($export_type, $allowed_exports)) {
    $export_type = 'excel';
}

// Validate report type
$allowed_reports = ['detailed', 'summary', 'financial_statement'];
if (!in_array($report_type, $allowed_reports)) {
    $report_type = 'detailed';
}

// Validate date range
if (strtotime($start_date) > strtotime($end_date)) {
    $start_date = date('Y-m-d', strtotime('-1 month'));
    $end_date = date('Y-m-d');
}

// Determine which export to run based on tab
try {
    if ($tab == 'revenue') {
        exportRevenueData($conn, $export_type, $start_date, $end_date, $category_filter, $payment_filter, $report_type, $search);
    } elseif ($tab == 'expenses') {
        exportExpenseData($conn, $export_type, $start_date, $end_date, $category_filter, $payment_filter, $report_type, $search);
    } elseif ($tab == 'profit') {
        exportFinancialStatement($conn, $export_type, $start_date, $end_date, $report_type);
    } else {
        // Default to revenue if tab not recognized
        exportRevenueData($conn, $export_type, $start_date, $end_date, $category_filter, $payment_filter, $report_type, $search);
    }
} catch (Exception $e) {
    // Log error and redirect
    error_log("Export error: " . $e->getMessage());
    header("Location: revenue.php?error=export_failed");
    exit();
}

$conn->close();
ob_end_flush();
exit;

// REVENUE EXPORT FUNCTION
function exportRevenueData($conn, $export_type, $start_date, $end_date, $category_filter, $payment_filter, $report_type, $search) {
    // Build query conditions
    $where_conditions = ["re.revenue_date BETWEEN ? AND ?"];
    $params = [$start_date, $end_date];
    $types = "ss";

    if ($category_filter && $category_filter != '') {
        $where_conditions[] = "re.category_id = ?";
        $params[] = $category_filter;
        $types .= "i";
    }

    if ($payment_filter && $payment_filter != '') {
        $where_conditions[] = "re.payment_method = ?";
        $params[] = $payment_filter;
        $types .= "s";
    }

    if ($search && $search != '') {
        $where_conditions[] = "(re.description LIKE ? OR re.reference_name LIKE ? OR rc.name LIKE ?)";
        $search_param = "%$search%";
        $params[] = $search_param;
        $params[] = $search_param;
        $params[] = $search_param;
        $types .= "sss";
    }

    $where_sql = implode(" AND ", $where_conditions);

    // Get revenue data - Only categories 1 and 4
    $sql = "SELECT 
                re.revenue_date as date,
                rc.name as category,
                re.description,
                re.reference_name,
                re.payment_method,
                re.amount,
                u.username as recorded_by
            FROM revenue_entries re
            JOIN revenue_categories rc ON re.category_id = rc.id
            JOIN users u ON re.recorded_by = u.id
            WHERE $where_sql AND rc.id IN (1, 4)
            ORDER BY re.revenue_date DESC";

    $stmt = $conn->prepare($sql);
    if ($stmt && !empty($params)) {
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
    } else {
        $result = false;
    }

    // Get ALL revenue including membership payments within date range - IMPROVED QUERY
    $revenue_stats_sql = "SELECT 
                'revenue_entries' as source,
                COUNT(re.id) as transaction_count,
                COALESCE(SUM(re.amount), 0) as total_amount
              FROM revenue_entries re
              WHERE re.revenue_date BETWEEN ? AND ?
              
              UNION ALL
              
              SELECT 
                'membership_payments' as source,
                COUNT(mp.id) as transaction_count,
                COALESCE(SUM(mp.amount), 0) as total_amount
              FROM membership_payments mp
              WHERE mp.payment_date BETWEEN ? AND ?
              AND mp.status = 'completed'";

    $revenue_stats_stmt = $conn->prepare($revenue_stats_sql);
    $total_revenue = 0;
    $total_transactions = 0;
    
    if ($revenue_stats_stmt) {
        $revenue_stats_stmt->bind_param("ssss", $start_date, $end_date, $start_date, $end_date);
        $revenue_stats_stmt->execute();
        $revenue_stats_result = $revenue_stats_stmt->get_result();
        
        if ($revenue_stats_result) {
            while($row = $revenue_stats_result->fetch_assoc()) {
                $total_revenue += $row['total_amount'] ?? 0;
                $total_transactions += $row['transaction_count'] ?? 0;
            }
        }
        $revenue_stats_stmt->close();
    }

    // Get detailed membership revenue breakdown by plan type
    $membership_breakdown_sql = "SELECT 
                mp.plan_type,
                COUNT(mp.id) as transaction_count,
                COALESCE(SUM(mp.amount), 0) as total_amount,
                COALESCE(AVG(mp.amount), 0) as average_amount
              FROM membership_payments mp
              WHERE mp.payment_date BETWEEN ? AND ?
              AND mp.status = 'completed'
              GROUP BY mp.plan_type
              ORDER BY total_amount DESC";

    $membership_breakdown_stmt = $conn->prepare($membership_breakdown_sql);
    $membership_breakdown = [];
    if ($membership_breakdown_stmt) {
        $membership_breakdown_stmt->bind_param("ss", $start_date, $end_date);
        $membership_breakdown_stmt->execute();
        $membership_breakdown_result = $membership_breakdown_stmt->get_result();
        while($row = $membership_breakdown_result->fetch_assoc()) {
            $membership_breakdown[] = $row;
        }
        $membership_breakdown_stmt->close();
    }

    // Get revenue statistics by category - IMPROVED VERSION
    $category_sql = "SELECT 
                    'Membership Fees' as category_name,
                    '#3b82f6' as category_color,
                    COUNT(mp.id) as transaction_count,
                    COALESCE(SUM(mp.amount), 0) as total_amount,
                    COALESCE(AVG(mp.amount), 0) as average_amount
                  FROM membership_payments mp
                  WHERE mp.payment_date BETWEEN ? AND ?
                  AND mp.status = 'completed'
                  
                  UNION ALL
                  
                  SELECT 
                    rc.name as category_name,
                    rc.color as category_color,
                    COUNT(re.id) as transaction_count,
                    COALESCE(SUM(re.amount), 0) as total_amount,
                    COALESCE(AVG(re.amount), 0) as average_amount
                  FROM revenue_categories rc
                  LEFT JOIN revenue_entries re ON rc.id = re.category_id AND re.revenue_date BETWEEN ? AND ?
                  WHERE rc.id IN (1, 4)
                  GROUP BY rc.id, rc.name, rc.color
                  ORDER BY total_amount DESC";

    $category_stmt = $conn->prepare($category_sql);
    $category_stmt->bind_param("ssss", $start_date, $end_date, $start_date, $end_date);
    $category_stmt->execute();
    $category_result = $category_stmt->get_result();

    $category_breakdown = [];
    while($cat = $category_result->fetch_assoc()) {
        $category_breakdown[] = $cat;
    }

    // Export based on format
    if ($export_type === 'excel') {
        exportRevenueExcel($result, $category_breakdown, $membership_breakdown, $total_revenue, $total_transactions, $start_date, $end_date, $report_type);
    } elseif ($export_type === 'csv') {
        exportRevenueCSV($result, $category_breakdown, $membership_breakdown, $total_revenue, $total_transactions, $start_date, $end_date, $report_type);
    } else { // pdf/html
        exportRevenuePDF($result, $category_breakdown, $membership_breakdown, $total_revenue, $total_transactions, $start_date, $end_date, $report_type);
    }

    // Close statements
    if ($stmt) $stmt->close();
    if (isset($category_stmt)) $category_stmt->close();
}

// EXPENSE EXPORT FUNCTION
function exportExpenseData($conn, $export_type, $start_date, $end_date, $category_filter, $payment_filter, $report_type, $search) {
    // Build query conditions
    $where_conditions = ["e.expense_date BETWEEN ? AND ?"];
    $params = [$start_date, $end_date];
    $types = "ss";

    if ($category_filter && $category_filter != '') {
        $where_conditions[] = "e.category_id = ?";
        $params[] = $category_filter;
        $types .= "i";
    }

    if ($payment_filter && $payment_filter != '') {
        $where_conditions[] = "e.payment_method = ?";
        $params[] = $payment_filter;
        $types .= "s";
    }

    if ($search && $search != '') {
        $where_conditions[] = "(e.description LIKE ? OR ec.name LIKE ?)";
        $search_param = "%$search%";
        $params[] = $search_param;
        $params[] = $search_param;
        $types .= "ss";
    }

    $where_sql = implode(" AND ", $where_conditions);

    // Get expense data
    $sql = "SELECT 
                e.expense_date as date,
                ec.name as category,
                e.description,
                e.payment_method,
                e.amount,
                u.username as recorded_by
            FROM expenses e
            JOIN expense_categories ec ON e.category_id = ec.id
            JOIN users u ON e.recorded_by = u.id
            WHERE $where_sql
            ORDER BY e.expense_date DESC";

    $stmt = $conn->prepare($sql);
    if ($stmt && !empty($params)) {
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
    } else {
        $result = false;
    }

    // Get summary statistics
    $stats_sql = "SELECT 
                    COUNT(e.id) as transaction_count,
                    COALESCE(SUM(e.amount), 0) as total_amount
                  FROM expenses e
                  WHERE e.expense_date BETWEEN ? AND ?";

    $stats_stmt = $conn->prepare($stats_sql);
    $stats_stmt->bind_param("ss", $start_date, $end_date);
    $stats_stmt->execute();
    $stats_result = $stats_stmt->get_result();
    $stats = $stats_result->fetch_assoc();

    $total_expenses = $stats['total_amount'] ?? 0;
    $total_transactions = $stats['transaction_count'] ?? 0;

    // Get category breakdown
    $category_sql = "SELECT 
                        ec.name as category_name,
                        COUNT(e.id) as transaction_count,
                        COALESCE(SUM(e.amount), 0) as total_amount,
                        COALESCE(AVG(e.amount), 0) as average_amount
                      FROM expense_categories ec
                      LEFT JOIN expenses e ON ec.id = e.category_id AND e.expense_date BETWEEN ? AND ?
                      GROUP BY ec.id, ec.name
                      HAVING total_amount > 0
                      ORDER BY total_amount DESC";

    $category_stmt = $conn->prepare($category_sql);
    $category_stmt->bind_param("ss", $start_date, $end_date);
    $category_stmt->execute();
    $category_result = $category_stmt->get_result();

    $category_breakdown = [];
    while($cat = $category_result->fetch_assoc()) {
        $category_breakdown[] = $cat;
    }

    // Export based on format
    if ($export_type === 'excel') {
        exportExpenseExcel($result, $category_breakdown, $total_expenses, $total_transactions, $start_date, $end_date, $report_type);
    } elseif ($export_type === 'csv') {
        exportExpenseCSV($result, $category_breakdown, $total_expenses, $total_transactions, $start_date, $end_date, $report_type);
    } else { // pdf/html
        exportExpensePDF($result, $category_breakdown, $total_expenses, $total_transactions, $start_date, $end_date, $report_type);
    }

    // Close statements
    if ($stmt) $stmt->close();
    $stats_stmt->close();
    $category_stmt->close();
}

// FINANCIAL STATEMENT EXPORT FUNCTION
function exportFinancialStatement($conn, $export_type, $start_date, $end_date, $report_type) {
    // Get ALL revenue including membership payments
    $revenue_sql = "SELECT 
                    'Membership Fees' as category_name,
                    COALESCE(SUM(mp.amount), 0) as total_amount
                  FROM membership_payments mp
                  WHERE mp.payment_date BETWEEN ? AND ?
                  AND mp.status = 'completed'
                  
                  UNION ALL
                  
                  SELECT 
                    rc.name as category_name,
                    COALESCE(SUM(re.amount), 0) as total_amount
                  FROM revenue_categories rc
                  LEFT JOIN revenue_entries re ON rc.id = re.category_id AND re.revenue_date BETWEEN ? AND ?
                  WHERE rc.id IN (1, 4)
                  GROUP BY rc.id, rc.name";

    $revenue_stmt = $conn->prepare($revenue_sql);
    $revenue_stmt->bind_param("ssss", $start_date, $end_date, $start_date, $end_date);
    $revenue_stmt->execute();
    $revenue_result = $revenue_stmt->get_result();

    $total_revenue = 0;
    $revenue_breakdown = [];
    while($row = $revenue_result->fetch_assoc()) {
        $total_revenue += $row['total_amount'] ?? 0;
        if ($row['total_amount'] > 0) {
            $revenue_breakdown[] = $row;
        }
    }

    // Get expense data
    $expense_sql = "SELECT 
                    ec.name as category_name,
                    COALESCE(SUM(e.amount), 0) as total_amount
                  FROM expense_categories ec
                  LEFT JOIN expenses e ON ec.id = e.category_id AND e.expense_date BETWEEN ? AND ?
                  GROUP BY ec.id, ec.name
                  ORDER BY total_amount DESC";

    $expense_stmt = $conn->prepare($expense_sql);
    $expense_stmt->bind_param("ss", $start_date, $end_date);
    $expense_stmt->execute();
    $expense_result = $expense_stmt->get_result();

    $total_expenses = 0;
    $expense_breakdown = [];
    while($row = $expense_result->fetch_assoc()) {
        $total_expenses += $row['total_amount'] ?? 0;
        if ($row['total_amount'] > 0) {
            $expense_breakdown[] = $row;
        }
    }

    $net_profit = $total_revenue - $total_expenses;
    $expense_ratio = $total_revenue > 0 ? ($total_expenses / $total_revenue) * 100 : 0;

    // Export based on format
    if ($export_type === 'excel') {
        exportFinancialExcel($revenue_breakdown, $expense_breakdown, $total_revenue, $total_expenses, $net_profit, $expense_ratio, $start_date, $end_date);
    } elseif ($export_type === 'csv') {
        exportFinancialCSV($revenue_breakdown, $expense_breakdown, $total_revenue, $total_expenses, $net_profit, $expense_ratio, $start_date, $end_date);
    } else { // pdf/html
        exportFinancialPDF($revenue_breakdown, $expense_breakdown, $total_revenue, $total_expenses, $net_profit, $expense_ratio, $start_date, $end_date);
    }

    // Close statements
    $revenue_stmt->close();
    $expense_stmt->close();
}

// REVENUE EXPORT FORMATS
function exportRevenueExcel($data, $category_breakdown, $membership_breakdown, $total_revenue, $total_transactions, $start_date, $end_date, $report_type) {
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="boiyets_revenue_' . date('Y-m-d') . '.xls"');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    // Output UTF-8 BOM for proper character encoding
    echo "\xEF\xBB\xBF";
    
    echo "<html>";
    echo "<head>";
    echo "<meta charset='UTF-8'>";
    echo "<style>";
    echo "body { font-family: Arial, sans-serif; }";
    echo "table { border-collapse: collapse; width: 100%; }";
    echo "th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }";
    echo "th { background-color: #f2f2f2; font-weight: bold; }";
    echo ".summary { background-color: #e8f5e8; }";
    echo ".header { background-color: #fbbf24; color: white; font-weight: bold; }";
    echo ".membership { background-color: #e6f3ff; }";
    echo "</style>";
    echo "</head>";
    echo "<body>";
    
    echo "<table>";
    echo "<tr><th colspan='6' class='header'>BOIYETS FITNESS GYM - REVENUE REPORT</th></tr>";
    echo "<tr><td colspan='6'><strong>Period:</strong> $start_date to $end_date</td></tr>";
    echo "<tr><td colspan='6'><strong>Generated:</strong> " . date('Y-m-d H:i:s') . "</td></tr>";
    echo "<tr><td colspan='6'></td></tr>";
    
    // Summary Section
    echo "<tr><th colspan='6' class='summary'>REVENUE SUMMARY</th></tr>";
    echo "<tr><td colspan='2'><strong>Total Revenue</strong></td><td colspan='4'>" . formatCurrency($total_revenue) . "</td></tr>";
    echo "<tr><td colspan='2'><strong>Total Transactions</strong></td><td colspan='4'>" . $total_transactions . "</td></tr>";
    echo "<tr><td colspan='2'><strong>Average Transaction</strong></td><td colspan='4'>" . ($total_transactions > 0 ? formatCurrency($total_revenue / $total_transactions) : formatCurrency(0)) . "</td></tr>";
    echo "<tr><td colspan='6'></td></tr>";
    
    // Membership Breakdown
    if (!empty($membership_breakdown)) {
        echo "<tr><th colspan='4' class='membership'>MEMBERSHIP REVENUE BREAKDOWN</th></tr>";
        echo "<tr><th>Plan Type</th><th>Transactions</th><th>Amount</th><th>Average</th></tr>";
        foreach ($membership_breakdown as $plan) {
            echo "<tr>";
            echo "<td>" . ucfirst($plan['plan_type']) . "</td>";
            echo "<td>" . $plan['transaction_count'] . "</td>";
            echo "<td>" . formatCurrency($plan['total_amount']) . "</td>";
            echo "<td>" . formatCurrency($plan['average_amount']) . "</td>";
            echo "</tr>";
        }
        echo "<tr><td colspan='6'></td></tr>";
    }
    
    if ($report_type != 'detailed') {
        // Category Breakdown
        echo "<tr><th colspan='4' class='summary'>REVENUE BY CATEGORY</th></tr>";
        echo "<tr><th>Category</th><th>Transactions</th><th>Amount</th><th>Percentage</th></tr>";
        foreach ($category_breakdown as $category) {
            $percentage = $total_revenue > 0 ? ($category['total_amount'] / $total_revenue) * 100 : 0;
            echo "<tr>";
            echo "<td>" . htmlspecialchars($category['category_name']) . "</td>";
            echo "<td>" . $category['transaction_count'] . "</td>";
            echo "<td>" . formatCurrency($category['total_amount']) . "</td>";
            echo "<td>" . number_format($percentage, 1) . "%</td>";
            echo "</tr>";
        }
        echo "<tr><td colspan='6'></td></tr>";
    }
    
    if ($report_type != 'summary') {
        // Detailed Transactions
        echo "<tr><th colspan='6' class='summary'>DETAILED REVENUE ENTRIES</th></tr>";
        echo "<tr><th>Date</th><th>Category</th><th>Description</th><th>Payment Method</th><th>Amount</th><th>Recorded By</th></tr>";
        
        if ($data) {
            $data->data_seek(0);
            while($row = $data->fetch_assoc()) {
                echo "<tr>";
                echo "<td>" . $row['date'] . "</td>";
                echo "<td>" . htmlspecialchars($row['category']) . "</td>";
                echo "<td>" . htmlspecialchars($row['description']) . "</td>";
                echo "<td>" . htmlspecialchars($row['payment_method']) . "</td>";
                echo "<td>" . formatCurrency($row['amount']) . "</td>";
                echo "<td>" . htmlspecialchars($row['recorded_by']) . "</td>";
                echo "</tr>";
            }
        }
    }
    
    echo "</table>";
    echo "</body>";
    echo "</html>";
    exit;
}

function exportRevenueCSV($data, $category_breakdown, $membership_breakdown, $total_revenue, $total_transactions, $start_date, $end_date, $report_type) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="boiyets_revenue_' . date('Y-m-d') . '.csv"');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    $output = fopen('php://output', 'w');
    
    // Add BOM for UTF-8
    fputs($output, "\xEF\xBB\xBF");
    
    fputcsv($output, ['BOIYETS FITNESS GYM - REVENUE REPORT']);
    fputcsv($output, ['Generated on:', date('F j, Y g:i A')]);
    fputcsv($output, ['Report Period:', date('M j, Y', strtotime($start_date)) . ' to ' . date('M j, Y', strtotime($end_date))]);
    fputcsv($output, []);
    
    // Summary Section
    fputcsv($output, ['REVENUE SUMMARY']);
    fputcsv($output, ['Total Revenue', formatCurrency($total_revenue)]);
    fputcsv($output, ['Total Transactions', $total_transactions]);
    fputcsv($output, ['Average Transaction', ($total_transactions > 0 ? formatCurrency($total_revenue / $total_transactions) : formatCurrency(0))]);
    fputcsv($output, []);
    
    // Membership Breakdown
    if (!empty($membership_breakdown)) {
        fputcsv($output, ['MEMBERSHIP REVENUE BREAKDOWN']);
        fputcsv($output, ['Plan Type', 'Transactions', 'Amount', 'Average']);
        foreach ($membership_breakdown as $plan) {
            fputcsv($output, [
                ucfirst($plan['plan_type']),
                $plan['transaction_count'],
                formatCurrency($plan['total_amount']),
                formatCurrency($plan['average_amount'])
            ]);
        }
        fputcsv($output, []);
    }
    
    if ($report_type != 'detailed') {
        // Category Breakdown
        fputcsv($output, ['REVENUE BY CATEGORY']);
        fputcsv($output, ['Category', 'Transactions', 'Amount', 'Percentage']);
        foreach ($category_breakdown as $category) {
            $percentage = $total_revenue > 0 ? ($category['total_amount'] / $total_revenue) * 100 : 0;
            fputcsv($output, [
                $category['category_name'],
                $category['transaction_count'],
                formatCurrency($category['total_amount']),
                number_format($percentage, 1) . '%'
            ]);
        }
        fputcsv($output, []);
    }
    
    if ($report_type != 'summary') {
        // Detailed Transactions
        fputcsv($output, ['DETAILED REVENUE ENTRIES']);
        fputcsv($output, ['Date', 'Category', 'Description', 'Payment Method', 'Amount', 'Recorded By']);
        
        if ($data) {
            $data->data_seek(0);
            while($row = $data->fetch_assoc()) {
                fputcsv($output, [
                    $row['date'],
                    $row['category'],
                    $row['description'],
                    $row['payment_method'],
                    formatCurrency($row['amount']),
                    $row['recorded_by']
                ]);
            }
        }
    }
    
    fclose($output);
    exit;
}

function exportRevenuePDF($data, $category_breakdown, $membership_breakdown, $total_revenue, $total_transactions, $start_date, $end_date, $report_type) {
    header('Content-Type: text/html; charset=utf-8');
    header('Content-Disposition: attachment; filename="boiyets_revenue_' . date('Y-m-d') . '.html"');
    
    $has_data = $data ? $data->num_rows > 0 : false;
    if ($data) $data->data_seek(0);
    
    echo '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>BOIYETS FITNESS GYM - REVENUE REPORT</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; color: #333; }
        .header { text-align: center; margin-bottom: 30px; border-bottom: 3px solid #fbbf24; padding-bottom: 20px; }
        .header h1 { color: #fbbf24; margin: 0; font-size: 28px; font-weight: bold; }
        .header p { margin: 8px 0; color: #666; font-size: 14px; }
        .section { margin-bottom: 30px; }
        .section-title { background-color: #fbbf24; color: white; padding: 12px; font-weight: bold; border-radius: 8px; font-size: 16px; margin-bottom: 15px; }
        .membership-title { background-color: #3b82f6; color: white; padding: 12px; font-weight: bold; border-radius: 8px; font-size: 16px; margin-bottom: 15px; }
        table { width: 100%; border-collapse: collapse; margin: 15px 0; }
        th { background-color: #f8fafc; text-align: left; padding: 12px 15px; border: 1px solid #e5e7eb; font-weight: bold; color: #374151; }
        td { padding: 10px 15px; border: 1px solid #e5e7eb; }
        .summary-item { margin: 10px 0; padding: 8px; font-size: 14px; }
        .summary-item strong { color: #1f2937; }
        .amount { font-weight: bold; color: #059669; }
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        .footer { margin-top: 40px; text-align: center; color: #6b7280; font-size: 12px; border-top: 1px solid #e5e7eb; padding-top: 20px; }
        tr:nth-child(even) { background-color: #f9fafb; }
        .no-data { text-align: center; padding: 40px; color: #6b7280; font-style: italic; background-color: #f9fafb; border-radius: 8px; }
    </style>
</head>
<body>
    <div class="header">
        <h1>BOIYETS FITNESS GYM</h1>
        <h2 style="color: #1f2937; margin: 10px 0;">REVENUE REPORT</h2>
        <p><strong>Generated on:</strong> ' . date('F j, Y g:i A') . '</p>
        <p><strong>Report Period:</strong> ' . date('M j, Y', strtotime($start_date)) . ' to ' . date('M j, Y', strtotime($end_date)) . '</p>
    </div>';

    if (!$has_data && empty($membership_breakdown)) {
        echo '
    <div class="section">
        <div class="no-data">
            <h3>No Revenue Data Found</h3>
            <p>No revenue entries match the selected criteria for the specified date range.</p>
        </div>
    </div>';
    } else {
        echo '
    <div class="section">
        <div class="section-title">REVENUE SUMMARY</div>
        <div class="summary-item"><strong>Total Revenue:</strong> <span class="amount">' . formatCurrency($total_revenue) . '</span></div>
        <div class="summary-item"><strong>Total Transactions:</strong> ' . $total_transactions . '</div>
        <div class="summary-item"><strong>Average Transaction:</strong> ' . ($total_transactions > 0 ? formatCurrency($total_revenue / $total_transactions) : formatCurrency(0)) . '</div>
    </div>';

        if (!empty($membership_breakdown)) {
            echo '
    <div class="section">
        <div class="membership-title">MEMBERSHIP REVENUE BREAKDOWN</div>
        <table>
            <tr>
                <th>Plan Type</th>
                <th class="text-center">Transactions</th>
                <th class="text-right">Amount</th>
                <th class="text-right">Average</th>
            </tr>';
            
            foreach ($membership_breakdown as $plan) {
                echo '
            <tr>
                <td>' . ucfirst($plan['plan_type']) . '</td>
                <td class="text-center">' . $plan['transaction_count'] . '</td>
                <td class="text-right amount">' . formatCurrency($plan['total_amount']) . '</td>
                <td class="text-right">' . formatCurrency($plan['average_amount']) . '</td>
            </tr>';
            }
            echo '
        </table>
    </div>';
        }

        if ($report_type != 'detailed') {
            if (!empty($category_breakdown)) {
                echo '
    <div class="section">
        <div class="section-title">REVENUE BY CATEGORY</div>
        <table>
            <tr>
                <th>Category</th>
                <th class="text-center">Transactions</th>
                <th class="text-right">Amount</th>
                <th class="text-center">Percentage</th>
            </tr>';
                
                foreach ($category_breakdown as $category) {
                    $percentage = $total_revenue > 0 ? ($category['total_amount'] / $total_revenue) * 100 : 0;
                    echo '
            <tr>
                <td>' . htmlspecialchars($category['category_name']) . '</td>
                <td class="text-center">' . $category['transaction_count'] . '</td>
                <td class="text-right amount">' . formatCurrency($category['total_amount']) . '</td>
                <td class="text-center">' . number_format($percentage, 1) . '%</td>
            </tr>';
                }
                echo '
        </table>
    </div>';
            }
        }

        if ($report_type != 'summary' && $has_data) {
            echo '
    <div class="section">
        <div class="section-title">DETAILED REVENUE ENTRIES</div>
        <table>
            <tr>
                <th>Date</th>
                <th>Category</th>
                <th>Description</th>
                <th>Payment Method</th>
                <th class="text-right">Amount</th>
            </tr>';
            
            while($row = $data->fetch_assoc()) {
                echo '
            <tr>
                <td>' . date('M j, Y', strtotime($row['date'])) . '</td>
                <td>' . htmlspecialchars($row['category']) . '</td>
                <td>' . htmlspecialchars($row['description']) . '</td>
                <td>' . ucfirst($row['payment_method']) . '</td>
                <td class="text-right amount">' . formatCurrency($row['amount']) . '</td>
            </tr>';
            }
            echo '
        </table>
    </div>';
        }
    }

    echo '
    <div class="footer">
        <p>Report generated by BOIYETS FITNESS GYM Management System</p>
        <p>Page generated on ' . date('F j, Y \a\t g:i A') . '</p>
    </div>
</body>
</html>';
    exit;
}

// EXPENSE EXPORT FORMATS (similar structure to revenue)
function exportExpenseExcel($data, $category_breakdown, $total_expenses, $total_transactions, $start_date, $end_date, $report_type) {
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="boiyets_expenses_' . date('Y-m-d') . '.xls"');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    // Output UTF-8 BOM for proper character encoding
    echo "\xEF\xBB\xBF";
    
    echo "<html>";
    echo "<head>";
    echo "<meta charset='UTF-8'>";
    echo "<style>";
    echo "body { font-family: Arial, sans-serif; }";
    echo "table { border-collapse: collapse; width: 100%; }";
    echo "th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }";
    echo "th { background-color: #f2f2f2; font-weight: bold; }";
    echo ".summary { background-color: #ffe6e6; }";
    echo ".header { background-color: #ef4444; color: white; font-weight: bold; }";
    echo "</style>";
    echo "</head>";
    echo "<body>";
    
    echo "<table>";
    echo "<tr><th colspan='5' class='header'>BOIYETS FITNESS GYM - EXPENSE REPORT</th></tr>";
    echo "<tr><td colspan='5'><strong>Period:</strong> $start_date to $end_date</td></tr>";
    echo "<tr><td colspan='5'><strong>Generated:</strong> " . date('Y-m-d H:i:s') . "</td></tr>";
    echo "<tr><td colspan='5'></td></tr>";
    
    // Summary Section
    echo "<tr><th colspan='5' class='summary'>EXPENSE SUMMARY</th></tr>";
    echo "<tr><td colspan='2'><strong>Total Expenses</strong></td><td colspan='3'>" . formatCurrency($total_expenses) . "</td></tr>";
    echo "<tr><td colspan='2'><strong>Total Transactions</strong></td><td colspan='3'>" . $total_transactions . "</td></tr>";
    echo "<tr><td colspan='2'><strong>Average Expense</strong></td><td colspan='3'>" . ($total_transactions > 0 ? formatCurrency($total_expenses / $total_transactions) : formatCurrency(0)) . "</td></tr>";
    echo "<tr><td colspan='5'></td></tr>";
    
    if ($report_type != 'detailed') {
        // Category Breakdown
        echo "<tr><th colspan='4' class='summary'>EXPENSES BY CATEGORY</th></tr>";
        echo "<tr><th>Category</th><th>Transactions</th><th>Amount</th><th>Percentage</th></tr>";
        foreach ($category_breakdown as $category) {
            $percentage = $total_expenses > 0 ? ($category['total_amount'] / $total_expenses) * 100 : 0;
            echo "<tr>";
            echo "<td>" . htmlspecialchars($category['category_name']) . "</td>";
            echo "<td>" . $category['transaction_count'] . "</td>";
            echo "<td>" . formatCurrency($category['total_amount']) . "</td>";
            echo "<td>" . number_format($percentage, 1) . "%</td>";
            echo "</tr>";
        }
        echo "<tr><td colspan='5'></td></tr>";
    }
    
    if ($report_type != 'summary') {
        // Detailed Transactions
        echo "<tr><th colspan='5' class='summary'>DETAILED EXPENSE ENTRIES</th></tr>";
        echo "<tr><th>Date</th><th>Category</th><th>Description</th><th>Payment Method</th><th>Amount</th></tr>";
        
        if ($data) {
            $data->data_seek(0);
            while($row = $data->fetch_assoc()) {
                echo "<tr>";
                echo "<td>" . $row['date'] . "</td>";
                echo "<td>" . htmlspecialchars($row['category']) . "</td>";
                echo "<td>" . htmlspecialchars($row['description']) . "</td>";
                echo "<td>" . htmlspecialchars($row['payment_method']) . "</td>";
                echo "<td>" . formatCurrency($row['amount']) . "</td>";
                echo "</tr>";
            }
        }
    }
    
    echo "</table>";
    echo "</body>";
    echo "</html>";
    exit;
}

function exportExpenseCSV($data, $category_breakdown, $total_expenses, $total_transactions, $start_date, $end_date, $report_type) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="boiyets_expenses_' . date('Y-m-d') . '.csv"');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    $output = fopen('php://output', 'w');
    
    // Add BOM for UTF-8
    fputs($output, "\xEF\xBB\xBF");
    
    fputcsv($output, ['BOIYETS FITNESS GYM - EXPENSE REPORT']);
    fputcsv($output, ['Generated on:', date('F j, Y g:i A')]);
    fputcsv($output, ['Report Period:', date('M j, Y', strtotime($start_date)) . ' to ' . date('M j, Y', strtotime($end_date))]);
    fputcsv($output, []);
    
    // Summary Section
    fputcsv($output, ['EXPENSE SUMMARY']);
    fputcsv($output, ['Total Expenses', formatCurrency($total_expenses)]);
    fputcsv($output, ['Total Transactions', $total_transactions]);
    fputcsv($output, ['Average Expense', ($total_transactions > 0 ? formatCurrency($total_expenses / $total_transactions) : formatCurrency(0))]);
    fputcsv($output, []);
    
    if ($report_type != 'detailed') {
        // Category Breakdown
        fputcsv($output, ['EXPENSES BY CATEGORY']);
        fputcsv($output, ['Category', 'Transactions', 'Amount', 'Percentage']);
        foreach ($category_breakdown as $category) {
            $percentage = $total_expenses > 0 ? ($category['total_amount'] / $total_expenses) * 100 : 0;
            fputcsv($output, [
                $category['category_name'],
                $category['transaction_count'],
                formatCurrency($category['total_amount']),
                number_format($percentage, 1) . '%'
            ]);
        }
        fputcsv($output, []);
    }
    
    if ($report_type != 'summary') {
        // Detailed Transactions
        fputcsv($output, ['DETAILED EXPENSE ENTRIES']);
        fputcsv($output, ['Date', 'Category', 'Description', 'Payment Method', 'Amount']);
        
        if ($data) {
            $data->data_seek(0);
            while($row = $data->fetch_assoc()) {
                fputcsv($output, [
                    $row['date'],
                    $row['category'],
                    $row['description'],
                    $row['payment_method'],
                    formatCurrency($row['amount'])
                ]);
            }
        }
    }
    
    fclose($output);
    exit;
}

function exportExpensePDF($data, $category_breakdown, $total_expenses, $total_transactions, $start_date, $end_date, $report_type) {
    header('Content-Type: text/html; charset=utf-8');
    header('Content-Disposition: attachment; filename="boiyets_expenses_' . date('Y-m-d') . '.html"');
    
    $has_data = $data ? $data->num_rows > 0 : false;
    if ($data) $data->data_seek(0);
    
    echo '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>BOIYETS FITNESS GYM - EXPENSE REPORT</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; color: #333; }
        .header { text-align: center; margin-bottom: 30px; border-bottom: 3px solid #ef4444; padding-bottom: 20px; }
        .header h1 { color: #ef4444; margin: 0; font-size: 28px; font-weight: bold; }
        .header p { margin: 8px 0; color: #666; font-size: 14px; }
        .section { margin-bottom: 30px; }
        .section-title { background-color: #ef4444; color: white; padding: 12px; font-weight: bold; border-radius: 8px; font-size: 16px; margin-bottom: 15px; }
        table { width: 100%; border-collapse: collapse; margin: 15px 0; }
        th { background-color: #f8fafc; text-align: left; padding: 12px 15px; border: 1px solid #e5e7eb; font-weight: bold; color: #374151; }
        td { padding: 10px 15px; border: 1px solid #e5e7eb; }
        .summary-item { margin: 10px 0; padding: 8px; font-size: 14px; }
        .summary-item strong { color: #1f2937; }
        .expense-amount { font-weight: bold; color: #dc2626; }
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        .footer { margin-top: 40px; text-align: center; color: #6b7280; font-size: 12px; border-top: 1px solid #e5e7eb; padding-top: 20px; }
        tr:nth-child(even) { background-color: #f9fafb; }
        .no-data { text-align: center; padding: 40px; color: #6b7280; font-style: italic; background-color: #f9fafb; border-radius: 8px; }
    </style>
</head>
<body>
    <div class="header">
        <h1>BOIYETS FITNESS GYM</h1>
        <h2 style="color: #1f2937; margin: 10px 0;">EXPENSE REPORT</h2>
        <p><strong>Generated on:</strong> ' . date('F j, Y g:i A') . '</p>
        <p><strong>Report Period:</strong> ' . date('M j, Y', strtotime($start_date)) . ' to ' . date('M j, Y', strtotime($end_date)) . '</p>
    </div>';

    if (!$has_data) {
        echo '
    <div class="section">
        <div class="no-data">
            <h3>No Expense Data Found</h3>
            <p>No expense entries match the selected criteria for the specified date range.</p>
        </div>
    </div>';
    } else {
        echo '
    <div class="section">
        <div class="section-title">EXPENSE SUMMARY</div>
        <div class="summary-item"><strong>Total Expenses:</strong> <span class="expense-amount">' . formatCurrency($total_expenses) . '</span></div>
        <div class="summary-item"><strong>Total Transactions:</strong> ' . $total_transactions . '</div>
        <div class="summary-item"><strong>Average Expense:</strong> ' . ($total_transactions > 0 ? formatCurrency($total_expenses / $total_transactions) : formatCurrency(0)) . '</div>
    </div>';

        if ($report_type != 'detailed') {
            if (!empty($category_breakdown)) {
                echo '
    <div class="section">
        <div class="section-title">EXPENSES BY CATEGORY</div>
        <table>
            <tr>
                <th>Category</th>
                <th class="text-center">Transactions</th>
                <th class="text-right">Amount</th>
                <th class="text-center">Percentage</th>
            </tr>';
                
                foreach ($category_breakdown as $category) {
                    $percentage = $total_expenses > 0 ? ($category['total_amount'] / $total_expenses) * 100 : 0;
                    echo '
            <tr>
                <td>' . htmlspecialchars($category['category_name']) . '</td>
                <td class="text-center">' . $category['transaction_count'] . '</td>
                <td class="text-right expense-amount">' . formatCurrency($category['total_amount']) . '</td>
                <td class="text-center">' . number_format($percentage, 1) . '%</td>
            </tr>';
                }
                echo '
        </table>
    </div>';
            }
        }

        if ($report_type != 'summary') {
            echo '
    <div class="section">
        <div class="section-title">DETAILED EXPENSE ENTRIES</div>
        <table>
            <tr>
                <th>Date</th>
                <th>Category</th>
                <th>Description</th>
                <th>Payment Method</th>
                <th class="text-right">Amount</th>
            </tr>';
            
            while($row = $data->fetch_assoc()) {
                echo '
            <tr>
                <td>' . date('M j, Y', strtotime($row['date'])) . '</td>
                <td>' . htmlspecialchars($row['category']) . '</td>
                <td>' . htmlspecialchars($row['description']) . '</td>
                <td>' . ucfirst($row['payment_method']) . '</td>
                <td class="text-right expense-amount">' . formatCurrency($row['amount']) . '</td>
            </tr>';
            }
            echo '
        </table>
    </div>';
        }
    }

    echo '
    <div class="footer">
        <p>Report generated by BOIYETS FITNESS GYM Management System</p>
        <p>Page generated on ' . date('F j, Y \a\t g:i A') . '</p>
    </div>
</body>
</html>';
    exit;
}

// FINANCIAL STATEMENT EXPORT FORMATS
function exportFinancialExcel($revenue_breakdown, $expense_breakdown, $total_revenue, $total_expenses, $net_profit, $expense_ratio, $start_date, $end_date) {
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="boiyets_financial_' . date('Y-m-d') . '.xls"');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    // Output UTF-8 BOM for proper character encoding
    echo "\xEF\xBB\xBF";
    
    echo "<html>";
    echo "<head>";
    echo "<meta charset='UTF-8'>";
    echo "<style>";
    echo "body { font-family: Arial, sans-serif; }";
    echo "table { border-collapse: collapse; width: 100%; }";
    echo "th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }";
    echo "th { background-color: #f2f2f2; font-weight: bold; }";
    echo ".summary { background-color: #e6f3ff; }";
    echo ".header { background-color: #10b981; color: white; font-weight: bold; }";
    echo ".positive { color: #059669; }";
    echo ".negative { color: #dc2626; }";
    echo "</style>";
    echo "</head>";
    echo "<body>";
    
    echo "<table>";
    echo "<tr><th colspan='4' class='header'>BOIYETS FITNESS GYM - FINANCIAL STATEMENT</th></tr>";
    echo "<tr><td colspan='4'><strong>Period:</strong> $start_date to $end_date</td></tr>";
    echo "<tr><td colspan='4'><strong>Generated:</strong> " . date('Y-m-d H:i:s') . "</td></tr>";
    echo "<tr><td colspan='4'></td></tr>";
    
    // Financial Summary
    echo "<tr><th colspan='4' class='summary'>FINANCIAL SUMMARY</th></tr>";
    echo "<tr><td colspan='2'><strong>Total Revenue</strong></td><td colspan='2'>" . formatCurrency($total_revenue) . "</td></tr>";
    echo "<tr><td colspan='2'><strong>Total Expenses</strong></td><td colspan='2'>" . formatCurrency($total_expenses) . "</td></tr>";
    $profit_class = $net_profit >= 0 ? 'positive' : 'negative';
    echo "<tr><td colspan='2'><strong>Net Profit/Loss</strong></td><td colspan='2' class='$profit_class'>" . formatCurrency($net_profit) . "</td></tr>";
    echo "<tr><td colspan='2'><strong>Expense Ratio</strong></td><td colspan='2'>" . number_format($expense_ratio, 1) . "%</td></tr>";
    echo "<tr><td colspan='4'></td></tr>";
    
    // Revenue Breakdown
    echo "<tr><th colspan='4' class='summary'>REVENUE BREAKDOWN</th></tr>";
    echo "<tr><th>Category</th><th>Amount</th><th>Percentage</th></tr>";
    foreach ($revenue_breakdown as $revenue) {
        $percentage = $total_revenue > 0 ? ($revenue['total_amount'] / $total_revenue) * 100 : 0;
        echo "<tr>";
        echo "<td>" . htmlspecialchars($revenue['category_name']) . "</td>";
        echo "<td>" . formatCurrency($revenue['total_amount']) . "</td>";
        echo "<td>" . number_format($percentage, 1) . "%</td>";
        echo "</tr>";
    }
    echo "<tr><td colspan='4'></td></tr>";
    
    // Expense Breakdown
    echo "<tr><th colspan='4' class='summary'>EXPENSE BREAKDOWN</th></tr>";
    echo "<tr><th>Category</th><th>Amount</th><th>Percentage</th></tr>";
    foreach ($expense_breakdown as $expense) {
        $percentage = $total_expenses > 0 ? ($expense['total_amount'] / $total_expenses) * 100 : 0;
        echo "<tr>";
        echo "<td>" . htmlspecialchars($expense['category_name']) . "</td>";
        echo "<td>" . formatCurrency($expense['total_amount']) . "</td>";
        echo "<td>" . number_format($percentage, 1) . "%</td>";
        echo "</tr>";
    }
    
    echo "</table>";
    echo "</body>";
    echo "</html>";
    exit;
}

function exportFinancialCSV($revenue_breakdown, $expense_breakdown, $total_revenue, $total_expenses, $net_profit, $expense_ratio, $start_date, $end_date) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="boiyets_financial_' . date('Y-m-d') . '.csv"');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    $output = fopen('php://output', 'w');
    
    // Add BOM for UTF-8
    fputs($output, "\xEF\xBB\xBF");
    
    fputcsv($output, ['BOIYETS FITNESS GYM - FINANCIAL STATEMENT']);
    fputcsv($output, ['Generated on:', date('F j, Y g:i A')]);
    fputcsv($output, ['Report Period:', date('M j, Y', strtotime($start_date)) . ' to ' . date('M j, Y', strtotime($end_date))]);
    fputcsv($output, []);
    
    // Financial Summary
    fputcsv($output, ['FINANCIAL SUMMARY']);
    fputcsv($output, ['Total Revenue', formatCurrency($total_revenue)]);
    fputcsv($output, ['Total Expenses', formatCurrency($total_expenses)]);
    fputcsv($output, ['Net Profit/Loss', formatCurrency($net_profit)]);
    fputcsv($output, ['Expense Ratio', number_format($expense_ratio, 1) . '%']);
    fputcsv($output, []);
    
    // Revenue Breakdown
    fputcsv($output, ['REVENUE BREAKDOWN']);
    fputcsv($output, ['Category', 'Amount', 'Percentage']);
    foreach ($revenue_breakdown as $revenue) {
        $percentage = $total_revenue > 0 ? ($revenue['total_amount'] / $total_revenue) * 100 : 0;
        fputcsv($output, [
            $revenue['category_name'],
            formatCurrency($revenue['total_amount']),
            number_format($percentage, 1) . '%'
        ]);
    }
    fputcsv($output, []);
    
    // Expense Breakdown
    fputcsv($output, ['EXPENSE BREAKDOWN']);
    fputcsv($output, ['Category', 'Amount', 'Percentage']);
    foreach ($expense_breakdown as $expense) {
        $percentage = $total_expenses > 0 ? ($expense['total_amount'] / $total_expenses) * 100 : 0;
        fputcsv($output, [
            $expense['category_name'],
            formatCurrency($expense['total_amount']),
            number_format($percentage, 1) . '%'
        ]);
    }
    
    fclose($output);
    exit;
}

function exportFinancialPDF($revenue_breakdown, $expense_breakdown, $total_revenue, $total_expenses, $net_profit, $expense_ratio, $start_date, $end_date) {
    header('Content-Type: text/html; charset=utf-8');
    header('Content-Disposition: attachment; filename="boiyets_financial_' . date('Y-m-d') . '.html"');
    
    $profit_class = $net_profit >= 0 ? 'positive' : 'negative';
    $profit_text = $net_profit >= 0 ? 'Profit' : 'Loss';
    
    echo '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>BOIYETS FITNESS GYM - FINANCIAL STATEMENT</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; color: #333; }
        .header { text-align: center; margin-bottom: 30px; border-bottom: 3px solid #10b981; padding-bottom: 20px; }
        .header h1 { color: #10b981; margin: 0; font-size: 28px; font-weight: bold; }
        .header p { margin: 8px 0; color: #666; font-size: 14px; }
        .section { margin-bottom: 30px; }
        .section-title { background-color: #10b981; color: white; padding: 12px; font-weight: bold; border-radius: 8px; font-size: 16px; margin-bottom: 15px; }
        table { width: 100%; border-collapse: collapse; margin: 15px 0; }
        th { background-color: #f8fafc; text-align: left; padding: 12px 15px; border: 1px solid #e5e7eb; font-weight: bold; color: #374151; }
        td { padding: 10px 15px; border: 1px solid #e5e7eb; }
        .summary-item { margin: 10px 0; padding: 8px; font-size: 14px; }
        .summary-item strong { color: #1f2937; }
        .positive { color: #10b981; font-weight: bold; }
        .negative { color: #ef4444; font-weight: bold; }
        .revenue-amount { color: #059669; font-weight: bold; }
        .expense-amount { color: #dc2626; font-weight: bold; }
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        .footer { margin-top: 40px; text-align: center; color: #6b7280; font-size: 12px; border-top: 1px solid #e5e7eb; padding-top: 20px; }
        .financial-highlight { background-color: #f0fdf4; padding: 15px; border-radius: 8px; border-left: 4px solid #10b981; margin: 20px 0; }
        tr:nth-child(even) { background-color: #f9fafb; }
        .no-data { text-align: center; padding: 40px; color: #6b7280; font-style: italic; background-color: #f9fafb; border-radius: 8px; }
    </style>
</head>
<body>
    <div class="header">
        <h1>BOIYETS FITNESS GYM</h1>
        <h2 style="color: #1f2937; margin: 10px 0;">FINANCIAL STATEMENT</h2>
        <p><strong>Generated on:</strong> ' . date('F j, Y g:i A') . '</p>
        <p><strong>Report Period:</strong> ' . date('M j, Y', strtotime($start_date)) . ' to ' . date('M j, Y', strtotime($end_date)) . '</p>
    </div>';

    if ($total_revenue == 0 && $total_expenses == 0) {
        echo '
    <div class="section">
        <div class="no-data">
            <h3>No Financial Data Found</h3>
            <p>No revenue or expense entries found for the specified date range.</p>
        </div>
    </div>';
    } else {
        echo '
    <div class="financial-highlight">
        <div style="text-align: center; font-size: 18px; font-weight: bold; margin-bottom: 10px;">FINANCIAL OVERVIEW</div>
        <div style="display: flex; justify-content: space-around; text-align: center;">
            <div>
                <div style="font-size: 14px; color: #6b7280;">Total Revenue</div>
                <div style="font-size: 20px; font-weight: bold; color: #059669;">' . formatCurrency($total_revenue) . '</div>
            </div>
            <div>
                <div style="font-size: 14px; color: #6b7280;">Total Expenses</div>
                <div style="font-size: 20px; font-weight: bold; color: #dc2626;">' . formatCurrency($total_expenses) . '</div>
            </div>
            <div>
                <div style="font-size: 14px; color: #6b7280;">Net ' . $profit_text . '</div>
                <div style="font-size: 20px; font-weight: bold; color: ' . ($net_profit >= 0 ? '#059669' : '#dc2626') . ';">' . formatCurrency($net_profit) . '</div>
            </div>
        </div>
    </div>

    <div class="section">
        <div class="section-title">FINANCIAL SUMMARY</div>
        <div class="summary-item"><strong>Total Revenue:</strong> <span class="revenue-amount">' . formatCurrency($total_revenue) . '</span></div>
        <div class="summary-item"><strong>Total Expenses:</strong> <span class="expense-amount">' . formatCurrency($total_expenses) . '</span></div>
        <div class="summary-item"><strong>Net Profit/Loss:</strong> <span class="' . $profit_class . '">' . formatCurrency($net_profit) . '</span></div>
        <div class="summary-item"><strong>Expense Ratio:</strong> ' . number_format($expense_ratio, 1) . '%</div>
        <div class="summary-item"><strong>Profit Margin:</strong> ' . ($total_revenue > 0 ? number_format(($net_profit / $total_revenue) * 100, 1) : '0.0') . '%</div>
    </div>';

        if (!empty($revenue_breakdown)) {
            echo '
    <div class="section">
        <div class="section-title">REVENUE BREAKDOWN</div>
        <table>
            <tr>
                <th>Category</th>
                <th class="text-right">Amount</th>
                <th class="text-center">Percentage</th>
            </tr>';
        
            foreach ($revenue_breakdown as $revenue) {
                $percentage = $total_revenue > 0 ? ($revenue['total_amount'] / $total_revenue) * 100 : 0;
                echo '
            <tr>
                <td>' . htmlspecialchars($revenue['category_name']) . '</td>
                <td class="text-right revenue-amount">' . formatCurrency($revenue['total_amount']) . '</td>
                <td class="text-center">' . number_format($percentage, 1) . '%</td>
            </tr>';
            }
            echo '
        </table>
    </div>';
        }

        if (!empty($expense_breakdown)) {
            echo '
    <div class="section">
        <div class="section-title">EXPENSE BREAKDOWN</div>
        <table>
            <tr>
                <th>Category</th>
                <th class="text-right">Amount</th>
                <th class="text-center">Percentage</th>
            </tr>';
        
            foreach ($expense_breakdown as $expense) {
                $percentage = $total_expenses > 0 ? ($expense['total_amount'] / $total_expenses) * 100 : 0;
                echo '
            <tr>
                <td>' . htmlspecialchars($expense['category_name']) . '</td>
                <td class="text-right expense-amount">' . formatCurrency($expense['total_amount']) . '</td>
                <td class="text-center">' . number_format($percentage, 1) . '%</td>
            </tr>';
            }
            echo '
        </table>
    </div>';
        }
    }

    echo '
    <div class="footer">
        <p>Report generated by BOIYETS FITNESS GYM Management System</p>
        <p>Page generated on ' . date('F j, Y \a\t g:i A') . '</p>
    </div>
</body>
</html>';
    exit;
}
?>




<?php
if (!isset($con)) {
    include(__DIR__ . '/../../includes/db.php');
}

// Ensure monthly performance table exists
$createPerformance = "CREATE TABLE IF NOT EXISTS `emp_performance` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `emp_id` INT NOT NULL,
    `perf_year` INT NOT NULL,
    `perf_month` INT NOT NULL,
    `absent` TINYINT UNSIGNED DEFAULT 0,
    `late` TINYINT UNSIGNED DEFAULT 0,
    `task_sheet` TINYINT UNSIGNED DEFAULT 0,
    `performance_score` TINYINT UNSIGNED DEFAULT 0,
    `dressing_behaviour` TINYINT UNSIGNED DEFAULT 0,
    `rnd` TINYINT UNSIGNED DEFAULT 0,
    `total` INT DEFAULT 0,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `emp_month` (`emp_id`, `perf_year`, `perf_month`),
    FOREIGN KEY (`emp_id`) REFERENCES `emp_list` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB";
mysqli_query($con, $createPerformance);

// Month/year context (always current)
$currentYear  = (int)date('Y');
$currentMonth = (int)date('m');

$perfMessage = '';
$perfError = '';

// Save performance (upsert)
if (isset($_POST['save_performance'])) {
    $empId = isset($_POST['emp_id']) ? (int)$_POST['emp_id'] : 0;
    $pYear = isset($_POST['perf_year']) ? (int)$_POST['perf_year'] : $currentYear;
    $pMonth = isset($_POST['perf_month']) ? (int)$_POST['perf_month'] : $currentMonth;

    $maxScores = array(
        'absent' => 20,
        'late' => 10,
        'task_sheet' => 10,
        'performance_score' => 35,
        'dressing_behaviour' => 10,
        'rnd' => 15
    );

    $scores = array();
    foreach ($maxScores as $key => $limit) {
        // Use float for performance_score to preserve decimals, int for others
        if ($key === 'performance_score') {
            $val = isset($_POST[$key]) ? (float)$_POST[$key] : 0;
        } else {
            $val = isset($_POST[$key]) ? (int)$_POST[$key] : 0;
        }
        if ($val < 0) $val = 0;
        if ($val > $limit) $val = $limit;
        $scores[$key] = $val;
    }

    $total = array_sum($scores);
    if ($total > 100) {
        $perfError = "Total score cannot exceed 100.";
    } elseif ($empId > 0 && $pMonth >= 1 && $pMonth <= 12) {
        $insert = "INSERT INTO emp_performance 
            (emp_id, perf_year, perf_month, absent, late, task_sheet, performance_score, dressing_behaviour, rnd, total)
            VALUES 
            ('$empId', '$pYear', '$pMonth', '{$scores['absent']}', '{$scores['late']}', '{$scores['task_sheet']}', '{$scores['performance_score']}', '{$scores['dressing_behaviour']}', '{$scores['rnd']}', '$total')
            ON DUPLICATE KEY UPDATE 
            absent=VALUES(absent),
            late=VALUES(late),
            task_sheet=VALUES(task_sheet),
            performance_score=VALUES(performance_score),
            dressing_behaviour=VALUES(dressing_behaviour),
            rnd=VALUES(rnd),
            total=VALUES(total)";
        if (mysqli_query($con, $insert)) {
            $perfMessage = "Performance saved for " . htmlspecialchars($_POST['emp_name'] ?? 'employee');
        } else {
            $perfError = "Could not save performance.";
        }
    } else {
        $perfError = "Invalid performance data.";
    }
}

// Fetch performance map for selected month/year
$performanceMap = array();
$perfQuery = mysqli_query($con, "SELECT * FROM emp_performance WHERE perf_year='$currentYear' AND perf_month='$currentMonth'");
if ($perfQuery && mysqli_num_rows($perfQuery) > 0) {
    while ($p = mysqli_fetch_assoc($perfQuery)) {
        $performanceMap[(int)$p['emp_id']] = $p;
    }
}



// Build last 4 months list (including current) for history display
$historyMonths = array();
for ($i = 0; $i < 4; $i++) {
    $ts = strtotime("-$i month");
    $historyMonths[] = array(
        'year' => (int)date('Y', $ts),
        'month' => (int)date('n', $ts),
        'label' => date('M Y', $ts)
    );
}

// Fetch totals for last 4 months for all employees
$historyTotals = array();
if (count($historyMonths) > 0) {
    $conds = array();
    foreach ($historyMonths as $hm) {
        $conds[] = "(perf_year='{$hm['year']}' AND perf_month='{$hm['month']}')";
    }
    $histSql = "SELECT emp_id, perf_year, perf_month, total FROM emp_performance WHERE " . implode(' OR ', $conds);
    $histRes = mysqli_query($con, $histSql);
    if ($histRes && mysqli_num_rows($histRes) > 0) {
        while ($h = mysqli_fetch_assoc($histRes)) {
            $key = $h['perf_year'] . '-' . $h['perf_month'];
            $historyTotals[(int)$h['emp_id']][$key] = (int)$h['total'];
        }
    }
}

// Pre-compute absent-based default points (3 or fewer absences => 10 points, otherwise 0)
$absencePoints = array();
$attendanceTable = mysqli_query($con, "SHOW TABLES LIKE 'attendance'");
if ($attendanceTable && mysqli_num_rows($attendanceTable) > 0) {
    $absSql = "SELECT emp_id, SUM(CASE WHEN status='absent' THEN 1 ELSE 0 END) AS absences
               FROM attendance
               WHERE MONTH(attendance_date)='$currentMonth' AND YEAR(attendance_date)='$currentYear'
               GROUP BY emp_id";
    $absRes = mysqli_query($con, $absSql);
    if ($absRes && mysqli_num_rows($absRes) > 0) {
        while ($a = mysqli_fetch_assoc($absRes)) {
            $absences = (int)$a['absences'];
            $absencePoints[(int)$a['emp_id']] = ($absences <= 3) ? 10 : 0;
        }
    }
}

// Pre-compute late-based default points using check-in time after 10:15 AM
// Rules: up to 3 late => 10 points, 4-6 late => 5 points, more than 6 late => 0
$latePoints = array();
if ($attendanceTable && mysqli_num_rows($attendanceTable) > 0) {
    $lateSql = "SELECT emp_id, SUM(
                    CASE 
                        WHEN status='present' AND check_in_time IS NOT NULL AND check_in_time > '10:15:00' THEN 1
                        WHEN status='late' THEN 1
                        WHEN remarks LIKE '%late%' THEN 1
                        ELSE 0
                    END
                ) AS lates
                FROM attendance
                WHERE MONTH(attendance_date)='$currentMonth' AND YEAR(attendance_date)='$currentYear'
                GROUP BY emp_id";
    $lateRes = mysqli_query($con, $lateSql);
    if ($lateRes && mysqli_num_rows($lateRes) > 0) {
        while ($l = mysqli_fetch_assoc($lateRes)) {
            $lateCount = (int)$l['lates'];
            $points = 10;
            if ($lateCount >= 4 && $lateCount <= 6) $points = 5;
            if ($lateCount > 6) $points = 0;
            $latePoints[(int)$l['emp_id']] = $points;
        }
    }
}

// Fetch average daily performance from attendance table and convert to 35-point scale
$avgDailyPerformance = array();
if ($attendanceTable && mysqli_num_rows($attendanceTable) > 0) {
    $avgPerfSql = "SELECT emp_id, AVG(performance) as avg_perf, COUNT(performance) as perf_count 
                   FROM attendance 
                   WHERE MONTH(attendance_date)='$currentMonth' AND YEAR(attendance_date)='$currentYear' 
                   AND performance IS NOT NULL 
                   GROUP BY emp_id";
    $avgPerfRes = mysqli_query($con, $avgPerfSql);
    if ($avgPerfRes && mysqli_num_rows($avgPerfRes) > 0) {
        while ($ap = mysqli_fetch_assoc($avgPerfRes)) {
            $empId = (int)$ap['emp_id'];
            $avgVal = (float)$ap['avg_perf'];
            // Convert from 0-100 scale to 0-35 scale
            $convertedScore = round(($avgVal / 100) * 35, 2);
            $avgDailyPerformance[$empId] = array(
                'average' => $avgVal,
                'converted' => $convertedScore,
                'count' => (int)$ap['perf_count']
            );
        }
    }
}

/**
 * @param int $m
 * @return string
 */
function monthName($m)
{
    return date('F', mktime(0, 0, 0, (int)$m, 10));
}

/**
 * File Upload Function (Auto Remove PDF Password)
 * @param array $fileArray
 * @param string $targetDir
 * @return string|bool
 */
function handleFileUpload($fileArray, $targetDir = "../../uploads/")
{

    if (isset($fileArray) && $fileArray['error'] == 0) {

        $file_name = $fileArray['name'];
        $tmp_name = $fileArray['tmp_name'];

        $ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

        $new_name = time() . '_' . rand(1000, 9999) . '.' . $ext;

        $target_path = $targetDir . $new_name;

        if (move_uploaded_file($tmp_name, $target_path)) {

            // Only for PDF
            if ($ext === "pdf") {

                $qpdf = "C:/Program Files/qpdf/qpdf 12.3.2/bin/qpdf.exe";

                $unlocked_file = $targetDir . "unlock_" . $new_name;

                $command = "\"$qpdf\" --decrypt \"$target_path\" \"$unlocked_file\" 2>&1";

                exec($command, $output, $return_var);

                if ($return_var === 0 && file_exists($unlocked_file)) {

                    unlink($target_path);
                    rename($unlocked_file, $target_path);
                } else {

                    error_log("QPDF ERROR: " . implode("\n", $output));
                }
            }

            return $new_name;
        }
    }

    return '';
}
// Month/year options for the performance modal
$monthLabels = array();
for ($m = 1; $m <= 12; $m++) {
    $monthLabels[$m] = monthName($m);
}
$yearOptions = array();
for ($y = $currentYear - 2; $y <= $currentYear + 1; $y++) {
    $yearOptions[] = $y;
}

// Check extra_leaves column in emp_list
$checkExtraCol = @mysqli_query($con, "SHOW COLUMNS FROM emp_list LIKE 'extra_leaves'");
if ($checkExtraCol && mysqli_num_rows($checkExtraCol) == 0) {
    @mysqli_query($con, "ALTER TABLE emp_list ADD COLUMN extra_leaves INT(11) DEFAULT 0");
}

// phone_number was typed as INT(11) which overflows for any real 10-digit mobile
// number (max signed int is 2147483647), silently clamping every number to that
// value. Widen it to VARCHAR so numbers are stored/read as entered.
$checkPhoneCol = @mysqli_query($con, "SHOW COLUMNS FROM emp_list LIKE 'phone_number'");
if ($checkPhoneCol && $phoneColInfo = mysqli_fetch_assoc($checkPhoneCol)) {
    if (stripos($phoneColInfo['Type'], 'int') !== false) {
        @mysqli_query($con, "ALTER TABLE emp_list MODIFY COLUMN phone_number VARCHAR(15) NOT NULL DEFAULT ''");
    }
}

// Default system allowed leaves sum from leave_types (Annual Leave Policy sum)
$defaultSystemAllowedLeaves = 0;
$lt_sum_q = @mysqli_query($con, "SELECT SUM(num_of_leave) as total FROM leave_types WHERE deleted_at IS NULL");
if ($lt_sum_q && $lt_sum_row = mysqli_fetch_assoc($lt_sum_q)) {
    if ($lt_sum_row['total'] !== null && $lt_sum_row['total'] !== '') {
        $defaultSystemAllowedLeaves = intval($lt_sum_row['total']);
    }
}

// Pre-calculate used leaves per employee
$empUsedLeavesMap = array();
$usedLeavesQuery = @mysqli_query($con, "SELECT emp_id, leave_from, leave_to FROM leave_applications WHERE status = 'approved'");
if ($usedLeavesQuery && mysqli_num_rows($usedLeavesQuery) > 0) {
    while ($ul = mysqli_fetch_assoc($usedLeavesQuery)) {
        $eId = (int)$ul['emp_id'];
        $from = strtotime($ul['leave_from']);
        $to = strtotime($ul['leave_to']);
        $days = 1;
        if ($from && $to && $to >= $from) {
            $days = round(($to - $from) / (60 * 60 * 24)) + 1;
        }
        if (!isset($empUsedLeavesMap[$eId])) {
            $empUsedLeavesMap[$eId] = 0;
        }
        $empUsedLeavesMap[$eId] += $days;
    }
}
?>

<style>
    .btn-leave-badge {
        background: #FFEAEB;
        color: #DD2127;
        border: 1px solid #FCA5A5;
        padding: 5px 14px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 700;
        cursor: pointer;
        transition: all 0.25s ease;
        display: inline-flex;
        align-items: center;
        box-shadow: 0 2px 4px rgba(221, 33, 39, 0.08);
        outline: none !important;
    }

    .btn-leave-badge:hover {
        background: #DD2127;
        color: #ffffff;
        border-color: #DD2127;
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(221, 33, 39, 0.25);
    }

    .leave-stat-card {
        background: #ffffff;
        border-radius: 16px;
        padding: 16px 20px;
        border: 1px solid #e2e8f0;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.03);
        display: flex;
        align-items: center;
        justify-content: space-between;
    }

    /* Prevent Flash of Unstyled Content (FOUC) / profile picture flash on page refresh */
    .modal:not(.in):not(.show) {
        display: none !important;
    }

    /* Document Maintenance Modal Styles (Premium) */
    .doc-modal-overlay {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(15, 23, 42, 0.4);
        backdrop-filter: blur(8px);
        z-index: 2000;
        justify-content: center;
        align-items: center;
        opacity: 0;
        transition: opacity 0.3s ease;
    }

    .doc-modal-overlay.active {
        opacity: 1;
    }

    .doc-modal-container {
        background: #fff;
        width: 90%;
        max-width: 600px;
        border-radius: 20px;
        box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
        overflow: hidden;
        transform: translateY(20px);
        transition: transform 0.3s ease;
    }

    .doc-modal-overlay.active .doc-modal-container {
        transform: translateY(0);
    }

    .doc-modal-header {
        position: relative;
        padding: 20px 25px;
        background: #ffeaeb;
        border-bottom: 1px solid #e2e8f0;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .doc-modal-title-group {
        display: flex;
        align-items: center;
        gap: 12px;
    }

    .doc-modal-title-group i {
        font-size: 20px;
        color: #dd2127;
    }

    .doc-modal-title-group h3 {
        margin: 0;
        font-size: 18px;
        font-weight: 700;
        color: #0f172a;
    }

    .doc-modal-body {
        padding: 25px;
        max-height: 70vh;
        overflow-y: auto;
    }

    .doc-modal-list-view {
        margin-bottom: 25px;
        display: flex;
        flex-direction: column;
        gap: 10px;
    }

    .doc-item {
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        padding: 12px 16px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        transition: all 0.2s;
    }

    .doc-item:hover {
        border-color: #cbd5e1;
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
    }

    .premium-notification {
        position: fixed;
        top: 20px;
        right: 20px;
        padding: 16px 24px;
        border-radius: 16px;
        display: flex;
        align-items: center;
        gap: 12px;
        z-index: 9999;
        box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
        backdrop-filter: blur(10px);
        border: 1px solid rgba(255, 255, 255, 0.2);
        color: white;
        font-weight: 600;
        transform: translateX(120%);
        transition: transform 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
    }

    .premium-notification.active {
        transform: translateX(0);
    }

    .notification-success {
        background: rgba(16, 185, 129, 0.92);
    }

    .notification-error {
        background: rgba(239, 68, 68, 0.92);
    }

    .doc-item a {
        color: #1e293b;
        text-decoration: none;
        font-weight: 600;
        font-size: 14px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        max-width: 250px;
    }

    .delete-doc {
        background: #fee2e2;
        color: #ef4444;
        border: none;
        padding: 6px 12px;
        border-radius: 8px;
        font-size: 11px;
        font-weight: 700;
        cursor: pointer;
        transition: all 0.2s;
    }

    .delete-doc:hover {
        background: #ef4444;
        color: #fff;
    }

    .doc-modal-upload-section {
        background: #ffeaeb;
        border-radius: 16px;
        padding: 15px;
        border: 1px dashed #dd2127;
    }

    .upload-section-header {
        display: flex;
        align-items: center;
        gap: 8px;
        margin-bottom: 12px;
        font-size: 13px;
        font-weight: 700;
        color: #dd2127;
    }

    .upload-controls {
        display: flex;
        gap: 10px;
    }

    .custom-file-input {
        flex: 1;
        position: relative;
    }

    .custom-file-input input {
        position: absolute;
        width: 0;
        height: 0;
        opacity: 0;
    }

    .custom-file-input label {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        background: #fff;
        border: 1px solid #e2e8f0;
        padding: 12px 12px;
        border-radius: 10px;
        cursor: pointer;
        font-size: 12px;
        color: #64748b;
        width: 100%;
        margin: 0;
    }

    .btn-upload {
        padding: 8px 16px;
        border-radius: 10px;
        font-weight: 700;
        font-size: 13px;
    }

    .no-docs {
        text-align: center;
        padding: 30px;
        color: #94a3b8;
    }

    .no-docs i {
        font-size: 30px;
        margin-bottom: 10px;
        opacity: 0.5;
    }

    .no-docs p {
        margin: 0;
        font-size: 13px;
    }

    /* Performance history modal */
    #performanceHistoryModal .modal-content {
        border-radius: 10px;
        border: 1px solid #e2e8f0;
        box-shadow: 0 16px 44px rgba(15, 23, 42, 0.16);
    }

    #performanceHistoryModal .modal-header {
        border-bottom: 1px solid #e2e8f0;
    }

    .history-chart-box {
        background: linear-gradient(180deg, #f8fafc, #eef2ff);
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        padding: 14px;
        box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.7);
    }

    .history-table-wrap {
        margin-top: 14px;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        padding: 12px;
        background: #ffffff;
        box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.6);
    }

    .history-table-title {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        font-weight: 600;
        color: #0f172a;
    }

    .history-total-pill {
        display: inline-block;
        background: #0ea5e9;
        color: #fff;
        padding: 4px 10px;
        border-radius: 999px;
        font-weight: 700;
        font-size: 12px;
        letter-spacing: 0.01em;
    }

    .history-breakdown-table thead th {
        background: #f8fafc;
        color: #475569;
        font-size: 12px;
        text-transform: uppercase;
        letter-spacing: 0.02em;
        border-bottom: 1px solid #e2e8f0;
    }

    .history-breakdown-table tbody td {
        vertical-align: middle;
        color: #0f172a;
    }

    .history-point-badge {
        display: inline-block;
        padding: 4px 9px;
        border-radius: 10px;
        font-weight: 700;
        font-size: 12px;
        background: #e2e8f0;
        color: #0f172a;
    }

    .history-empty-row {
        text-align: center;
        color: #94a3b8;
    }

    .score-btn {
        border-color: transparent;
    }

    .score-plain {
        background: #f8fafc;
        color: #0f172a;
        border-color: #e2e8f0;
    }

    .score-red {
        background: #ef4444;
        color: #fff;
        border-color: #dc2626;
    }

    .score-gray {
        background: #94a3b8;
        color: #0f172a;
        border-color: #94a3b8;
    }

    .score-amber {
        background: #f59e0b;
        color: #0f172a;
        border-color: #d97706;
    }

    .score-green {
        background: #22c55e;
        color: #fff;
        border-color: #16a34a;
    }

    /* Premium Delete Confirmation Modal */
    .premium-confirm-overlay {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(15, 23, 42, 0.4);
        backdrop-filter: blur(8px);
        z-index: 9999;
        justify-content: center;
        align-items: center;
        opacity: 0;
        transition: opacity 0.3s ease;
    }

    .premium-confirm-overlay.active {
        display: flex;
        opacity: 1;
    }

    .premium-confirm-modal {
        background: #fff;
        width: 100%;
        max-width: 400px;
        border-radius: 20px;
        box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
        overflow: hidden;
        transform: scale(0.9);
        transition: transform 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
        padding: 30px;
        text-align: center;
    }

    .premium-confirm-overlay.active .premium-confirm-modal {
        transform: scale(1);
    }

    .confirm-icon-box {
        width: 60px;
        height: 60px;
        background: #fee2e2;
        color: #ef4444;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 24px;
        margin: 0 auto 20px auto;
        animation: pulseDanger 2s infinite;
    }

    @keyframes pulseDanger {
        0% {
            box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.4);
        }

        70% {
            box-shadow: 0 0 0 15px rgba(239, 68, 68, 0);
        }

        100% {
            box-shadow: 0 0 0 0 rgba(239, 68, 68, 0);
        }
    }

    .premium-confirm-header h3 {
        margin: 0 0 10px 0;
        font-size: 20px;
        font-weight: 700;
        color: #0f172a;
    }

    .premium-confirm-header p {
        margin: 0 0 25px 0;
        font-size: 14px;
        color: #64748b;
        line-height: 1.5;
    }

    .premium-confirm-footer {
        display: flex;
        gap: 12px;
    }

    .confirm-btn-cancel {
        flex: 1;
        padding: 12px;
        border-radius: 12px;
        background: #f1f5f9;
        color: #64748b;
        border: none;
        font-weight: 700;
        font-size: 14px;
        cursor: pointer;
        transition: all 0.2s;
    }

    .confirm-btn-cancel:hover {
        background: #e2e8f0;
        color: #0f172a;
    }

    .confirm-btn-delete {
        flex: 1;
        padding: 12px;
        border-radius: 12px;
        background: #ef4444;
        color: #fff;
        border: none;
        font-weight: 700;
        font-size: 14px;
        cursor: pointer;
        transition: all 0.2s;
        box-shadow: 0 4px 6px -1px rgba(239, 68, 68, 0.2);
    }

    .confirm-btn-delete:hover {
        background: #dc2626;
        transform: translateY(-1px);
        box-shadow: 0 10px 15px -3px rgba(239, 68, 68, 0.3);
    }

    /* Comprehensive Form Styles */
    .form-section-title {
        background: #f8fafc;
        padding: 8px 12px;
        border-left: 4px solid #3b82f6;
        margin: 20px 0 15px 0;
        font-weight: 700;
        color: #1e293b;
        font-size: 15px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .form-section-title:first-child {
        margin-top: 0;
    }

    .modal-lg-custom {
        width: 90%;
        max-width: 1000px;
    }

    .grid-row {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 15px;
        margin-bottom: 15px;
    }

    .grid-col {
        display: flex;
        flex-direction: column;
    }

    .grid-col label {
        font-weight: 600;
        margin-bottom: 5px;
        color: #475569;
        font-size: 13px;
    }

    .table-input {
        width: 100%;
        border: 1px solid #e2e8f0;
        padding: 6px 10px;
        border-radius: 4px;
        font-size: 13px;
    }

    .dynamic-table {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 15px;
    }

    .dynamic-table th {
        background: #f1f5f9;
        color: #475569;
        font-weight: 600;
        font-size: 12px;
        padding: 8px;
        text-align: left;
        border: 1px solid #e2e8f0;
    }

    .dynamic-table td {
        padding: 5px;
        border: 1px solid #e2e8f0;
    }

    /* View Modal Specific Styles */
    .view-info-item {
        margin-bottom: 12px;
        border-bottom: 1px solid #f1f5f9;
        padding-bottom: 8px;
    }

    .view-info-label {
        font-weight: 700;
        color: #64748b;
        font-size: 11px;
        text-transform: uppercase;
        display: block;
        margin-bottom: 2px;
    }

    .view-info-value {
        color: #1e293b;
        font-size: 14px;
        font-weight: 500;
    }

    .view-image-large {
        width: 120px;
        height: 120px;
        border-radius: 20px;
        object-fit: cover;
        object-position: center 10%;
        /* Ensures face focus in sidebar */
        border: 3px solid #fff;
        box-shadow: 0 8px 16px rgba(0, 0, 0, 0.1);
        margin-bottom: 15px;
        transition: transform 0.3s;
    }

    .view-modal-header {
        background: #f8fafc;
        border-bottom: 1px solid #e2e8f0;
        padding: 15px 20px;
    }

    .table-view-btn {
        padding: 4px 8px;
        font-size: 11px;
        border-radius: 4px;
        background: #f1f5f9;
        color: #475569;
        border: 1px solid #e2e8f0;
        transition: all 0.2s;
    }

    .table-view-btn:hover {
        background: #e2e8f0;
        color: #0f172a;
    }

    .emp-table-img {
        width: 48px;
        height: 48px;
        border-radius: 50%;
        object-fit: cover;
        object-position: center 10%;
        /* Focus on the face (top portion) */
        border: 2px solid #fff;
        cursor: pointer;
        transition: transform 0.25s ease, border-color 0.25s ease, box-shadow 0.25s ease;
        box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        outline: none !important;
        -webkit-tap-highlight-color: transparent;
    }

    .emp-table-img:hover {
        transform: scale(1.15) rotate(5deg);
        border-color: #dd2127;
        box-shadow: 0 10px 15px -3px rgba(221, 33, 39, 0.4);
    }

    .emp-table-img:focus,
    .emp-table-img:focus-visible,
    .emp-table-img:active {
        outline: none !important;
        border-color: #e2e8f0;
        box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
    }

    /* Round Profile Modal & Preview Styles */
    .view-image-round {
        width: 320px;
        height: 320px;
        border-radius: 50%;
        object-fit: cover;
        object-position: center 10%;
        /* Ensures the face is centered in the circle */
        border: 8px solid rgba(255, 255, 255, 0.3);
        box-shadow: 0 0 50px rgba(0, 0, 0, 0.5);
        background: #f8fafc;
        padding: 5px;
    }

    .preview-circle-container {
        display: flex;
        align-items: center;
        gap: 20px;
        margin-top: 10px;
        padding: 10px;
        background: #f8fafc;
        border-radius: 12px;
        border: 1px dashed #e2e8f0;
    }

    .image-preview-circle {
        width: 70px;
        height: 80px;
        border-radius: 50%;
        object-fit: cover;
        object-position: center 10%;
        /* Face-first preview */
        border: 3px solid #fff;
        box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
        background: #eef2ff;
    }

    /* Professional Profile Modal Styles */
    .profile-modal-body {
        display: flex;
        padding: 0 !important;
        background: #f8fafc;
        min-height: 500px;
    }

    .profile-sidebar {
        width: 280px;
        background: #ffffff;
        border-right: 1px solid #e2e8f0;
        display: flex;
        flex-direction: column;
        padding: 30px 0;
    }

    .profile-sidebar-header {
        padding: 0 25px 25px 25px;
        text-align: center;
        border-bottom: 1px solid #f1f5f9;
        margin-bottom: 15px;
    }

    .profile-nav {
        display: flex;
        flex-direction: column;
        gap: 2px;
    }

    .profile-nav-item {
        padding: 12px 25px;
        display: flex;
        align-items: center;
        gap: 12px;
        color: #64748b;
        font-weight: 600;
        font-size: 14px;
        cursor: pointer;
        transition: all 0.2s;
        border-right: 3px solid transparent;
    }

    .profile-nav-item i {
        width: 20px;
        font-size: 16px;
    }

    .profile-nav-item:hover {
        background: #FFEAEB;
        color: #dd2127;
    }

    .profile-nav-item.active {
        background: #FFEAEB;
        color: #dd2127;
        border-right-color: #dd2127;
    }

    .profile-content {
        flex: 1;
        padding: 40px;
        background: #ffffff;
        overflow-y: auto;
    }

    .profile-section-title {
        font-size: 20px;
        font-weight: 700;
        color: #0f172a;
        margin-bottom: 25px;
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .profile-data-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 25px;
    }

    .profile-data-card {
        background: #f8fafc;
        padding: 15px 20px;
        border-radius: 10px;
        border: 1px solid #f1f5f9;
    }

    .profile-data-label {
        font-size: 11px;
        text-transform: uppercase;
        color: #94a3b8;
        font-weight: 700;
        letter-spacing: 0.5px;
        margin-bottom: 6px;
        display: block;
    }

    .profile-data-value {
        font-size: 15px;
        color: #1e293b;
        font-weight: 600;
    }

    .profile-section {
        display: none;
    }

    .profile-section.active {
        display: block;
        animation: fadeIn 0.3s ease-out;
    }

    @keyframes fadeIn {
        from {
            opacity: 0;
            transform: translateY(10px);
        }

        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    .close-profile-btn {
        position: absolute;
        top: 20px;
        right: 20px;
        background: #dd2127;
        border: none;
        width: 36px;
        height: 36px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        color: #fff;
        cursor: pointer;
        transition: all 0.2s;
        z-index: 100;
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
    }

    .close-profile-btn:hover {
        background: #e2e8f0;
        color: #0f172a;
        transform: rotate(90deg);
    }

    .profile-content {
        position: relative;
    }
</style>

<div class="page-header-premium">
    <h1></h1>
    <div class="header-actions-premium">
        <?php if (function_exists('canAdminAccess') && canAdminAccess('employee_insert')): ?>
            <a href="index.php?add_emp" class="btn-premium-add">
                <i class="fa fa-user-plus"></i> Add Employee
            </a>
        <?php endif; ?>
    </div>
</div>

<?php if ($perfMessage): ?>
    <div class="alert alert-success" style="border-radius: 12px; border: none; background: #ecfdf5; color: #065f46; margin-bottom: 25px; font-weight: 600;">
        <i class="fa fa-check-circle"></i> <?php echo $perfMessage; ?>
    </div>
<?php endif; ?>
<?php if ($perfError): ?>
    <div class="alert alert-danger" style="border-radius: 12px; border: none; background: #fef2f2; color: #991b1b; margin-bottom: 25px; font-weight: 600;">
        <i class="fa fa-exclamation-circle"></i> <?php echo $perfError; ?>
    </div>
<?php endif; ?>

<div class="premium-card">
    <div class="card-hdr">
        <i class="fa fa-users"></i>
        <h3>All Employees</h3>
    </div>

    <div class="table-premium" style="overflow-x: auto; width: 100%; -webkit-overflow-scrolling: touch;">
        <table class="table table-premium" style="min-width: 950px; width: 100%;">
            <thead>
                <tr>
                    <th class="text-center" style="width: 60px; text-align: center;">ID</th>
                    <th class="text-center" style="width: 80px; text-align: center;">Photo</th>
                    <th>Name</th>
                    <th class="text-center" style="text-align: center;">Designation</th>
                    <th class="text-center" style="text-align: center;">Department</th>
                    <th class="text-center" style="text-align: center;">Leaves</th>
                    <th class="text-center" style="text-align: center;">Details</th>
                    <!-- <th class="text-center" style="text-align: center;">Rating</th> -->
                    <th class="text-center" style="text-align: center;">Files</th>
                    <th class="text-center" style="text-align: center;">Manage</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $sql = "SELECT * FROM emp_list WHERE deleted_at IS NULL ORDER BY status ASC, id ASC";
                $res = mysqli_query($con, $sql);
                if ($res && mysqli_num_rows($res) > 0) {
                    while ($row = mysqli_fetch_assoc($res)) {
                        $pk = $row['id'];
                        $name = htmlspecialchars($row['name']);
                        $phone = htmlspecialchars($row['phone_number']);
                        $email = htmlspecialchars($row['email']);
                        $status = isset($row['status']) ? $row['status'] : 'Active';
                        $img = !empty($row['employee_image']) ? 'uploads/' . $row['employee_image'] : 'admin_images/default.png';

                        $perfRow = isset($performanceMap[$pk]) ? $performanceMap[$pk] : null;
                        $perfTotal = $perfRow ? (int)$perfRow['total'] : null;
                        $absentPrefill = $perfRow ? (int)$perfRow['absent'] : (isset($absencePoints[$pk]) ? $absencePoints[$pk] : 0);
                        $latePrefill = $perfRow ? (int)$perfRow['late'] : (isset($latePoints[$pk]) ? $latePoints[$pk] : 0);

                        // Build history payload
                        $histSeries = array();
                        foreach ($historyMonths as $hm) {
                            $k = $hm['year'] . '-' . $hm['month'];
                            $val = isset($historyTotals[$pk][$k]) ? $historyTotals[$pk][$k] : 0;
                            $histSeries[] = array('label' => $hm['label'], 'value' => $val);
                        }
                        $histJson = htmlspecialchars(json_encode($histSeries), ENT_QUOTES, 'UTF-8');

                        $breakdown = array(
                            array('label' => 'Absent (auto)', 'max' => 20, 'user' => $absentPrefill),
                            array('label' => 'Late (auto)', 'max' => 10, 'user' => $latePrefill),
                            array('label' => 'Task Sheet', 'max' => 10, 'user' => $perfRow ? (int)$perfRow['task_sheet'] : 0),
                            array('label' => 'Performance', 'max' => 35, 'user' => $perfRow ? (float)$perfRow['performance_score'] : 0),
                            array('label' => 'Dressing & Behaviour', 'max' => 10, 'user' => $perfRow ? (int)$perfRow['dressing_behaviour'] : 0),
                            array('label' => 'RND', 'max' => 15, 'user' => $perfRow ? (int)$perfRow['rnd'] : 0)
                        );
                        $calculatedTotal = 0;
                        foreach ($breakdown as $b) {
                            $calculatedTotal += isset($b['user']) ? (float)$b['user'] : 0;
                        }
                        $effectiveTotal = ($perfTotal !== null) ? $perfTotal : $calculatedTotal;
                        $breakdown[] = array('label' => 'Total', 'max' => 100, 'user' => $effectiveTotal);
                        $breakdownJson = htmlspecialchars(json_encode($breakdown), ENT_QUOTES, 'UTF-8');

                        $rowStyle = ($status === 'Inactive') ? 'background: #fef2f2; opacity: 0.85;' : '';
                ?>
                        <tr style="<?php echo $rowStyle; ?>">
                            <td class="text-center" style="font-weight: 700; color: #64748b; text-align: center;"><?php echo format_emp_id($pk); ?></td>
                            <td class="text-center" style="text-align: center;">
                                <div style="position: relative; display: inline-block;">
                                    <img src="<?php echo $img; ?>" class="emp-table-img <?php echo ($status === 'Inactive') ? 'grayscale-img' : ''; ?>" alt="Profile"
                                        onclick="viewImage('<?php echo $img; ?>', '<?php echo $name; ?>')"
                                        title="Click to zoom" style="<?php echo ($status === 'Inactive') ? 'filter: grayscale(100%); opacity: 0.7;' : ''; ?>">
                                </div>
                            </td>
                            <td>
                                <div style="font-weight: 700; color: #1e293b; font-size: 14px;">
                                    <?php echo $name; ?>
                                    <?php if ($status === 'Inactive'): ?>
                                        <span style="font-size: 9px; font-weight: 800; background: #fca5a5; color: #991b1b; padding: 2px 6px; border-radius: 4px; margin-left: 6px; vertical-align: middle;">INACTIVE</span>
                                    <?php endif; ?>
                                </div>
                                <div style="font-size: 11px; color: #64748b; margin-top: 4px;">
                                    <span style="display:inline-flex; align-items:center; gap:4px; margin-right:10px;"><i class="fa fa-phone" style="color:#333; opacity:0.7;"></i> <?php echo $phone; ?></span>
                                    <span style="display:inline-flex; align-items:center; gap:4px;"><i class="fa fa-envelope" style="color:#dd2127; opacity:0.8;"></i> <?php echo htmlspecialchars(!empty($row['company_email']) ? $row['company_email'] : $email); ?></span>
                                </div>
                                <div style="font-size: 11px; color: #94a3b8; margin-top: 2px;"><i class="fa fa-calendar-check-o"></i> Joined: <?php echo (!empty($row['join_date']) && $row['join_date'] !== '0000-00-00') ? date('d-m-Y', strtotime($row['join_date'])) : '-'; ?></div>
                            </td>
                            <td class="text-center" style="text-align: center;">
                                <?php if (!empty($row['designation']) && $row['designation'] !== 'Not Assigned'): ?>
                                    <span style="font-size: 12px; font-weight: 700; background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; padding: 4px 10px; border-radius: 6px; display: inline-block;"><?php echo htmlspecialchars($row['designation']); ?></span>
                                <?php else: ?>
                                    <span style="font-size: 12px; color: #94a3b8; font-weight: 600;">Not Assigned</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center" style="text-align: center;">
                                <?php if (!empty($row['department']) && $row['department'] !== 'Not Assigned'): ?>
                                    <span style="font-size: 12px; font-weight: 700; background: #f0fdf4; color: #15803d; border: 1px solid #bbf7d0; padding: 4px 10px; border-radius: 6px; display: inline-block;"><?php echo htmlspecialchars($row['department']); ?></span>
                                <?php else: ?>
                                    <span style="font-size: 12px; color: #94a3b8; font-weight: 600;">Not Assigned</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center" style="text-align: center; vertical-align: middle;">
                                <?php
                                $empExtra = intval($row['extra_leaves'] ?? 0);
                                $empAllowed = $defaultSystemAllowedLeaves + $empExtra;
                                $empUsed = isset($empUsedLeavesMap[$pk]) ? intval($empUsedLeavesMap[$pk]) : 0;
                                ?>
                                <button type="button" class="btn-leave-badge"
                                    onclick="openEmpLeaveModal(<?php echo $pk; ?>, '<?php echo addslashes($name); ?>')"
                                    title="Click to view & edit leave details for <?php echo addslashes($name); ?>">
                                    <i class="fa fa-calendar-check-o" style="margin-right: 5px;"></i>
                                    <span><?php echo $empUsed; ?> / <?php echo $empAllowed; ?></span>
                                </button>
                            </td>
                            <td class="text-center" style="text-align: center;">
                                <button type="button" class="btn" style="padding: 6px 12px; border-radius: 8px; font-weight: 600; background: #f1f5f9; color: #475569; border: 1.5px solid #e2e8f0; font-size: 12px;"
                                    data-emp='<?php echo htmlspecialchars(json_encode($row), ENT_QUOTES, 'UTF-8'); ?>'
                                    onclick="openViewEmployee(this, 'section_personal')">
                                    <i class="fa fa-user-circle-o"></i> View Profile
                                </button>
                            </td>
                            <!-- Rating column commented out
                            <td class="text-center" style="text-align: center;">
                                <?php
                                $scoreClass = 'score-plain';
                                if ($perfRow) {
                                    if ($perfTotal < 30) $scoreClass = 'score-red';
                                    elseif ($perfTotal <= 49) $scoreClass = 'score-gray';
                                    elseif ($perfTotal <= 69) $scoreClass = 'score-amber';
                                    else $scoreClass = 'score-green';
                                }
                                ?>
                                <button type="button" class="btn score-btn <?php echo $scoreClass; ?> <?php echo $perfRow ? '' : 'btn-default'; ?>"
                                    style="padding: 4px 10px; margin-bottom: 5px; border-radius: 20px; font-weight: 700; font-size: 11px;"
                                    data-history="<?php echo $histJson; ?>"
                                    data-breakdown="<?php echo $breakdownJson; ?>"
                                    data-total="<?php echo $effectiveTotal; ?>"
                                    data-empname="<?php echo $name; ?>"
                                    onclick="openPerfHistory(this)">
                                    <?php echo $perfRow ? ($perfTotal . ' / 100') : 'Not set'; ?>
                                </button><br>
                                <button class="btn" style="padding: 3px 10px; font-size: 10px; border-radius: 6px; background: #fffbeb; color: #b45309; border: 1px solid #fde68a; font-weight: 700;"
                                    data-emp="<?php echo $pk; ?>"
                                    data-name="<?php echo $name; ?>"
                                    data-absent="<?php echo $absentPrefill; ?>"
                                    data-late="<?php echo $latePrefill; ?>"
                                    data-task_sheet="<?php echo $perfRow ? (int)$perfRow['task_sheet'] : 0; ?>"
                                    data-performance_score="<?php echo $perfRow ? (float)$perfRow['performance_score'] : 0; ?>"
                                    data-dressing_behaviour="<?php echo $perfRow ? (int)$perfRow['dressing_behaviour'] : 0; ?>"
                                    data-rnd="<?php echo $perfRow ? (int)$perfRow['rnd'] : 0; ?>"
                                    data-avg_perf="<?php echo isset($avgDailyPerformance[$pk]) ? $avgDailyPerformance[$pk]['converted'] : 0; ?>"
                                    onclick="openPerformance(this)">
                                    <i class="fa fa-line-chart"></i> Set
                                </button>
                            </td>
                            -->
                            <td class="text-center" style="text-align: center;">
                                <?php if (function_exists('canAdminAccess') && canAdminAccess('employee_update')): ?>
                                    <a href="javascript:void(0)" onclick="openDocuments(<?php echo $pk; ?>)" class="btn" style="padding: 6px 14px; border-radius: 8px; background: #fff; border: 1.5px solid #e2e8f0; color: #64748b; font-weight: 600; font-size: 12px;" title="View Documents">
                                        <i class="fa fa-folder-open-o"></i> View
                                    </a>
                                <?php endif; ?>
                            </td>
                            <td class="text-center" style="text-align: center;">
                                <div style="display: flex; gap: 8px; justify-content: center;">
                                    <?php if (function_exists('canAdminAccess') && canAdminAccess('employee_update')): ?>
                                        <a href="index.php?edit_emp=<?php echo $pk; ?>" class="btn-icon-premium btn-icon-edit" title="Edit Employee">
                                            <i class="fa fa-pencil"></i>
                                        </a>
                                    <?php endif; ?>
                                    <?php if (function_exists('canAdminAccess') && canAdminAccess('employee_delete')): ?>
                                        <button onclick="showDeleteConfirm(<?php echo $pk; ?>, '<?php echo addslashes($name); ?>')" type="button" class="btn-icon-premium btn-icon-delete" title="Delete Record">
                                            <i class="fa fa-trash-o"></i>
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php
                    }
                } else {
                    ?>
                    <tr>
                        <td colspan="8" style="padding: 100px 20px; text-align: center;">
                            <div style="width: 60px; height: 60px; background: #f1f5f9; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 15px;">
                                <i class="fa fa-users" style="font-size: 24px; color: #94a3b8;"></i>
                            </div>
                            <h3 style="color: #64748b; font-weight: 600; font-size: 16px;">No employees found.</h3>
                            <p style="color: #64748b; font-size: 13px;">There are no employees to show right now.</p>
                        </td>
                    </tr>
                <?php } ?>
            </tbody>
        </table>
    </div>
</div>


<!-- Document Management Modal (Premium UI) -->
<div class="doc-modal-overlay" id="docModalOverlay" aria-hidden="true" onclick="handleOverlayClick(event)" style="display: none;">
    <div class="doc-modal-container" role="dialog" aria-modal="true" aria-labelledby="doc-modal-title">
        <div class="doc-modal-header">
            <button class="btn-modal-close" onclick="closeDocModal()" aria-label="Close">
                <i class="fa fa-times"></i>
            </button>
            <div class="doc-modal-title-group">
                <i class="fa fa-folder-open text-primary"></i>
                <h3 id="doc-modal-title">Employee Documents</h3>
            </div>
        </div>

        <div class="doc-modal-body">
            <div id="doc-modal-list" class="doc-modal-list-view">
                <div class="text-center p-5"><i class="fa fa-spinner fa-spin"></i> Loading...</div>
            </div>

            <div class="doc-modal-upload-section">
                <div class="upload-section-header">
                    <i class="fa fa-cloud-upload"></i>
                    <span>Upload New Documents</span>
                </div>
                <form id="docUploadForm" enctype="multipart/form-data">
                    <input type="hidden" name="emp_id" id="doc_emp_id">
                    <div class="upload-controls">
                        <div class="custom-file-input">
                            <input type="file" name="documents[]" multiple required id="docFiles">
                            <label for="docFiles">
                                <i class="fa fa-files-o"></i> <span>Choose files...</span>
                            </label>
                        </div>
                        <button type="submit" class="btn-premium-add">
                            <i class="fa fa-check"></i> Upload
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Performance Modal -->
<div class="modal fade" id="performanceModal" tabindex="-1" role="dialog" aria-labelledby="performanceModalLabel" style="display: none;">
    <div class="modal-dialog" role="document" style="max-width: 650px; width: 100%;">
        <div class="modal-content premium-modal-content" style="border-radius: 16px; border: none; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1); overflow: hidden;">
            <form method="post" id="performanceForm">
                <div class="modal-header" style="background: #ffeaeb; padding: 20px 26px; border-bottom: 1px solid #f1f5f9; display: flex; align-items: flex-start;">
                    <div>
                        <h4 class="modal-title" id="performanceModalLabel" style="margin: 0; font-size: 18px; font-weight: 700; color: #0f172a;">
                            <i class="fa fa-line-chart" style="color: #DD2127; margin-right: 8px;"></i> Monthly Performance
                        </h4>
                        <p style="margin: 6px 0 0 0; color: #64748b; font-size: 12px; font-weight: 500;">Set the monthly score for <strong id="perfEmpName" style="color: #0f172a;"></strong> (<span id="perfMonthYearLabel"><?php echo monthName($currentMonth) . ' ' . $currentYear; ?></span>).</p>
                    </div>
                </div>
                <div class="modal-body" style="padding: 30px; background: #ffffff;">
                    <style>
                        .perf-input {
                            height: 48px;
                            border-radius: 10px;
                            background: #f8fafc;
                            border: 1.5px solid #e2e8f0;
                            font-size: 14px;
                            font-weight: 500;
                            color: #0f172a;
                            transition: all 0.2s ease;
                            padding: 0 16px;
                            width: 100%;
                            box-shadow: none;
                            outline: none;
                        }

                        .perf-input:focus {
                            border-color: #4f46e5;
                            background: #ffffff;
                            box-shadow: 0 0 0 4px rgba(79, 70, 229, 0.1);
                        }

                        .perf-input[readonly] {
                            background: #f1f5f9;
                            color: #64748b;
                            border-color: #e2e8f0;
                            cursor: not-allowed;
                        }

                        .perf-label {
                            font-weight: 600;
                            color: #475569;
                            font-size: 13px;
                            margin-bottom: 8px;
                            display: block;
                        }

                        .perf-hint {
                            color: #94a3b8;
                            font-size: 11px;
                            margin-top: 6px;
                            display: block;
                            font-weight: 500;
                        }
                    </style>

                    <div class="row" style="margin-bottom: 24px;">
                        <div class="col-sm-6">
                            <label class="perf-label">Month</label>
                            <select name="perf_month" id="perf_month" class="perf-input">
                                <?php foreach ($monthLabels as $mVal => $mLabel): ?>
                                    <option value="<?php echo $mVal; ?>" <?php echo ($mVal === $currentMonth) ? 'selected' : ''; ?>>
                                        <?php echo $mLabel; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-sm-6">
                            <label class="perf-label">Year</label>
                            <select name="perf_year" id="perf_year" class="perf-input">
                                <?php foreach ($yearOptions as $yr): ?>
                                    <option value="<?php echo $yr; ?>" <?php echo ($yr === $currentYear) ? 'selected' : ''; ?>>
                                        <?php echo $yr; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="row" style="margin-bottom: 24px;">
                        <div class="col-sm-6">
                            <label class="perf-label">Absent (auto)</label>
                            <input type="number" name="absent" id="perf_absent" class="perf-input" min="0" max="20" value="0" readonly>
                            <span class="perf-hint"><i class="fa fa-info-circle"></i> Auto from monthly absences</span>
                        </div>
                        <div class="col-sm-6">
                            <label class="perf-label">Late (auto)</label>
                            <input type="number" name="late" id="perf_late" class="perf-input" min="0" max="10" value="0" readonly>
                            <span class="perf-hint"><i class="fa fa-info-circle"></i> Auto from late check-ins</span>
                        </div>
                    </div>

                    <div class="row" style="margin-bottom: 24px;">
                        <div class="col-sm-6">
                            <label class="perf-label">Task Sheet (Max 10) <span style="color: #ef4444;">*</span></label>
                            <input type="number" name="task_sheet" id="perf_task" class="perf-input" min="0" max="10" value="0" required>
                        </div>
                        <div class="col-sm-6">
                            <label class="perf-label">Performance (Max 35)</label>
                            <input type="number" name="performance_score" id="perf_core" class="perf-input" min="0" max="35" value="0" readonly>
                            <span class="perf-hint"><i class="fa fa-info-circle"></i> Auto from daily average</span>
                        </div>
                    </div>

                    <div class="row" style="margin-bottom: 24px;">
                        <div class="col-sm-6">
                            <label class="perf-label">Dressing & Behaviour (Max 10) <span style="color: #ef4444;">*</span></label>
                            <input type="number" name="dressing_behaviour" id="perf_dress" class="perf-input" min="0" max="10" value="0" required>
                        </div>
                        <div class="col-sm-6">
                            <label class="perf-label">RND (Max 15) <span style="color: #ef4444;">*</span></label>
                            <input type="number" name="rnd" id="perf_rnd" class="perf-input" min="0" max="15" value="0" required>
                        </div>
                    </div>

                    <div id="perfTotalBox" style="background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%); border: 1.5px solid #e2e8f0; border-radius: 12px; padding: 20px; display: flex; align-items: center; justify-content: space-between;">
                        <div style="display: flex; align-items: center; gap: 15px;">
                            <div style="width: 50px; height: 50px; border-radius: 50%; background: #ffffff; display: flex; align-items: center; justify-content: center; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);">
                                <i class="fa fa-trophy" style="color: #eab308; font-size: 22px;"></i>
                            </div>
                            <div>
                                <span style="display: block; font-size: 12px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px;">Total Score</span>
                                <span style="display: block; font-size: 13px; color: #94a3b8; margin-top: 2px;">Maximum: 100 points</span>
                            </div>
                        </div>
                        <div style="text-align: right;">
                            <span style="font-size: 32px; font-weight: 800; color: #0f172a;" id="perfTotalValue">0</span>
                            <span style="font-size: 16px; font-weight: 600; color: #94a3b8; margin-left: 4px;">/ 100</span>
                        </div>
                    </div>

                    <input type="hidden" name="emp_id" id="perf_emp_id" value="">
                    <input type="hidden" name="emp_name" id="perf_emp_name_field" value="">
                    <input type="hidden" id="perf_avg_performance" value="0">
                    <input type="hidden" name="save_performance" value="1">
                </div>
                <div class="modal-footer" style="padding: 20px 30px; background: #f8fafc; border-top: 1px solid #f1f5f9; display: flex; justify-content: flex-end; gap: 12px;">
                    <button type="button" class="btn-premium-cancel" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn-premium-add">
                        <i class="fa fa-check"></i> Save Performance
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Performance History Modal -->
<div class="modal fade" id="performanceHistoryModal" tabindex="-1" role="dialog" aria-labelledby="performanceHistoryLabel" style="display: none;">
    <div class="modal-dialog" role="document" style="max-width: 650px; width: 100%;">
        <div class="modal-content premium-modal-content" style="border-radius: 16px; border: none; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1); overflow: hidden;">
            <div class="modal-header" style="background: #ffeaeb; padding: 19px 30px; border-bottom: 1px solid #f1f5f9; display: flex; align-items: flex-start;">
                <div>
                    <h4 class="modal-title" id="performanceHistoryLabel" style="margin: 0; font-size: 18px; font-weight: 700; color: #0f172a;">
                        <i class="fa fa-area-chart" style="color: #dd2127; margin-right: 8px;"></i> Performance History
                    </h4>
                    <p style="margin: 6px 0 0 0; color: #64748b; font-size: 12px; font-weight: 500;">Last 4 months performance for <strong id="historyEmpName" style="color: #0f172a;"></strong>.</p>
                </div>
            </div>
            <div class="modal-body" style="padding: 30px; background: #ffffff;">
                <div class="history-chart-box" style="background: #f8fafc; border: 1.5px solid #e2e8f0; border-radius: 12px; padding: 15px; margin-bottom: 24px;">
                    <div id="historyChart" style="width:100%; height:200px;"></div>
                </div>

                <div class="history-table-wrap" style="background: #ffffff; border: 1.5px solid #e2e8f0; border-radius: 12px; overflow: hidden;">
                    <div class="history-table-title" style="padding: 16px 20px; background: #f8fafc; border-bottom: 1.5px solid #e2e8f0; display: flex; align-items: center; justify-content: space-between;">
                        <span style="font-weight: 700; color: #0f172a; font-size: 14px;"><i class="fa fa-list-ul" style="color: #dd2127; margin-right: 6px;"></i> Breakdown</span>
                        <span id="historyBreakdownTotal" class="history-total-pill" style="background: #dd2127; color: #ffffff; padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 600;">0 / 100</span>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover history-breakdown-table" style="margin-bottom: 0;">
                            <thead style="background: #ffffff;">
                                <tr>
                                    <th style="padding: 12px 20px; border-bottom: 1.5px solid #e2e8f0; font-size: 11px; text-transform: uppercase; color: #64748b; font-weight: 600; width: 45%;">Type</th>
                                    <th style="padding: 12px 20px; border-bottom: 1.5px solid #e2e8f0; font-size: 11px; text-transform: uppercase; color: #64748b; font-weight: 600; width: 25%; text-align: center;">Max Points</th>
                                    <th style="padding: 12px 20px; border-bottom: 1.5px solid #e2e8f0; font-size: 11px; text-transform: uppercase; color: #64748b; font-weight: 600; width: 30%; text-align: center;">User Points</th>
                                </tr>
                            </thead>
                            <tbody id="historyBreakdown"></tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div class="modal-footer" style="padding: 20px 30px; background: #f8fafc; border-top: 1px solid #f1f5f9; display: flex; justify-content: flex-end;">
                <button type="button" class="btn-premium-cancel" data-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- View Employee Modal -->
<div class="modal fade" id="viewEmployeeModal" tabindex="-1" role="dialog" aria-labelledby="viewEmployeeModalLabel" style="display: none;">
    <div class="modal-dialog modal-lg" role="document" style="width: 90%; max-width: 1100px;">
        <div class="modal-content" style="border-radius: 16px; overflow: hidden; border: none; box-shadow: 0 25px 70px rgba(0,0,0,0.3);">
            <div class="profile-modal-body">
                <!-- Sidebar Navigation -->
                <div class="profile-sidebar">
                    <div class="profile-sidebar-header">
                        <img id="view_img" src="admin_images/default.png" class="view-image-large" style="width: 140px; height: 140px; border-radius: 20px; margin-bottom: 20px;" alt="Profile">
                        <h4 id="view_name" style="font-weight: 800; color: #0f172a; margin: 0 0 5px 0;">Employee Name</h4>
                        <div id="view_desig_sidebar" style="font-size: 13px; font-weight: 700; color: #dd2127; margin-bottom: 2px;">-</div>
                        <div id="view_dept_sidebar" style="font-size: 11px; font-weight: 600; color: #64748b; margin-bottom: 10px;">-</div>
                        <p id="view_id_label" style="color: #64748b; font-size: 13px; font-weight: 600; margin-bottom: 10px;">ID: 001</p>
                        <span id="view_gender_badge" class="label label-primary" style="background: #dd2127; padding: 5px 12px; border-radius: 30px; font-size: 11px;">Male</span>
                        <div id="view_join_sidebar" style="font-size: 11px; color: #64748b; font-weight: 600; margin-top: 10px;">Joined: -</div>
                    </div>

                    <div class="profile-nav">
                        <div class="profile-nav-item active" data-target="section_personal" onclick="switchProfileTab(this)">
                            <i class="fa fa-user"></i> Personal Information
                        </div>
                        <div class="profile-nav-item" data-target="section_documents" onclick="switchProfileTab(this)">
                            <i class="fa fa-file"></i> Documents
                        </div>
                        <div class="profile-nav-item" data-target="section_emergency" onclick="switchProfileTab(this)">
                            <i class="fa fa-ambulance"></i> Emergency Contact
                        </div>
                        <div class="profile-nav-item" data-target="section_education" onclick="switchProfileTab(this)">
                            <i class="fa fa-graduation-cap"></i> Educational Background
                        </div>
                        <div class="profile-nav-item" data-target="section_history" onclick="switchProfileTab(this)">
                            <i class="fa fa-briefcase"></i> Employment History
                        </div>
                        <div class="profile-nav-item" data-target="section_bank" onclick="switchProfileTab(this)">
                            <i class="fa fa-bank"></i> Bank Details
                        </div>
                        <?php if (canAdminAccess('salary_view')): ?>
                            <div class="profile-nav-item" data-target="section_salary" onclick="switchProfileTab(this)">
                                <i class="fa fa-money"></i> Professional & Salary
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- <div style="margin-top: auto; padding: 20px 25px;">
                        <button type="button" class="btn btn-default btn-block" data-dismiss="modal" style="border-radius: 8px; font-weight: 600; color: #64748b;">
                            <i class="fa fa-times"></i> Close Profile
                        </button>
                    </div> -->
                </div>

                <!-- Main Content Area -->
                <div class="profile-content">
                    <button type="button" class="close-profile-btn" data-dismiss="modal" aria-label="Close">
                        <i class="fa fa-times"></i>
                    </button>
                    <!-- Personal Info Section -->
                    <div id="section_personal" class="profile-section active">
                        <h3 class="profile-section-title"><i class="fa fa-user" style="color: #dd2127;"></i> Personal Information</h3>
                        <div class="profile-data-grid">
                            <div class="profile-data-card">
                                <span class="profile-data-label">Department</span>
                                <span class="profile-data-value" id="view_dept_personal">-</span>
                            </div>
                            <div class="profile-data-card">
                                <span class="profile-data-label">Designation</span>
                                <span class="profile-data-value" id="view_desig_personal">-</span>
                            </div>
                            <div class="profile-data-card">
                                <span class="profile-data-label">Date of Birth</span>
                                <span class="profile-data-value" id="view_dob">-</span>
                            </div>
                            <div class="profile-data-card">
                                <span class="profile-data-label">Age</span>
                                <span class="profile-data-value" id="view_age">-</span>
                            </div>
                            <div class="profile-data-card">
                                <span class="profile-data-label">Marital Status</span>
                                <span class="profile-data-value" id="view_marital">-</span>
                            </div>
                            <div class="profile-data-card">
                                <span class="profile-data-label">Dependents</span>
                                <span class="profile-data-value" id="view_dependents">0</span>
                            </div>
                            <div class="profile-data-card">
                                <span class="profile-data-label">Phone Number</span>
                                <span class="profile-data-value" id="view_phone">-</span>
                            </div>
                            <div class="profile-data-card">
                                <span class="profile-data-label">Company Email (Login)</span>
                                <span class="profile-data-value" id="view_company_email" style="color: #dd2127; font-weight: 700;">-</span>
                            </div>
                            <div class="profile-data-card">
                                <span class="profile-data-label">Personal Email Address</span>
                                <span class="profile-data-value" id="view_email">-</span>
                            </div>
                            <div class="profile-data-card">
                                <span class="profile-data-label">Blood Group</span>
                                <span class="profile-data-value" id="view_blood">-</span>
                            </div>
                            <div class="profile-data-card" style="grid-column: span 2;">
                                <span class="profile-data-label">Residential Address</span>
                                <span class="profile-data-value" id="view_address">-</span>
                            </div>
                        </div>
                    </div>

                    <!-- Documents Section -->
                    <div id="section_documents" class="profile-section">
                        <h3 class="profile-section-title"><i class="fa fa-file" style="color: #059669;"></i> Documents</h3>
                        <div class="profile-data-grid">
                            <div class="profile-data-card" style="grid-column: span 2;">
                                <span class="profile-data-label">Uploaded Documents</span>
                                <div id="view_documents" style="margin-top: 10px;">
                                    <p style="text-align: center; color: #999;">No documents found.</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Emergency Contact Section -->
                    <div id="section_emergency" class="profile-section">
                        <h3 class="profile-section-title"><i class="fa fa-ambulance" style="color: #ef4444;"></i> Emergency Contact</h3>
                        <div class="profile-data-grid">
                            <div class="profile-data-card">
                                <span class="profile-data-label">Contact Person Name</span>
                                <span class="profile-data-value" id="view_e_name">-</span>
                            </div>
                            <div class="profile-data-card">
                                <span class="profile-data-label">Relationship</span>
                                <span class="profile-data-value" id="view_e_rel">-</span>
                            </div>
                            <div class="profile-data-card">
                                <span class="profile-data-label">Contact Phone</span>
                                <span class="profile-data-value" id="view_e_phone">-</span>
                            </div>
                            <div class="profile-data-card" style="grid-column: span 2;">
                                <span class="profile-data-label">Contact Address</span>
                                <span class="profile-data-value" id="view_e_addr">-</span>
                            </div>
                        </div>
                    </div>

                    <!-- Education Section -->
                    <div id="section_education" class="profile-section">
                        <h3 class="profile-section-title"><i class="fa fa-graduation-cap" style="color: #dd2127;"></i> Educational Background</h3>
                        <div class="table-responsive" style="border: 1px solid #f1f5f9; border-radius: 12px; overflow: hidden;">
                            <table class="table table-hover" style="margin-bottom: 0;">
                                <thead style="background: #5b5b5b; color: #ffffff;">
                                    <tr>
                                        <th style="padding: 15px; border: none; font-size: 12px; text-transform: uppercase; text-align: center; background: #5b5b5b !important; color: #ffffff !important">Degree / Course</th>
                                        <th style="padding: 15px; border: none; font-size: 12px; text-transform: uppercase; text-align: center; background: #5b5b5b !important; color: #ffffff !important">University / Institute</th>
                                        <th style="padding: 15px; border: none; font-size: 12px; text-transform: uppercase; text-align: center; background: #5b5b5b !important; color: #ffffff !important">Year</th>
                                        <th style="padding: 15px; border: none; font-size: 12px; text-transform: uppercase; text-align: center; background: #5b5b5b !important; color: #ffffff !important">Grade</th>
                                    </tr>
                                </thead>
                                <tbody id="view_edu_list"></tbody>
                            </table>
                        </div>
                    </div>

                    <!-- History Section -->
                    <div id="section_history" class="profile-section">
                        <h3 class="profile-section-title"><i class="fa fa-briefcase" style="color: #dd2127;"></i> Employment History</h3>
                        <div class="table-responsive" style="border: 1px solid #f1f5f9; border-radius: 12px; overflow: hidden;">
                            <table class="table table-hover" style="margin-bottom: 0;">
                                <thead style="background: #5b5b5b; color: #ffffff;">
                                    <tr>
                                        <th style="padding: 15px; border: none; font-size: 12px; text-transform: uppercase; text-align: center; background: #5b5b5b !important; color: #ffffff !important">Company Name</th>
                                        <th style="padding: 15px; border: none; font-size: 12px; text-transform: uppercase; text-align: center; background: #5b5b5b !important; color: #ffffff !important">Position</th>
                                        <th style="padding: 15px; border: none; font-size: 12px; text-transform: uppercase; text-align: center; background: #5b5b5b !important; color: #ffffff !important">Year</th>
                                        <th style="padding: 15px; border: none; font-size: 12px; text-transform: uppercase; text-align: center; background: #5b5b5b !important; color: #ffffff !important">Reason for Leaving</th>
                                    </tr>
                                </thead>
                                <tbody id="view_hist_list"></tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Bank Details Section -->
                    <div id="section_bank" class="profile-section">
                        <h3 class="profile-section-title"><i class="fa fa-bank" style="color: #dd2127;"></i> Bank Details</h3>
                        <div class="profile-data-grid">
                            <div class="profile-data-card">
                                <span class="profile-data-label">Account Holder Name</span>
                                <span class="profile-data-value" id="view_acc_name">-</span>
                            </div>
                            <div class="profile-data-card">
                                <span class="profile-data-label">Bank & Branch</span>
                                <span class="profile-data-value" id="view_bank_br">-</span>
                            </div>
                            <div class="profile-data-card">
                                <span class="profile-data-label">Account Number</span>
                                <span class="profile-data-value" id="view_acc_num">-</span>
                            </div>
                            <div class="profile-data-card">
                                <span class="profile-data-label">IFSC Code / Type</span>
                                <span class="profile-data-value" id="view_acc_ifsc">-</span>
                            </div>
                        </div>
                    </div>

                    <!-- Professional Section -->
                    <?php if (canAdminAccess('salary_view')): ?>
                        <div id="section_salary" class="profile-section">
                            <h3 class="profile-section-title"><i class="fa fa-money" style="color: #059669;"></i> Professional & Salary Details</h3>
                            <div class="profile-data-grid">
                                <div class="profile-data-card" style="background: #ecfdf5; border-color: #d1fae5;">
                                    <span class="profile-data-label" style="color: #059669;">Net Monthly Salary</span>
                                    <span class="profile-data-value" id="view_salary" style="color: #047857; font-size: 20px;">₹ 0.00</span>
                                </div>
                                <div class="profile-data-card">
                                    <span class="profile-data-label">Joining Date</span>
                                    <span class="profile-data-value" id="view_join">-</span>
                                </div>
                                <div class="profile-data-card">
                                    <span class="profile-data-label">Department</span>
                                    <span class="profile-data-value" id="view_dept_salary">-</span>
                                </div>
                                <div class="profile-data-card">
                                    <span class="profile-data-label">Designation</span>
                                    <span class="profile-data-value" id="view_desig_salary">-</span>
                                </div>
                                <div class="profile-data-card">
                                    <span class="profile-data-label">Basic Salary</span>
                                    <span class="profile-data-value" id="view_basic">0.00</span>
                                </div>
                                <div class="profile-data-card">
                                    <span class="profile-data-label">HRA</span>
                                    <span class="profile-data-value" id="view_hra">0.00</span>
                                </div>
                                <div class="profile-data-card">
                                    <span class="profile-data-label">Allowance</span>
                                    <span class="profile-data-value" id="view_allowance">0.00</span>
                                </div>
                                <div class="profile-data-card">
                                    <span class="profile-data-label">Deductions</span>
                                    <span class="profile-data-value" id="view_deductions">0.00</span>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Image Viewer Modal -->
<div class="modal fade" id="imageViewerModal" tabindex="-1" role="dialog" style="display: none; background: rgba(15, 23, 42, 0.9);">
    <div class="modal-dialog" role="document" style="width: fit-content; max-width: 90vw; margin: 10vh auto;">
        <div class="modal-content" style="background: transparent; border: none; box-shadow: none;">
            <div class="modal-body text-center" style="padding: 0; position: relative;">
                <button type="button" class="close" data-dismiss="modal" style="position: absolute; right: -40px; top: -10px; color: white; opacity: 1; font-size: 35px; text-shadow: 0 0 10px rgba(0,0,0,0.5);">&times;</button>
                <img id="viewer_img" src="" class="view-image-round">
                <h3 id="viewer_name" style="color: white; margin-top: 25px; font-weight: 700; font-size: 24px; text-shadow: 0 2px 4px rgba(0,0,0,0.5);">Employee Name</h3>
            </div>
        </div>
    </div>
</div>

<!-- Employee Leave Modal -->
<div class="modal fade" id="empLeaveModal" tabindex="-1" role="dialog" aria-labelledby="empLeaveModalLabel" style="display: none;">
    <div class="modal-dialog modal-lg" role="document" style="width: 92%; max-width: 1050px;">
        <div class="modal-content" style="border-radius: 20px; border: none; box-shadow: 0 25px 70px rgba(0,0,0,0.3); overflow: hidden;">
            <div class="modal-header" style="background: #ffedeb; color: #1e293b; padding: 20px 28px; border: none; position: relative;">
                <button type="button" class="close-profile-btn" data-dismiss="modal" aria-label="Close" style="top: 18px; right: 20px;">
                    <i class="fa fa-times"></i>
                </button>
                <h4 class="modal-title" style="font-weight: 800; display: flex; align-items: center; gap: 12px; margin: 0; font-size: 18px;">
                    <div style="background: #DD2127; color: white; width: 36px; height: 36px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 16px;">
                        <i class="fa fa-calendar-check-o"></i>
                    </div>
                    <div>
                        <span id="leaveModalEmpName">Employee Name</span> - Leave Management
                        <div style="font-size: 12px; font-weight: 600; color: #64748b; margin-top: 2px;">View leave history, adjust allowed quota & manage leave applications</div>
                    </div>
                </h4>
            </div>

            <div class="modal-body" style="padding: 28px; background: #ffffff;">
                <!-- Summary Cards Row -->
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; margin-bottom: 25px;">
                    <!-- Used Leaves Card -->
                    <div class="leave-stat-card" style="border-left: 4.5px solid #ef4444; background: #fef2f2;">
                        <div>
                            <div style="font-size: 11px; font-weight: 800; color: #991b1b; text-transform: uppercase; letter-spacing: 0.5px;">Used Leaves</div>
                            <div style="font-size: 26px; font-weight: 900; color: #7f1d1d; margin-top: 4px;" id="modalUsedLeaves">0 Days</div>
                        </div>
                        <div style="width: 42px; height: 42px; border-radius: 12px; background: #fee2e2; color: #ef4444; display: flex; align-items: center; justify-content: center; font-size: 18px;">
                            <i class="fa fa-calendar-minus-o"></i>
                        </div>
                    </div>

                    <!-- Remaining Leaves Card -->
                    <div class="leave-stat-card" style="border-left: 4.5px solid #10b981; background: #ecfdf5;">
                        <div>
                            <div style="font-size: 11px; font-weight: 800; color: #065f46; text-transform: uppercase; letter-spacing: 0.5px;">Remaining Balance</div>
                            <div style="font-size: 26px; font-weight: 900; color: #047857; margin-top: 4px;" id="modalRemainingLeaves">0 Days</div>
                        </div>
                        <div style="width: 42px; height: 42px; border-radius: 12px; background: #d1fae5; color: #10b981; display: flex; align-items: center; justify-content: center; font-size: 18px;">
                            <i class="fa fa-calendar-check-o"></i>
                        </div>
                    </div>

                    <!-- Total Allowed Leaves Card -->
                    <div class="leave-stat-card" style="border-left: 4.5px solid #dd2127; background: #fff5f5;">
                        <div style="flex: 1;">
                            <div style="font-size: 11px; font-weight: 800; color: #991b1b; text-transform: uppercase; letter-spacing: 0.5px;">Total Allowed Leaves</div>
                            <div style="font-size: 26px; font-weight: 900; color: #991b1b; margin-top: 4px;" id="modalAllowedLeaves">0 Days</div>
                        </div>
                        <div style="width: 42px; height: 42px; border-radius: 12px; background: #ffe4e6; color: #dd2127; display: flex; align-items: center; justify-content: center; font-size: 18px;">
                            <i class="fa fa-calendar"></i>
                        </div>
                    </div>
                </div>

                <!-- Leave Policy Allowances & Extra Leaves Row -->
                <div id="modalLeaveTypeBreakdown" style="display: flex; flex-wrap: wrap; gap: 12px; margin-bottom: 22px; background: #f8fafc; padding: 14px 18px; border-radius: 14px; border: 1.5px solid #e2e8f0; align-items: center; justify-content: space-between;">
                    <div style="display: flex; flex-wrap: wrap; gap: 8px; align-items: center; flex: 1;">
                        <div style="font-size: 11px; font-weight: 800; color: #475569; text-transform: uppercase; letter-spacing: 0.5px; margin-right: 4px; display: flex; align-items: center; gap: 6px;">
                            <i class="fa fa-pie-chart" style="color: #dd2127; font-size: 14px;"></i> Policy Allowances:
                        </div>
                        <div id="modalLeaveTypeBadges" style="display: flex; flex-wrap: wrap; gap: 8px;"></div>
                    </div>
                    <div>
                        <button type="button" class="btn-premium-add" onclick="toggleExtraLeaveForm()">
                            <i class="fa fa-plus-circle"></i> Add Extra Leaves
                        </button>
                    </div>
                </div>

                <!-- Hidden Inline Form to Add / Edit Extra Leaves for this Employee -->
                <div id="extraLeaveFormBox" style="display: none; background: #fff5f5; border: 1.5px solid #fca5a5; border-radius: 16px; padding: 20px 24px; margin-bottom: 22px; box-shadow: 0 4px 15px rgba(221,33,39,0.05);">
                    <div style="display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap;">
                        <div>
                            <span style="font-weight: 800; color: #991b1b; font-size: 14px; display: flex; align-items: center; gap: 8px;">
                                <i class="fa fa-user-plus" style="color: #dd2127; font-size: 16px;"></i> Personal Extra Leaves for Employee
                            </span>
                            <div style="font-size: 11.5px; color: #7f1d1d; margin-top: 3px; font-weight: 500;">Add extra custom leave days for this specific employee on top of the annual policy allowance</div>
                        </div>
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <input type="number" id="inputExtraLeaves" min="0" max="365" placeholder="e.g. 3" class="form-control" style="height: 40px; font-size: 13px; font-weight: 700; width: 110px; border-radius: 10px; border: 1.5px solid #dd2127; background: #ffffff; text-align: center;">
                            <button type="button" class="btn-premium-add" onclick="saveExtraLeaves()">
                                Save Extra Leaves
                            </button>
                            <button type="button" class="btn-premium-cancel" onclick="toggleExtraLeaveForm()">
                                Cancel
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Section Header & Add Button -->
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 18px; border-bottom: 1.5px solid #f1f5f9; padding-bottom: 14px;">
                    <h4 style="font-weight: 800; color: #1e293b; margin: 0; font-size: 15px; display: flex; align-items: center; gap: 8px;">
                        <i class="fa fa-list-alt" style="color: #dd2127;"></i> Leave Applications & Record History
                    </h4>
                    <button type="button" class="btn-premium-add" onclick="toggleAddLeaveForm()">
                        <i class="fa fa-plus-circle"></i> Add Leave Entry
                    </button>
                </div>

                <!-- Admin Add Leave Entry Form -->
                <div id="addLeaveFormBox" style="display: none; background: #f8fafc; border: 1.5px solid #cbd5e1; border-radius: 16px; padding: 22px 24px; margin-bottom: 22px; box-shadow: 0 4px 15px rgba(0,0,0,0.03);">
                    <h5 style="font-weight: 800; color: #0f172a; margin-top: 0; margin-bottom: 18px; font-size: 14px; display: flex; align-items: center; gap: 8px;">
                        <i class="fa fa-plus-circle" style="color: #dd2127; font-size: 16px;"></i> Add Leave Entry For Employee
                    </h5>
                    <div class="row">
                        <div class="col-md-3 col-sm-6" style="margin-bottom: 14px;">
                            <label style="font-size: 10px; font-weight: 800; color: #475569; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 6px; display: block;">Leave Type</label>
                            <select id="add_leave_type_id" class="form-control" style="height: 40px; border-radius: 10px; font-weight: 600; font-size: 13px; border: 1.5px solid #cbd5e1; background: #ffffff; color: #1e293b;"></select>
                        </div>
                        <div class="col-md-3 col-sm-6" style="margin-bottom: 14px;">
                            <label style="font-size: 10px; font-weight: 800; color: #475569; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 6px; display: block;">From Date</label>
                            <input type="date" id="add_leave_from" class="form-control" style="height: 40px; border-radius: 10px; font-weight: 600; font-size: 13px; border: 1.5px solid #cbd5e1; background: #ffffff; color: #1e293b;">
                        </div>
                        <div class="col-md-3 col-sm-6" style="margin-bottom: 14px;">
                            <label style="font-size: 10px; font-weight: 800; color: #475569; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 6px; display: block;">To Date</label>
                            <input type="date" id="add_leave_to" class="form-control" style="height: 40px; border-radius: 10px; font-weight: 600; font-size: 13px; border: 1.5px solid #cbd5e1; background: #ffffff; color: #1e293b;">
                        </div>
                        <div class="col-md-3 col-sm-6" style="margin-bottom: 14px;">
                            <label style="font-size: 10px; font-weight: 800; color: #475569; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 6px; display: block;">Status</label>
                            <select id="add_leave_status" class="form-control" style="height: 40px; border-radius: 10px; font-weight: 600; font-size: 13px; border: 1.5px solid #cbd5e1; background: #ffffff; color: #1e293b;">
                                <option value="approved" selected>Approved</option>
                                <option value="pending">Pending</option>
                                <option value="rejected">Rejected</option>
                            </select>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-12" style="margin-bottom: 16px;">
                            <label style="font-size: 10px; font-weight: 800; color: #475569; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 6px; display: block;">Reason / Notes</label>
                            <input type="text" id="add_leave_reason" placeholder="e.g. Medical leave, Personal work" class="form-control" style="height: 40px; border-radius: 10px; font-weight: 600; font-size: 13px; border: 1.5px solid #cbd5e1; background: #ffffff; color: #1e293b;">
                        </div>
                    </div>
                    <div style="display: flex; justify-content: flex-end; gap: 10px; border-top: 1px solid #e2e8f0; padding-top: 16px; margin-top: 4px;">
                        <button type="button" class="btn-premium-cancel" onclick="toggleAddLeaveForm()">
                            <i class="fa fa-times"></i> Cancel
                        </button>
                        <button type="button" class="btn-premium-add" onclick="submitAdminEmpLeave()">
                            <i class="fa fa-check"></i> Save Leave
                        </button>
                    </div>
                </div>

                <!-- History Table -->
                <div class="table-responsive" style="border: 1.5px solid #e2e8f0; border-radius: 14px; overflow: hidden; background: #fff;">
                    <table class="table table-hover" style="margin-bottom: 0;">
                        <thead style="background: #1e293b; color: #ffffff;">
                            <tr>
                                <th style="padding: 12px 16px; border: none; font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px;">Type</th>
                                <th style="padding: 12px 16px; border: none; font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px;">Dates & Duration</th>
                                <th style="padding: 12px 16px; border: none; font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; text-align: center;">Days</th>
                                <th style="padding: 12px 16px; border: none; font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px;">Reason</th>
                                <th style="padding: 12px 16px; border: none; font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; text-align: center;">Status</th>
                            </tr>
                        </thead>
                        <tbody id="empLeaveHistoryBody">
                            <!-- Dynamic Content -->
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="modal-footer" style="padding: 18px 28px; background: #f8fafc; border-top: 1px solid #f1f5f9; display: flex; justify-content: flex-end;">
                <button type="button" class="btn-premium-cancel" data-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Premium Delete Confirmation Modal -->
<div class="premium-confirm-overlay" id="deleteConfirmOverlay" style="display: none;">
    <div class="premium-confirm-modal">
        <div class="premium-confirm-header">
            <div class="confirm-icon-box">
                <i class="fa fa-trash"></i>
            </div>
            <h3>Delete Record?</h3>
            <p>You are about to delete <strong id="delete_emp_name_label">this employee</strong>. This action is permanent and cannot be reversed.</p>
        </div>
        <div class="premium-confirm-footer">
            <button class="confirm-btn-cancel" onclick="closeDeleteConfirm()">Cancel</button>
            <button class="confirm-btn-delete" id="confirmDeleteBtn">Delete Now</button>
        </div>
    </div>
</div>

<div class="premium-confirm-overlay" id="docDeleteConfirmOverlay" style="display: none;">
    <div class="premium-confirm-modal">
        <div class="premium-confirm-header">
            <div class="confirm-icon-box">
                <i class="fa fa-file-text-o"></i>
            </div>
            <h3>Delete Document?</h3>
            <p>This file will be removed permanently from the employee record.</p>
        </div>
        <div class="premium-confirm-footer">
            <button class="confirm-btn-cancel" onclick="closeDocDeleteConfirm()" type="button">Cancel</button>
            <button class="confirm-btn-delete" id="confirmDocDeleteBtn" type="button">Delete Document</button>
        </div>
    </div>
</div>



<script>
    // Employee Leave Management JS
    let currentEmpLeaveId = 0;
    let currentEmpLeaveName = '';

    function openEmpLeaveModal(empId, empName) {
        currentEmpLeaveId = empId;
        currentEmpLeaveName = empName;

        $('#leaveModalEmpName').text(empName);
        $('#extraLeaveFormBox').hide();
        $('#addLeaveFormBox').hide();
        $('#empLeaveHistoryBody').html('<tr><td colspan="5" class="text-center" style="padding: 25px;"><i class="fa fa-spinner fa-spin fa-2x" style="color: #dd2127;"></i><br><span style="color: #64748b; font-weight: 600; font-size: 13px; margin-top: 8px; display: inline-block;">Loading leave details...</span></td></tr>');

        $('#empLeaveModal').modal('show');

        fetchLeaveDetails(empId);
    }

    function fetchLeaveDetails(empId) {
        $.ajax({
            url: 'ajax/leaves/ajax_get_emp_leave_details.php',
            method: 'GET',
            data: {
                emp_id: empId
            },
            dataType: 'json',
            success: function(res) {
                if (res.status === 'success') {
                    $('#modalUsedLeaves').text(res.emp.used_leaves + ' Days');
                    $('#modalRemainingLeaves').text(res.emp.remaining_leaves + ' Days');
                    $('#modalAllowedLeaves').text(res.emp.allowed_leaves + ' Days');
                    $('#inputExtraLeaves').val(res.emp.extra_leaves || 0);

                    // Populate Policy Allowance & Extra Leaves Badges
                    let breakdownHtml = '';
                    if (res.leave_types && res.leave_types.length > 0) {
                        res.leave_types.forEach(function(lt) {
                            let used = lt.used_leave || 0;
                            let total = lt.num_of_leave || 0;
                            breakdownHtml += `
                                <div style="background: #ffffff; border: 1px solid #cbd5e1; padding: 5px 12px; border-radius: 20px; font-size: 12px; font-weight: 700; color: #1e293b; display: inline-flex; align-items: center; gap: 6px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
                                    <span style="color: #dd2127;">${lt.leave_name}:</span>
                                    <span style="color: #0f172a;">${used} / ${total} Days</span>
                                </div>
                            `;
                        });
                    } else {
                        breakdownHtml = '<span style="font-size: 12px; color: #94a3b8;">No leave policy types configured.</span>';
                    }

                    if (res.emp.extra_leaves && res.emp.extra_leaves > 0) {
                        let extraUsed = res.emp.extra_leaves_used || 0;
                        breakdownHtml += `
                            <div style="background: #fff5f5; border: 1px solid #fca5a5; padding: 5px 12px; border-radius: 20px; font-size: 12px; font-weight: 700; color: #991b1b; display: inline-flex; align-items: center; gap: 6px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
                                <span style="color: #dd2127;"><i class="fa fa-star"></i> Extra Leaves:</span>
                                <span style="color: #7f1d1d;">${extraUsed} / ${res.emp.extra_leaves} Days</span>
                            </div>
                        `;
                    }
                    $('#modalLeaveTypeBadges').html(breakdownHtml);

                    // Populate Leave Types Dropdown for Add Form
                    let typeOptions = '<option value="">-- Select Leave Type --</option>';
                    if (res.leave_types && res.leave_types.length > 0) {
                        res.leave_types.forEach(function(lt) {
                            typeOptions += `<option value="${lt.id}">${lt.leave_name} (${lt.num_of_leave} days)</option>`;
                        });
                    }
                    if (res.emp.extra_leaves && res.emp.extra_leaves > 0) {
                        typeOptions += `<option value="0">Extra Leaves (${res.emp.extra_leaves} days)</option>`;
                    }
                    $('#add_leave_type_id').html(typeOptions);

                    // Render Applications History Table
                    let rows = '';
                    if (res.applications && res.applications.length > 0) {
                        res.applications.forEach(function(app) {
                            let badgeClass = 'label-warning';
                            let badgeBg = '#fffbeb';
                            let badgeColor = '#b45309';
                            let badgeBorder = '#fde68a';

                            if (app.status === 'approved') {
                                badgeBg = '#ecfdf5';
                                badgeColor = '#047857';
                                badgeBorder = '#a7f3d0';
                            } else if (app.status === 'rejected') {
                                badgeBg = '#fef2f2';
                                badgeColor = '#b91c1c';
                                badgeBorder = '#fca5a5';
                            }

                            rows += `
                                <tr>
                                    <td style="font-weight: 700; color: #1e293b;">
                                        <i class="fa fa-tag" style="color: #dd2127; margin-right: 6px;"></i> ${app.leave_name}
                                    </td>
                                    <td>
                                        <span style="font-weight: 600; color: #334155;">${app.leave_from}</span> 
                                        <i class="fa fa-arrow-right" style="font-size: 10px; color: #94a3b8; margin: 0 4px;"></i> 
                                        <span style="font-weight: 600; color: #334155;">${app.leave_to}</span>
                                    </td>
                                    <td style="text-align: center; font-weight: 800; color: #0f172a;">
                                        <span style="background: #f1f5f9; padding: 3px 8px; border-radius: 6px; border: 1px solid #e2e8f0;">${app.days} Day(s)</span>
                                    </td>
                                    <td style="color: #475569; font-size: 12px; max-width: 200px;">
                                        ${app.reason ? app.reason : '-'}
                                    </td>
                                    <td style="text-align: center;">
                                        <span style="font-size: 11px; font-weight: 800; text-transform: uppercase; background: ${badgeBg}; color: ${badgeColor}; border: 1px solid ${badgeBorder}; padding: 3px 10px; border-radius: 20px; display: inline-block;">
                                            ${app.status}
                                        </span>
                                    </td>
                                </tr>
                            `;
                        });
                    } else {
                        rows = '<tr><td colspan="5" class="text-center" style="padding: 20px; color: #94a3b8; font-weight: 600;">No leave records found for this employee.</td></tr>';
                    }
                    $('#empLeaveHistoryBody').html(rows);
                } else {
                    $('#empLeaveHistoryBody').html(`<tr><td colspan="5" class="text-center text-danger">${res.message}</td></tr>`);
                }
            },
            error: function() {
                $('#empLeaveHistoryBody').html('<tr><td colspan="5" class="text-center text-danger">Connection error. Could not fetch details.</td></tr>');
            }
        });
    }

    function toggleExtraLeaveForm() {
        $('#extraLeaveFormBox').slideToggle(200);
    }

    function saveExtraLeaves() {
        let val = parseInt($('#inputExtraLeaves').val());
        if (isNaN(val) || val < 0) val = 0;

        $.ajax({
            url: 'ajax/leaves/ajax_update_emp_leave_quota.php',
            method: 'POST',
            data: {
                emp_id: currentEmpLeaveId,
                extra_leaves: val
            },
            dataType: 'json',
            success: function(res) {
                if (res.status === 'success') {
                    if (typeof showPremiumAlert === 'function') showPremiumAlert('Extra leaves updated!');
                    $('#extraLeaveFormBox').slideUp(200);
                    fetchLeaveDetails(currentEmpLeaveId);
                } else {
                    alert('Error: ' + res.message);
                }
            }
        });
    }

    function toggleAddLeaveForm() {
        $('#addLeaveFormBox').slideToggle(200);
    }

    function submitAdminEmpLeave() {
        let typeId = $('#add_leave_type_id').val();
        let fromDate = $('#add_leave_from').val();
        let toDate = $('#add_leave_to').val();
        let reason = $('#add_leave_reason').val();
        let status = $('#add_leave_status').val();

        if (typeId === null || typeId === '' || typeId === undefined || !fromDate || !toDate) {
            if (typeof showPremiumAlert === 'function') showPremiumAlert('Please fill in Leave Type, From Date and To Date', 'error');
            return;
        }

        $.ajax({
            url: 'ajax/leaves/ajax_add_emp_leave.php',
            method: 'POST',
            data: {
                emp_id: currentEmpLeaveId,
                leave_type_id: typeId,
                leave_from: fromDate,
                leave_to: toDate,
                reason: reason,
                status: status
            },
            dataType: 'json',
            success: function(res) {
                if (res.status === 'success') {
                    if (typeof showPremiumAlert === 'function') showPremiumAlert('Leave record added!');
                    $('#add_leave_from').val('');
                    $('#add_leave_to').val('');
                    $('#add_leave_reason').val('');
                    $('#addLeaveFormBox').slideUp(200);
                    fetchLeaveDetails(currentEmpLeaveId);
                } else {
                    alert('Error: ' + res.message);
                }
            }
        });
    }

    function updateLeaveAppStatus(appId, newStatus) {
        $.ajax({
            url: 'ajax/leaves/ajax_update_leave_app_status.php',
            method: 'POST',
            data: {
                app_id: appId,
                status: newStatus
            },
            dataType: 'json',
            success: function(res) {
                if (res.status === 'success') {
                    if (typeof showPremiumAlert === 'function') showPremiumAlert('Leave status updated to ' + newStatus);
                    fetchLeaveDetails(currentEmpLeaveId);
                } else {
                    alert('Error: ' + res.message);
                }
            }
        });
    }

    function deleteLeaveApp(appId) {
        if (!confirm('Are you sure you want to delete this leave entry?')) return;

        $.ajax({
            url: 'ajax/leaves/ajax_delete_leave_app.php',
            method: 'POST',
            data: {
                app_id: appId
            },
            dataType: 'json',
            success: function(res) {
                if (res.status === 'success') {
                    if (typeof showPremiumAlert === 'function') showPremiumAlert('Leave entry deleted!');
                    fetchLeaveDetails(currentEmpLeaveId);
                } else {
                    alert('Error: ' + res.message);
                }
            }
        });
    }

    // Zoom/View Profile Image
    function viewImage(img, name) {
        var viewerImg = document.getElementById('viewer_img');
        var viewerName = document.getElementById('viewer_name');
        if (viewerImg && viewerName) {
            viewerImg.src = img;
            viewerName.textContent = name;
            $('#imageViewerModal').modal('show');
        }
    }

    // Document Modal Logic
    const docOverlay = document.getElementById('docModalOverlay');
    const docList = document.getElementById('doc-modal-list');
    const docEmpIdField = document.getElementById('doc_emp_id');

    function openDocuments(empId) {
        if (!docOverlay || !docList || !docEmpIdField) return;

        docEmpIdField.value = empId;
        docOverlay.style.display = 'flex';
        docOverlay.setAttribute('aria-hidden', 'false');

        // Add a slight delay for animation
        setTimeout(() => docOverlay.classList.add('active'), 10);

        fetch('pages/documents/fetch_documents.php?id=' + empId)
            .then(res => res.text())
            .then(data => {
                docList.innerHTML = data || '<div class="no-docs"><i class="fa fa-file-o"></i><p>No extra documents uploaded yet.</p></div>';
            })
            .catch(() => {
                docList.innerHTML = '<div class="alert alert-danger">Error loading documents.</div>';
            });
    }

    function closeDocModal() {
        if (!docOverlay) return;
        docOverlay.classList.remove('active');
        setTimeout(() => {
            docOverlay.style.display = 'none';
            docOverlay.setAttribute('aria-hidden', 'true');
            if (docList) docList.innerHTML = '';
        }, 300);
    }

    function handleOverlayClick(e) {
        if (e.target === docOverlay) {
            closeDocModal();
        }
    }

    // Close on ESC
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            if (docOverlay && docOverlay.classList.contains('active')) {
                closeDocModal();
            }
        }
    });

    function showPremiumAlert(message, type = 'success') {
        const toast = document.createElement('div');
        toast.className = `premium-notification notification-${type}`;
        const icon = type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle';
        toast.innerHTML = `<i class="fa ${icon}"></i> <span>${message}</span>`;
        document.body.appendChild(toast);
        setTimeout(() => toast.classList.add('active'), 10);
        setTimeout(() => {
            toast.classList.remove('active');
            setTimeout(() => toast.remove(), 400);
        }, 3500);
    }

    // Handle document deletion
    function deleteDocument(docId, empId) {
        currentDocDelete = {
            docId,
            empId
        };
        docDeleteOverlay.style.display = 'flex';
        docDeleteOverlay.classList.add('active');
    }

    // Handle file uploads
    const docUploadForm = document.getElementById('docUploadForm');
    if (docUploadForm) {
        docUploadForm.addEventListener('submit', e => {
            e.preventDefault();
            const formData = new FormData(e.target);
            const submitBtn = e.target.querySelector('button[type="submit"]');

            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Uploading...';
            }

            fetch('ajax/projects/upload_ajax.php', {
                    method: 'POST',
                    body: formData
                })
                .then(res => res.text())
                .then(result => {
                    if (result.trim() === 'success') {
                        showPremiumAlert('Documents uploaded successfully');
                        openDocuments(docEmpIdField.value);
                        e.target.reset();
                    } else {
                        showPremiumAlert('Upload failed: ' + result, 'error');
                    }
                })
                .finally(() => {
                    if (submitBtn) {
                        submitBtn.disabled = false;
                        submitBtn.innerHTML = '<i class="fa fa-check"></i> Upload';
                    }
                });
        });
    }

    const perfMonthSelect = document.getElementById('perf_month');
    const perfYearSelect = document.getElementById('perf_year');
    const monthLabelMap = <?php echo json_encode($monthLabels); ?>;
    const defaultPerfMonth = <?php echo (int)$currentMonth; ?>;
    const defaultPerfYear = <?php echo (int)$currentYear; ?>;

    const formatDate = (dateStr) => {
        if (!dateStr || dateStr === '0000-00-00') return '-';
        if (typeof dateStr !== 'string') return '-';
        const parts = dateStr.split('-');
        if (parts.length !== 3) return dateStr;
        if (parts[0].length === 4) return `${parts[2]}-${parts[Parties[1]]}-${parts[0]}`; // Simple format
        return `${parts[0]}-${parts[1]}-${parts[2]}`;
    };

    function switchProfileTab(el) {
        if (!el) return;
        const target = el.getAttribute('data-target');
        document.querySelectorAll('.profile-nav-item').forEach(item => item.classList.remove('active'));
        el.classList.add('active');
        document.querySelectorAll('.profile-section').forEach(sec => sec.classList.remove('active'));
        const targetSec = document.getElementById(target);
        if (targetSec) targetSec.classList.add('active');
    }

    function openViewEmployee(btn, sectionId) {
        const data = JSON.parse(btn.dataset.emp);
        document.getElementById('view_img').src = data.employee_image ? 'uploads/' + data.employee_image : 'admin_images/default.png';
        document.getElementById('view_name').textContent = data.name || '-';
        if (document.getElementById('view_desig_sidebar')) document.getElementById('view_desig_sidebar').textContent = data.designation || 'Not Assigned';
        if (document.getElementById('view_dept_sidebar')) document.getElementById('view_dept_sidebar').textContent = data.department || 'Not Assigned';
        if (document.getElementById('view_dept_personal')) document.getElementById('view_dept_personal').textContent = data.department || 'Not Assigned';
        if (document.getElementById('view_desig_personal')) document.getElementById('view_desig_personal').textContent = data.designation || 'Not Assigned';
        if (document.getElementById('view_dept_salary')) document.getElementById('view_dept_salary').textContent = data.department || 'Not Assigned';
        if (document.getElementById('view_desig_salary')) document.getElementById('view_desig_salary').textContent = data.designation || 'Not Assigned';
        document.getElementById('view_id_label').textContent = 'ID: ' + data.id;
        document.getElementById('view_gender_badge').textContent = data.gender || 'Other';
        document.getElementById('view_phone').textContent = data.phone_number || '-';
        if (document.getElementById('view_company_email')) document.getElementById('view_company_email').textContent = data.company_email || data.email || '-';
        document.getElementById('view_email').textContent = data.email || '-';
        document.getElementById('view_blood').textContent = data.blood_group || '-';
        document.getElementById('view_join_sidebar').innerHTML = '<i class="fa fa-calendar-check-o"></i> Joined: ' + (data.join_date || '-');

        document.getElementById('view_dob').textContent = data.dob || '-';
        document.getElementById('view_age').textContent = data.age || '-';
        document.getElementById('view_marital').textContent = data.marital_status || '-';
        document.getElementById('view_dependents').textContent = data.num_dependents || '0';
        document.getElementById('view_address').textContent = data.address || '-';

        document.getElementById('view_e_name').textContent = data.emergency_name || '-';
        document.getElementById('view_e_rel').textContent = data.emergency_relationship || '-';
        document.getElementById('view_e_phone').textContent = data.emergency_phone || '-';
        document.getElementById('view_e_addr').textContent = data.emergency_address || '-';

        const eduList = document.getElementById('view_edu_list');
        eduList.innerHTML = '';
        try {
            const eduData = JSON.parse(data.education_json || '[]');
            if (eduData.length === 0) eduList.innerHTML = '<tr><td colspan="4" class="text-center">No records.</td></tr>';
            else eduData.forEach(item => {
                eduList.innerHTML += `<tr><td style="text-align: center;">${item.degree || '-'}</td><td style="text-align: center;">${item.univ || '-'}</td><td style="text-align: center;">${item.year || '-'}</td><td style="text-align: center;">${item.grade || '-'}</td></tr>`;
            });
        } catch (e) {
            eduList.innerHTML = '<tr><td colspan="4" class="text-center">Error.</td></tr>';
        }

        const histList = document.getElementById('view_hist_list');
        histList.innerHTML = '';
        try {
            const histData = JSON.parse(data.employment_json || '[]');
            if (histData.length === 0) histList.innerHTML = '<tr><td colspan="4" class="text-center">No history.</td></tr>';
            else histData.forEach(item => {
                histList.innerHTML += `<tr><td style="text-align: center;">${item.company || '-'}</td><td style="text-align: center;">${item.pos || item.position || '-'}</td><td style="text-align: center;">${item.year || '-'}</td><td style="text-align: center;">${item.reason || '-'}</td></tr>`;
            });
        } catch (e) {
            histList.innerHTML = '<tr><td colspan="4" class="text-center">Error.</td></tr>';
        }

        document.getElementById('view_acc_name').textContent = data.account_name || '-';
        document.getElementById('view_bank_br').textContent = data.bank_branch || '-';
        document.getElementById('view_acc_num').textContent = data.account_number || '-';
        document.getElementById('view_acc_ifsc').textContent = data.account_type_ifsc || '-';

        if (document.getElementById('view_join')) document.getElementById('view_join').textContent = data.join_date || '-';
        if (document.getElementById('view_salary')) document.getElementById('view_salary').textContent = parseFloat(data.salary || 0).toFixed(2);
        if (document.getElementById('view_basic')) document.getElementById('view_basic').textContent = parseFloat(data.basic_salary || 0).toFixed(2);
        if (document.getElementById('view_hra')) document.getElementById('view_hra').textContent = parseFloat(data.hra || 0).toFixed(2);
        if (document.getElementById('view_allowance')) document.getElementById('view_allowance').textContent = parseFloat(data.allowance || 0).toFixed(2);
        if (document.getElementById('view_deductions')) document.getElementById('view_deductions').textContent = parseFloat(data.deductions || 0).toFixed(2);

        const viewDocsList = document.getElementById('view_documents');
        viewDocsList.innerHTML = '<div style="text-align:center; padding:20px;"><i class="fa fa-spinner fa-spin"></i></div>';
        fetch('pages/documents/fetch_all_documents.php?id=' + data.id).then(res => res.text()).then(html => {
            viewDocsList.innerHTML = html;
        });

        const tab = document.querySelector(`.profile-nav-item[data-target="${sectionId}"]`);
        switchProfileTab(tab || document.querySelector('.profile-nav-item[data-target="section_personal"]'));
        $('#viewEmployeeModal').modal('show');
    }

    // --- Premium Delete Confirmation Logic ---
    let currentDeleteId = null;
    const deleteOverlay = document.getElementById('deleteConfirmOverlay');
    const deleteNameLabel = document.getElementById('delete_emp_name_label');
    const confirmDeleteBtn = document.getElementById('confirmDeleteBtn');
    let currentDocDelete = null;
    const docDeleteOverlay = document.getElementById('docDeleteConfirmOverlay');
    const confirmDocDeleteBtn = document.getElementById('confirmDocDeleteBtn');

    function showDeleteConfirm(id, name) {
        currentDeleteId = id;
        deleteNameLabel.textContent = name;
        deleteOverlay.style.display = 'flex';
        deleteOverlay.classList.add('active');
    }

    function closeDeleteConfirm() {
        deleteOverlay.classList.remove('active');
        deleteOverlay.style.display = 'none';
        currentDeleteId = null;
        confirmDeleteBtn.disabled = false;
        confirmDeleteBtn.innerHTML = 'Delete Now';
        confirmDeleteBtn.style.background = '#ef4444';
    }

    // Close on overlay click
    deleteOverlay.addEventListener('click', function(e) {
        if (e.target === deleteOverlay) closeDeleteConfirm();
    });

    confirmDeleteBtn.addEventListener('click', function() {
        if (!currentDeleteId) return;

        // Change button state
        confirmDeleteBtn.disabled = true;
        confirmDeleteBtn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Deleting...';

        fetch('pages/employees/delete_emp.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
                },
                body: 'id=' + encodeURIComponent(currentDeleteId)
            })
            .then(res => res.json())
            .then(result => {
                if (result.status === 'success') {
                    confirmDeleteBtn.style.background = '#22c55e';
                    confirmDeleteBtn.innerHTML = '<i class="fa fa-check"></i> Deleted';

                    setTimeout(() => {
                        location.reload();
                    }, 800);
                    return;
                }

                throw new Error(result.message || 'Delete failed');
            })
            .catch(err => {
                console.error('Delete error:', err);
                showPremiumAlert('Error deleting employee. Please try again.', 'error');
                confirmDeleteBtn.disabled = false;
                confirmDeleteBtn.innerHTML = 'Delete Now';
                confirmDeleteBtn.style.background = '#ef4444';
            });
    });

    function closeDocDeleteConfirm() {
        docDeleteOverlay.classList.remove('active');
        docDeleteOverlay.style.display = 'none';
        currentDocDelete = null;
        confirmDocDeleteBtn.disabled = false;
        confirmDocDeleteBtn.innerHTML = 'Delete Document';
    }

    if (docDeleteOverlay) {
        docDeleteOverlay.addEventListener('click', function(e) {
            if (e.target === docDeleteOverlay) {
                closeDocDeleteConfirm();
            }
        });
    }

    if (confirmDocDeleteBtn) {
        confirmDocDeleteBtn.addEventListener('click', function() {
            if (!currentDocDelete) return;

            confirmDocDeleteBtn.disabled = true;
            confirmDocDeleteBtn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Deleting...';

            fetch('pages/documents/delete_document.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
                    },
                    body: 'doc_id=' + encodeURIComponent(currentDocDelete.docId)
                })
                .then(res => res.json())
                .then(result => {
                    if (result.status === 'success') {
                        const empId = currentDocDelete.empId;
                        closeDocDeleteConfirm();
                        showPremiumAlert('Document deleted successfully');
                        openDocuments(empId);
                        return;
                    }

                    throw new Error('Delete failed');
                })
                .catch(() => {
                    showPremiumAlert('Error deleting file.', 'error');
                    confirmDocDeleteBtn.disabled = false;
                    confirmDocDeleteBtn.innerHTML = 'Delete Document';
                });
        });
    }

    // Performance modal handlers
    function updateMonthYearLabel() {
        const labelEl = document.getElementById('perfMonthYearLabel');
        if (!labelEl) return;
        const m = parseInt(perfMonthSelect?.value || '0', 10);
        const y = perfYearSelect?.value || '';
        labelEl.textContent = (monthLabelMap[m] || 'Month') + ' ' + y;
    }

    function applyPerformanceData(data) {
        const safe = (val) => {
            const n = parseInt(val, 10);
            return Number.isFinite(n) ? n : 0;
        };
        const safeFloat = (val) => {
            const n = parseFloat(val);
            return Number.isFinite(n) ? n : 0;
        };
        document.getElementById('perf_absent').value = safe(data.absent);
        document.getElementById('perf_late').value = safe(data.late);
        document.getElementById('perf_task').value = safe(data.task_sheet);
        document.getElementById('perf_core').value = safeFloat(data.performance_score);
        document.getElementById('perf_dress').value = safe(data.dressing_behaviour);
        document.getElementById('perf_rnd').value = safe(data.rnd);
        const total = safeFloat(data.total);
        document.getElementById('perfTotalValue').textContent = total.toFixed(2);
        updatePerformanceTotal();
    }

    function loadPerformanceForMonth(empId) {
        if (!empId || !perfMonthSelect || !perfYearSelect) return;
        const month = parseInt(perfMonthSelect.value, 10);
        const year = parseInt(perfYearSelect.value, 10);
        updateMonthYearLabel();
        fetch(`fetch/fetch_performance.php?emp_id=${empId}&month=${month}&year=${year}`)
            .then(res => res.ok ? res.json() : null)
            .then(json => {
                if (json && json.success && json.data) {
                    applyPerformanceData(json.data);
                } else {
                    updatePerformanceTotal();
                }
            })
            .catch(() => updatePerformanceTotal());
    }

    function onMonthYearChange() {
        const empId = document.getElementById('perf_emp_id')?.value || '';
        if (empId) {
            loadPerformanceForMonth(empId);
        }
    }

    if (perfMonthSelect) perfMonthSelect.addEventListener('change', onMonthYearChange);
    if (perfYearSelect) perfYearSelect.addEventListener('change', onMonthYearChange);

    function openPerformance(btn) {
        const data = btn.dataset;
        document.getElementById('perf_emp_id').value = data.emp;
        document.getElementById('perf_emp_name_field').value = data.name;
        document.getElementById('perfEmpName').textContent = data.name;
        document.getElementById('perf_avg_performance').value = data.avg_perf || 0;
        if (perfMonthSelect) perfMonthSelect.value = defaultPerfMonth;
        if (perfYearSelect) perfYearSelect.value = defaultPerfYear;
        updateMonthYearLabel();

        // Always use average performance (readonly field)
        const performanceScore = parseFloat(data.avg_perf || '0');

        applyPerformanceData({
            absent: data.absent || 0,
            late: data.late || 0,
            task_sheet: data.task_sheet || 0,
            performance_score: performanceScore,
            dressing_behaviour: data.dressing_behaviour || 0,
            rnd: data.rnd || 0,
            total: ['absent', 'late', 'task_sheet', 'dressing_behaviour', 'rnd']
                .map(k => parseInt(data[k] || '0', 10))
                .reduce((a, b) => a + (Number.isFinite(b) ? b : 0), 0) + performanceScore
        });
        loadPerformanceForMonth(data.emp);
        $('#performanceModal').modal('show');
    }

    function autoLoadAvgPerformance() {
        const avgPerf = parseFloat(document.getElementById('perf_avg_performance').value || '0') || 0;
        if (avgPerf > 0) {
            document.getElementById('perf_core').value = avgPerf;
            updatePerformanceTotal();
        } else {
            Swal.fire('Notification', 'No average daily performance data available for this month.', 'info');
        }
    }

    function updatePerformanceTotal() {
        const absent = parseInt(document.getElementById('perf_absent').value || '0', 10);
        const late = parseInt(document.getElementById('perf_late').value || '0', 10);
        const task = parseInt(document.getElementById('perf_task').value || '0', 10);
        const perf = parseFloat(document.getElementById('perf_core').value || '0');
        const dress = parseInt(document.getElementById('perf_dress').value || '0', 10);
        const rnd = parseInt(document.getElementById('perf_rnd').value || '0', 10);

        const total = absent + late + task + perf + dress + rnd;
        const totalBox = document.getElementById('perfTotalBox');
        document.getElementById('perfTotalValue').textContent = total.toFixed(2);
        if (total > 100) {
            totalBox.classList.add('alert', 'alert-danger');
        } else {
            totalBox.classList.remove('alert', 'alert-danger');
        }
    }

    ['perf_absent', 'perf_late', 'perf_task', 'perf_core', 'perf_dress', 'perf_rnd'].forEach(id => {
        const el = document.getElementById(id);
        el.addEventListener('input', updatePerformanceTotal);
    });

    document.getElementById('performanceForm').addEventListener('submit', function(e) {
        const totalText = document.getElementById('perfTotalValue').textContent;
        const total = parseFloat(totalText);
        if (total > 100) {
            e.preventDefault();
            Swal.fire('Notification', 'Total cannot exceed 100.', 'info');
        }
    });

    // Performance history (last 4 months) modal
    function scoreColor(val) {
        const v = parseInt(val, 10);
        if (v < 30) return '#6b7280'; // gray
        if (v < 50) return '#ef4444'; // red
        if (v < 70) return '#f59e0b'; // amber
        return '#22c55e'; // green
    }

    function openPerfHistory(btn) {
        const series = JSON.parse(btn.dataset.history || '[]');
        const empName = btn.dataset.empname || 'Employee';
        const breakdown = JSON.parse(btn.dataset.breakdown || '[]');
        const totalScore = parseInt(btn.dataset.total || '0', 10);
        document.getElementById('historyEmpName').textContent = empName;

        // Build SVG line + points
        const chartEl = document.getElementById('historyChart');
        const width = chartEl.clientWidth || 520;
        const height = 210;
        const pad = 22;
        const barArea = width - pad * 2;
        const slot = series.length ? (barArea / series.length) : 0;
        const gap = 10;
        const barWidth = slot ? Math.max(10, Math.min(18, slot * 0.55)) : 0;
        let bars = '';
        let labels = '';
        let grid = '';

        // horizontal grid lines every 25
        for (let i = 0; i <= 4; i++) {
            const val = i * 25;
            const y = height - pad - ((height - pad * 2) * val) / 100;
            grid += `<line x1="${pad}" y1="${y}" x2="${width - pad}" y2="${y}" stroke="#e2e8f0" stroke-width="1" stroke-dasharray="3 3"></line>`;
            grid += `<text x="${pad - 6}" y="${y + 4}" fill="#94a3b8" font-size="10" text-anchor="end">${val}</text>`;
        }

        series.forEach((s, idx) => {
            const capped = Math.min(100, Math.max(0, s.value));
            const h = ((height - pad * 2) * capped) / 100;
            const center = pad + idx * slot + slot / 2;
            const x = center - barWidth / 2;
            const y = height - pad - h;
            const c = scoreColor(capped);
            const barW = Math.max(10, Math.min(barWidth, 18));
            bars += `<rect x="${x}" y="${y}" width="${barW}" height="${h}" rx="7" fill="${c}" opacity="0.92" stroke="rgba(15,23,42,0.4)" stroke-width="0.5"></rect>`;
            labels += `<text x="${center}" y="${y - 8}" fill="#0f172a" font-size="11" text-anchor="middle">${capped}</text>`;
            labels += `<text x="${center}" y="${height - pad + 16}" fill="#475569" font-size="11" text-anchor="middle">${s.label}</text>`;
        });

        chartEl.innerHTML = `
            <svg width="${width}" height="${height}" viewBox="0 0 ${width} ${height}">
                <defs>
                    <linearGradient id="histGrad" x1="0" x2="0" y1="0" y2="1">
                        <stop offset="0%" stop-color="#f8fafc"></stop>
                        <stop offset="100%" stop-color="#eef2ff"></stop>
                    </linearGradient>
                    <filter id="histShadow" x="-10%" y="-10%" width="120%" height="120%">
                        <feDropShadow dx="0" dy="6" stdDeviation="6" flood-color="rgba(15,23,42,0.12)" />
                    </filter>
                </defs>
                <rect x="${pad - 6}" y="${pad - 6}" width="${width - (pad - 6) * 2}" height="${height - (pad - 6) * 2}" rx="12" fill="url(#histGrad)" stroke="#e2e8f0" stroke-width="1.1" filter="url(#histShadow)"></rect>
                ${grid}
                <line x1="${pad}" y1="${height - pad}" x2="${width - pad}" y2="${height - pad}" stroke="#cbd5e1" stroke-width="1.3"></line>
                <line x1="${pad}" y1="${pad}" x2="${pad}" y2="${height - pad}" stroke="#cbd5e1" stroke-width="1.3"></line>
                ${bars}
                ${labels}
            </svg>
        `;

        // Set point breakdown table
        const breakdownBody = document.getElementById('historyBreakdown');
        const breakdownTotal = document.getElementById('historyBreakdownTotal');
        if (breakdownBody) {
            const detailRows = breakdown.filter(item => (item.label || '').toLowerCase() !== 'total');
            const totalEntry = breakdown.find(item => (item.label || '').toLowerCase() === 'total');
            const displayedTotal = totalEntry ? (parseInt(totalEntry.user, 10) || 0) : totalScore || 0;
            if (detailRows.length === 0) {
                breakdownBody.innerHTML = `<tr><td colspan="3" class="history-empty-row">No set points yet for this month.</td></tr>`;
            } else {
                breakdownBody.innerHTML = detailRows.map(item => {
                    const userPts = parseInt(item.user, 10) || 0;
                    const badgeColor = scoreColor(userPts);
                    return `
                        <tr>
                            <td style="vertical-align: middle;">${item.label}</td>
                            <td style="text-align: center; vertical-align: middle;">${item.max}</td>
                            <td style="text-align: center; vertical-align: middle;"><span class="history-point-badge" style="background:${badgeColor}1a; color:${badgeColor}; border:1px solid ${badgeColor}33;">${userPts}</span></td>
                        </tr>
                    `;
                }).join('');
            }
            if (breakdownTotal) {
                breakdownTotal.textContent = `${displayedTotal} / 100`;
            }
        }

        $('#performanceHistoryModal').modal('show');
    }
</script>

<style>
    /* Prevent Flash of Unstyled Content (FOUC) / profile picture flash on page refresh */
    .modal:not(.in):not(.show) {
        display: none !important;
    }

    /* Document Maintenance Modal Styles (Premium) */
    .doc-modal-overlay {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(15, 23, 42, 0.4);
        backdrop-filter: blur(8px);
        z-index: 2000;
        justify-content: center;
        align-items: center;
        opacity: 0;
        transition: opacity 0.3s ease;
    }

    .doc-modal-overlay.active {
        opacity: 1;
    }

    .doc-modal-container {
        background: #fff;
        width: 90%;
        max-width: 600px;
        border-radius: 20px;
        box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
        overflow: hidden;
        transform: translateY(20px);
        transition: transform 0.3s ease;
    }

    .doc-modal-overlay.active .doc-modal-container {
        transform: translateY(0);
    }

    .doc-modal-header {
        position: relative;
        padding: 20px 25px;
        background: #ffeaeb;
        border-bottom: 1px solid #e2e8f0;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .doc-modal-title-group {
        display: flex;
        align-items: center;
        gap: 12px;
    }

    .doc-modal-title-group i {
        font-size: 20px;
        color: #dd2127;
    }

    .doc-modal-title-group h3 {
        margin: 0;
        font-size: 18px;
        font-weight: 700;
        color: #0f172a;
    }

    .doc-modal-body {
        padding: 25px;
        max-height: 70vh;
        overflow-y: auto;
    }

    .doc-modal-list-view {
        margin-bottom: 25px;
        display: flex;
        flex-direction: column;
        gap: 10px;
    }

    .doc-item {
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        padding: 12px 16px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        transition: all 0.2s;
    }

    .doc-item:hover {
        border-color: #cbd5e1;
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
    }

    .premium-notification {
        position: fixed;
        top: 20px;
        right: 20px;
        padding: 16px 24px;
        border-radius: 16px;
        display: flex;
        align-items: center;
        gap: 12px;
        z-index: 9999;
        box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
        backdrop-filter: blur(10px);
        border: 1px solid rgba(255, 255, 255, 0.2);
        color: white;
        font-weight: 600;
        transform: translateX(120%);
        transition: transform 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
    }

    .premium-notification.active {
        transform: translateX(0);
    }

    .notification-success {
        background: rgba(16, 185, 129, 0.92);
    }

    .notification-error {
        background: rgba(239, 68, 68, 0.92);
    }

    .doc-item a {
        color: #1e293b;
        text-decoration: none;
        font-weight: 600;
        font-size: 14px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        max-width: 250px;
    }

    .delete-doc {
        background: #fee2e2;
        color: #ef4444;
        border: none;
        padding: 6px 12px;
        border-radius: 8px;
        font-size: 11px;
        font-weight: 700;
        cursor: pointer;
        transition: all 0.2s;
    }

    .delete-doc:hover {
        background: #ef4444;
        color: #fff;
    }

    .doc-modal-upload-section {
        background: #ffeaeb;
        border-radius: 16px;
        padding: 15px;
        border: 1px dashed #dd2127;
    }

    .upload-section-header {
        display: flex;
        align-items: center;
        gap: 8px;
        margin-bottom: 12px;
        font-size: 13px;
        font-weight: 700;
        color: #dd2127;
    }

    .upload-controls {
        display: flex;
        gap: 10px;
    }

    .custom-file-input {
        flex: 1;
        position: relative;
    }

    .custom-file-input input {
        position: absolute;
        width: 0;
        height: 0;
        opacity: 0;
    }

    .custom-file-input label {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        background: #fff;
        border: 1px solid #e2e8f0;
        padding: 12px 12px;
        border-radius: 10px;
        cursor: pointer;
        font-size: 12px;
        color: #64748b;
        width: 100%;
        margin: 0;
    }

    .btn-upload {
        padding: 8px 16px;
        border-radius: 10px;
        font-weight: 700;
        font-size: 13px;
    }

    .no-docs {
        text-align: center;
        padding: 30px;
        color: #94a3b8;
    }

    .no-docs i {
        font-size: 30px;
        margin-bottom: 10px;
        opacity: 0.5;
    }

    .no-docs p {
        margin: 0;
        font-size: 13px;
    }

    /* Performance history modal */
    #performanceHistoryModal .modal-content {
        border-radius: 10px;
        border: 1px solid #e2e8f0;
        box-shadow: 0 16px 44px rgba(15, 23, 42, 0.16);
    }

    #performanceHistoryModal .modal-header {
        border-bottom: 1px solid #e2e8f0;
    }

    .history-chart-box {
        background: linear-gradient(180deg, #f8fafc, #eef2ff);
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        padding: 14px;
        box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.7);
    }

    .history-table-wrap {
        margin-top: 14px;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        padding: 12px;
        background: #ffffff;
        box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.6);
    }

    .history-table-title {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        font-weight: 600;
        color: #0f172a;
    }

    .history-total-pill {
        display: inline-block;
        background: #0ea5e9;
        color: #fff;
        padding: 4px 10px;
        border-radius: 999px;
        font-weight: 700;
        font-size: 12px;
        letter-spacing: 0.01em;
    }

    .history-breakdown-table thead th {
        background: #f8fafc;
        color: #475569;
        font-size: 12px;
        text-transform: uppercase;
        letter-spacing: 0.02em;
        border-bottom: 1px solid #e2e8f0;
    }

    .history-breakdown-table tbody td {
        vertical-align: middle;
        color: #0f172a;
    }

    .history-point-badge {
        display: inline-block;
        padding: 4px 9px;
        border-radius: 10px;
        font-weight: 700;
        font-size: 12px;
        background: #e2e8f0;
        color: #0f172a;
    }

    .history-empty-row {
        text-align: center;
        color: #94a3b8;
    }

    .score-btn {
        border-color: transparent;
    }

    .score-plain {
        background: #f8fafc;
        color: #0f172a;
        border-color: #e2e8f0;
    }

    .score-red {
        background: #ef4444;
        color: #fff;
        border-color: #dc2626;
    }

    .score-gray {
        background: #94a3b8;
        color: #0f172a;
        border-color: #94a3b8;
    }

    .score-amber {
        background: #f59e0b;
        color: #0f172a;
        border-color: #d97706;
    }

    .score-green {
        background: #22c55e;
        color: #fff;
        border-color: #16a34a;
    }

    /* Premium Delete Confirmation Modal */
    .premium-confirm-overlay {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(15, 23, 42, 0.4);
        backdrop-filter: blur(8px);
        z-index: 9999;
        justify-content: center;
        align-items: center;
        opacity: 0;
        transition: opacity 0.3s ease;
    }

    .premium-confirm-overlay.active {
        display: flex;
        opacity: 1;
    }

    .premium-confirm-modal {
        background: #fff;
        width: 100%;
        max-width: 400px;
        border-radius: 20px;
        box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
        overflow: hidden;
        transform: scale(0.9);
        transition: transform 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
        padding: 30px;
        text-align: center;
    }

    .premium-confirm-overlay.active .premium-confirm-modal {
        transform: scale(1);
    }

    .confirm-icon-box {
        width: 60px;
        height: 60px;
        background: #fee2e2;
        color: #ef4444;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 24px;
        margin: 0 auto 20px auto;
        animation: pulseDanger 2s infinite;
    }

    @keyframes pulseDanger {
        0% {
            box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.4);
        }

        70% {
            box-shadow: 0 0 0 15px rgba(239, 68, 68, 0);
        }

        100% {
            box-shadow: 0 0 0 0 rgba(239, 68, 68, 0);
        }
    }

    .premium-confirm-header h3 {
        margin: 0 0 10px 0;
        font-size: 20px;
        font-weight: 700;
        color: #0f172a;
    }

    .premium-confirm-header p {
        margin: 0 0 25px 0;
        font-size: 14px;
        color: #64748b;
        line-height: 1.5;
    }

    .premium-confirm-footer {
        display: flex;
        gap: 12px;
    }

    .confirm-btn-cancel {
        flex: 1;
        padding: 12px;
        border-radius: 12px;
        background: #f1f5f9;
        color: #64748b;
        border: none;
        font-weight: 700;
        font-size: 14px;
        cursor: pointer;
        transition: all 0.2s;
    }

    .confirm-btn-cancel:hover {
        background: #e2e8f0;
        color: #0f172a;
    }

    .confirm-btn-delete {
        flex: 1;
        padding: 12px;
        border-radius: 12px;
        background: #ef4444;
        color: #fff;
        border: none;
        font-weight: 700;
        font-size: 14px;
        cursor: pointer;
        transition: all 0.2s;
        box-shadow: 0 4px 6px -1px rgba(239, 68, 68, 0.2);
    }

    .confirm-btn-delete:hover {
        background: #dc2626;
        transform: translateY(-1px);
        box-shadow: 0 10px 15px -3px rgba(239, 68, 68, 0.3);
    }

    /* Comprehensive Form Styles */
    .form-section-title {
        background: #f8fafc;
        padding: 8px 12px;
        border-left: 4px solid #3b82f6;
        margin: 20px 0 15px 0;
        font-weight: 700;
        color: #1e293b;
        font-size: 15px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .form-section-title:first-child {
        margin-top: 0;
    }

    .modal-lg-custom {
        width: 90%;
        max-width: 1000px;
    }

    .grid-row {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 15px;
        margin-bottom: 15px;
    }

    .grid-col {
        display: flex;
        flex-direction: column;
    }

    .grid-col label {
        font-weight: 600;
        margin-bottom: 5px;
        color: #475569;
        font-size: 13px;
    }

    .table-input {
        width: 100%;
        border: 1px solid #e2e8f0;
        padding: 6px 10px;
        border-radius: 4px;
        font-size: 13px;
    }

    .dynamic-table {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 15px;
    }

    .dynamic-table th {
        background: #f1f5f9;
        color: #475569;
        font-weight: 600;
        font-size: 12px;
        padding: 8px;
        text-align: left;
        border: 1px solid #e2e8f0;
    }

    .dynamic-table td {
        padding: 5px;
        border: 1px solid #e2e8f0;
    }

    /* View Modal Specific Styles */
    .view-info-item {
        margin-bottom: 12px;
        border-bottom: 1px solid #f1f5f9;
        padding-bottom: 8px;
    }

    .view-info-label {
        font-weight: 700;
        color: #64748b;
        font-size: 11px;
        text-transform: uppercase;
        display: block;
        margin-bottom: 2px;
    }

    .view-info-value {
        color: #1e293b;
        font-size: 14px;
        font-weight: 500;
    }

    .view-image-large {
        width: 120px;
        height: 120px;
        border-radius: 20px;
        object-fit: cover;
        object-position: center 10%;
        /* Ensures face focus in sidebar */
        border: 3px solid #fff;
        box-shadow: 0 8px 16px rgba(0, 0, 0, 0.1);
        margin-bottom: 15px;
        transition: transform 0.3s;
    }

    .view-modal-header {
        background: #f8fafc;
        border-bottom: 1px solid #e2e8f0;
        padding: 15px 20px;
    }

    .table-view-btn {
        padding: 4px 8px;
        font-size: 11px;
        border-radius: 4px;
        background: #f1f5f9;
        color: #475569;
        border: 1px solid #e2e8f0;
        transition: all 0.2s;
    }

    .table-view-btn:hover {
        background: #e2e8f0;
        color: #0f172a;
    }

    .emp-table-img {
        width: 50px;
        height: 50px;
        border-radius: 50%;
        object-fit: cover;
        object-position: center 10%;
        /* Focus on the face (top portion) */
        border: 2px solid #e2e8f0;
        cursor: pointer;
        transition: transform 0.25s ease, border-color 0.25s ease, box-shadow 0.25s ease;
        box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        outline: none !important;
        -webkit-tap-highlight-color: transparent;
    }

    .emp-table-img:hover {
        transform: scale(1.08);
        border-color: #dd2127;
        box-shadow: 0 6px 12px -2px rgba(221, 33, 39, 0.25);
    }

    .emp-table-img:focus,
    .emp-table-img:focus-visible,
    .emp-table-img:active {
        outline: none !important;
        border-color: #e2e8f0;
        box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
    }

    /* Round Profile Modal & Preview Styles */
    .view-image-round {
        width: 320px;
        height: 320px;
        border-radius: 50%;
        object-fit: cover;
        object-position: center 10%;
        /* Ensures the face is centered in the circle */
        border: 8px solid rgba(255, 255, 255, 0.3);
        box-shadow: 0 0 50px rgba(0, 0, 0, 0.5);
        background: #f8fafc;
        padding: 5px;
    }

    .preview-circle-container {
        display: flex;
        align-items: center;
        gap: 20px;
        margin-top: 10px;
        padding: 10px;
        background: #f8fafc;
        border-radius: 12px;
        border: 1px dashed #e2e8f0;
    }

    .image-preview-circle {
        width: 70px;
        height: 80px;
        border-radius: 50%;
        object-fit: cover;
        object-position: center 10%;
        /* Face-first preview */
        border: 3px solid #fff;
        box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
        background: #eef2ff;
    }

    /* Professional Profile Modal Styles */
    .profile-modal-body {
        display: flex;
        padding: 0 !important;
        background: #f8fafc;
        min-height: 500px;
    }

    .profile-sidebar {
        width: 280px;
        background: #ffffff;
        border-right: 1px solid #e2e8f0;
        display: flex;
        flex-direction: column;
        padding: 30px 0;
    }

    .profile-sidebar-header {
        padding: 0 25px 25px 25px;
        text-align: center;
        border-bottom: 1px solid #f1f5f9;
        margin-bottom: 15px;
    }

    .profile-nav {
        display: flex;
        flex-direction: column;
        gap: 2px;
    }

    .profile-nav-item {
        padding: 12px 25px;
        display: flex;
        align-items: center;
        gap: 12px;
        color: #64748b;
        font-weight: 600;
        font-size: 14px;
        cursor: pointer;
        transition: all 0.2s;
        border-right: 3px solid transparent;
    }

    .profile-nav-item i {
        width: 20px;
        font-size: 16px;
    }

    .profile-nav-item:hover {
        background: #FFEAEB;
        color: #dd2127;
    }

    .profile-nav-item.active {
        background: #FFEAEB;
        color: #dd2127;
        border-right-color: #dd2127;
    }

    .profile-content {
        flex: 1;
        padding: 40px;
        background: #ffffff;
        overflow-y: auto;
    }

    .profile-section-title {
        font-size: 20px;
        font-weight: 700;
        color: #0f172a;
        margin-bottom: 25px;
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .profile-data-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 25px;
    }

    .profile-data-card {
        background: #f8fafc;
        padding: 15px 20px;
        border-radius: 10px;
        border: 1px solid #f1f5f9;
    }

    .profile-data-label {
        font-size: 11px;
        text-transform: uppercase;
        color: #94a3b8;
        font-weight: 700;
        letter-spacing: 0.5px;
        margin-bottom: 6px;
        display: block;
    }

    .profile-data-value {
        font-size: 15px;
        color: #1e293b;
        font-weight: 600;
    }

    .profile-section {
        display: none;
    }

    .profile-section.active {
        display: block;
        animation: fadeIn 0.3s ease-out;
    }

    @keyframes fadeIn {
        from {
            opacity: 0;
            transform: translateY(10px);
        }

        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    .close-profile-btn {
        position: absolute;
        top: 20px;
        right: 20px;
        background: #dd2127;
        border: none;
        width: 36px;
        height: 36px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        color: #fff;
        cursor: pointer;
        transition: all 0.2s;
        z-index: 100;
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
    }

    .close-profile-btn:hover {
        background: #e2e8f0;
        color: #0f172a;
        transform: rotate(90deg);
    }

    .profile-content {
        position: relative;
    }
</style>


<?php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;


require_once __DIR__ . '/../../PHPMailer/src/Exception.php';
require_once __DIR__ . '/../../PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/../../PHPMailer/src/SMTP.php';
?>

<?php
// PHP handler for Add Employee moved to add_emp.php
?>
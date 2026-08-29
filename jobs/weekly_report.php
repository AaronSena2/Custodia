<?php
/**
 * Scheduled entry point for the weekly operations report — generates it,
 * notifies every active SYSTEM_ADMIN/RECORDS_MANAGER in-app, and writes the
 * REPORT_GENERATED audit entry. Same "small CLI script" convention as
 * seed.php: no arguments, run with `php jobs/weekly_report.php`, safe to
 * re-run (each run just creates another report + notification).
 *
 * Register with Windows Task Scheduler to run every Friday at 5pm:
 *   schtasks /create /tn "Custodia Weekly Report" /tr "C:\xampp\php\php.exe C:\xampp\htdocs\custodia-php\jobs\weekly_report.php" /sc weekly /d FRI /st 17:00
 * (adjust the php.exe and htdocs paths to match your actual XAMPP install.)
 * On a Linux/cron host, the equivalent is a crontab entry:
 *   0 17 * * 5 php /path/to/custodia/jobs/weekly_report.php
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/reports.php';

$pdo = custodia_db();

try {
    $id = custodia_run_weekly_report_job($pdo, null, 'SCHEDULED');
    echo "Weekly report generated: {$id}\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Weekly report generation failed: ' . $e->getMessage() . "\n");
    exit(1);
}

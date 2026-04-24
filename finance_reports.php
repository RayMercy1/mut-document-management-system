<?php
/**
 * MUT DMS — Finance Office Reports
 * finance_reports.php
 */
session_start();
require_once 'db_config.php';

if (!isset($_SESSION['reg_number'])) { header("Location: login.php"); exit(); }

$user_role       = $_SESSION['role'];
$user_admin_role = $_SESSION['admin_role'] ?? 'none';
$reg_number      = $_SESSION['reg_number'];
$full_name       = $_SESSION['full_name'] ?? 'Finance Officer';
$current_view    = $_SESSION['current_admin_view'] ?? $user_admin_role;

$is_finance = ($user_role === 'admin' && $user_admin_role === 'finance')
           || ($user_role === 'super_admin' && $current_view === 'finance');
if (!$is_finance && $user_role !== 'super_admin') { header("Location: login.php"); exit(); }

$fin_modules = "'Resit','Retake','Special_Exam','Bursary','Fees'";
$year_filter  = intval($_GET['year']   ?? date('Y'));
$month_filter = $_GET['month'] ?? '';

// ── Summary stats ──
$summary = $conn->query("
    SELECT
        SUM(CASE WHEN status='Pending_Finance' THEN 1 ELSE 0 END) AS pending,
        SUM(CASE WHEN status='Approved' AND finance_approved=1 THEN 1 ELSE 0 END) AS approved,
        SUM(CASE WHEN status='Rejected' AND finance_rejection_reason IS NOT NULL THEN 1 ELSE 0 END) AS rejected,
        COUNT(*) AS total
    FROM documents WHERE module_type IN ({$fin_modules})
")->fetch_assoc();

// ── Monthly breakdown ──
$monthly = $conn->query("
    SELECT DATE_FORMAT(upload_date,'%Y-%m') AS ym,
           DATE_FORMAT(upload_date,'%b %Y') AS label,
           COUNT(*) AS total,
           SUM(CASE WHEN status='Approved' AND finance_approved=1 THEN 1 ELSE 0 END) AS approved,
           SUM(CASE WHEN status='Rejected' AND finance_rejection_reason IS NOT NULL THEN 1 ELSE 0 END) AS rejected,
           SUM(CASE WHEN status='Pending_Finance' THEN 1 ELSE 0 END) AS pending
    FROM documents WHERE module_type IN ({$fin_modules})
    AND YEAR(upload_date) = {$year_filter}
    GROUP BY ym ORDER BY ym DESC
");

// ── By module type ──
$by_module = $conn->query("
    SELECT module_type,
           COUNT(*) AS total,
           SUM(CASE WHEN status='Approved' AND finance_approved=1 THEN 1 ELSE 0 END) AS approved,
           SUM(CASE WHEN status='Rejected' AND finance_rejection_reason IS NOT NULL THEN 1 ELSE 0 END) AS rejected,
           SUM(CASE WHEN status='Pending_Finance' THEN 1 ELSE 0 END) AS pending
    FROM documents WHERE module_type IN ({$fin_modules})
    AND YEAR(upload_date) = {$year_filter}
    GROUP BY module_type ORDER BY total DESC
");

// ── By department ──
$by_dept = $conn->query("
    SELECT dept.dept_name, dept.school,
           COUNT(*) AS total,
           SUM(CASE WHEN d.status='Approved' AND d.finance_approved=1 THEN 1 ELSE 0 END) AS approved,
           SUM(CASE WHEN d.status='Rejected' AND d.finance_rejection_reason IS NOT NULL THEN 1 ELSE 0 END) AS rejected
    FROM documents d
    JOIN users u ON d.reg_number = u.reg_number
    LEFT JOIN departments dept ON u.department_id = dept.id
    WHERE d.module_type IN ({$fin_modules}) AND YEAR(d.upload_date) = {$year_filter}
    GROUP BY dept.id ORDER BY total DESC
");

// ── Recent activity (last 20) ──
$recent_act = $conn->query("
    SELECT d.*, u.full_name AS student_name, dept.dept_name
    FROM documents d
    JOIN users u ON d.reg_number = u.reg_number
    LEFT JOIN departments dept ON u.department_id = dept.id
    WHERE d.module_type IN ({$fin_modules})
    AND (d.finance_approved=1 OR (d.status='Rejected' AND d.finance_rejection_reason IS NOT NULL))
    AND YEAR(d.upload_date) = {$year_filter}
    ORDER BY COALESCE(d.finance_approved_at, d.rejected_at) DESC LIMIT 20
");

// Available years
$years_res = $conn->query("SELECT DISTINCT YEAR(upload_date) AS y FROM documents WHERE module_type IN ({$fin_modules}) ORDER BY y DESC");
$years = [];
while ($yr = $years_res->fetch_assoc()) $years[] = $yr['y'];
if (empty($years)) $years = [date('Y')];

$mod_cls = ['Resit'=>'mod-resit','Retake'=>'mod-retake','Special_Exam'=>'mod-special','Bursary'=>'mod-bursary','Fees'=>'mod-fees'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Finance Reports | MUT DMS</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
:root{--fin:#0ea5e9;--fin-dark:#0284c7;--bg:#f0f9ff;--sec:#0f172a;--card:#fff;--text:#1e293b;--text-light:#64748b;--border:#e2e8f0;--danger:#ef4444;--success:#22c55e;--warning:#f59e0b;--shadow:0 1px 3px rgba(0,0,0,.08);--shadow-lg:0 10px 25px -5px rgba(0,0,0,.12)}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Inter',sans-serif;background:var(--bg);color:var(--text);min-height:100vh}
.layout{display:flex;min-height:100vh}
.sidebar{width:272px;background:var(--sec);position:fixed;height:100vh;overflow-y:auto;z-index:100;display:flex;flex-direction:column}
.sidebar-header{padding:22px 20px 16px;border-bottom:1px solid rgba(255,255,255,.08)}
.logo{display:flex;align-items:center;gap:12px}
.logo-icon{width:44px;height:44px;background:linear-gradient(135deg,var(--fin),var(--fin-dark));border-radius:12px;display:flex;align-items:center;justify-content:center;color:#fff;font-size:1.3rem;flex-shrink:0}
.logo-text h3{color:#fff;font-size:1rem;font-weight:700}.logo-text span{font-size:.7rem;color:rgba(255,255,255,.5)}
.view-box{margin:14px 16px;padding:12px 16px;background:rgba(14,165,233,.15);border:1px solid rgba(14,165,233,.3);border-radius:10px}
.view-box .vb-lbl{font-size:.64rem;color:rgba(255,255,255,.5);text-transform:uppercase;letter-spacing:.08em;margin-bottom:4px}
.view-box .vb-val{font-size:.92rem;font-weight:700;color:var(--fin);display:flex;align-items:center;gap:8px}
.btn-back{display:flex;align-items:center;justify-content:center;gap:8px;margin:0 16px 8px;padding:9px;background:rgba(255,255,255,.07);border:1px dashed rgba(255,255,255,.25);border-radius:8px;color:rgba(255,255,255,.65);font-size:.82rem;text-decoration:none;transition:all .2s}
.btn-back:hover{background:rgba(255,255,255,.14);color:#fff;border-style:solid}
.nav-section{padding:8px 0;flex:1}
.nav-gtitle{padding:10px 20px 4px;font-size:.63rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:rgba(255,255,255,.35)}
.nav-item{display:flex;align-items:center;gap:11px;padding:10px 20px;color:rgba(255,255,255,.68);text-decoration:none;transition:all .2s;border-left:3px solid transparent;font-size:.875rem}
.nav-item:hover{background:rgba(14,165,233,.12);color:#fff;border-left-color:rgba(14,165,233,.5)}
.nav-item.active{background:rgba(14,165,233,.2);color:#fff;border-left-color:var(--fin);font-weight:600}
.nav-item i{width:18px;text-align:center;font-size:.92rem;flex-shrink:0}
.sidebar-footer{padding:14px 16px;border-top:1px solid rgba(255,255,255,.08);margin-top:auto}
.user-card{display:flex;align-items:center;gap:10px;margin-bottom:10px}
.user-av{width:36px;height:36px;border-radius:9px;background:linear-gradient(135deg,var(--fin),var(--fin-dark));display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:.88rem;flex-shrink:0}
.user-info .name{color:#fff;font-size:.875rem;font-weight:600}
.user-info .role{color:rgba(255,255,255,.45);font-size:.7rem}
.btn-logout{display:flex;align-items:center;justify-content:center;gap:8px;width:100%;padding:10px;background:rgba(239,68,68,.18);color:#ef4444;border:none;border-radius:8px;font-size:.82rem;font-weight:600;cursor:pointer;transition:all .2s;text-decoration:none}
.btn-logout:hover{background:rgba(239,68,68,.3)}
.main-content{margin-left:272px;flex:1;padding:28px 30px}
.page-hdr{margin-bottom:24px;display:flex;justify-content:space-between;align-items:flex-end;flex-wrap:wrap;gap:12px}
.page-hdr-left h1{font-size:1.6rem;font-weight:800}
.page-hdr-left p{color:var(--text-light);margin-top:4px;font-size:.88rem}
.year-form{display:flex;align-items:center;gap:8px}
.year-select{padding:8px 12px;border:1px solid var(--border);border-radius:8px;font-family:inherit;font-size:.85rem;background:#fff;color:var(--text)}
.year-select:focus{outline:none;border-color:var(--fin)}
.btn-print{padding:8px 16px;background:#fff;border:1px solid var(--border);border-radius:8px;font-size:.83rem;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:7px;color:var(--text);text-decoration:none;transition:all .2s}
.btn-print:hover{background:var(--border)}
.stats-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:24px}
.stat-card{background:var(--card);border:1px solid var(--border);border-radius:14px;padding:20px;display:flex;align-items:center;gap:14px;box-shadow:var(--shadow)}
.stat-ic{width:48px;height:48px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:1.2rem;flex-shrink:0}
.si-p{background:#e0f2fe;color:#0284c7}.si-a{background:#dcfce7;color:#16a34a}.si-r{background:#fee2e2;color:#dc2626}.si-t{background:#f3f4f6;color:#374151}
.stat-val{font-size:1.85rem;font-weight:800;line-height:1}
.stat-lbl{font-size:.75rem;color:var(--text-light);margin-top:3px}
.grid-2{display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:24px}
.rcard{background:var(--card);border:1px solid var(--border);border-radius:14px;overflow:hidden;box-shadow:var(--shadow)}
.rcard-hdr{padding:14px 18px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center}
.rcard-title{font-weight:700;font-size:.92rem;display:flex;align-items:center;gap:8px}
.rcard-title i{color:var(--fin)}
table.rt{width:100%;border-collapse:collapse}
table.rt th{font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--text-light);background:#f8fafc;padding:9px 14px;text-align:left;border-bottom:1px solid var(--border)}
table.rt td{padding:10px 14px;border-bottom:1px solid var(--border);font-size:.85rem;vertical-align:middle}
table.rt tr:last-child td{border-bottom:none}
table.rt tr:hover td{background:#fafafa}
.pbar-wrap{background:#f1f5f9;border-radius:6px;height:7px;width:100%;overflow:hidden}
.pbar{height:7px;border-radius:6px;background:var(--fin)}
.pbar.ok{background:#22c55e}
.pbar.rej{background:var(--danger)}
.mod-tag{display:inline-block;padding:2px 8px;border-radius:11px;font-size:.7rem;font-weight:600}
.mod-resit{background:#dbeafe;color:#1d4ed8}.mod-retake{background:#ede9fe;color:#6d28d9}
.mod-special{background:#fef3c7;color:#92400e}.mod-bursary{background:#dcfce7;color:#166534}.mod-fees{background:#fee2e2;color:#991b1b}
.sp{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:18px;font-size:.7rem;font-weight:600}
.sp-ok{background:#dcfce7;color:#166534}.sp-rej{background:#fee2e2;color:#991b1b}.sp-oth{background:#f3f4f6;color:#374151}
.full-card{background:var(--card);border:1px solid var(--border);border-radius:14px;overflow:hidden;box-shadow:var(--shadow);margin-bottom:24px}
.empty-st{text-align:center;padding:40px 24px;color:var(--text-light)}
.empty-st i{font-size:2rem;margin-bottom:8px;display:block;opacity:.4}
@media print{.sidebar,.year-form,.btn-print,.no-print{display:none!important}.main-content{margin-left:0!important}}
</style>
</head>
<body>
<div class="layout">
<aside class="sidebar">
    <div class="sidebar-header">
        <div class="logo">
            <div class="logo-icon"><i class="fa-solid fa-building-columns"></i></div>
            <div class="logo-text"><h3>Finance Office</h3><span>MUT Document System</span></div>
        </div>
    </div>
    <div class="view-box">
        <div class="vb-lbl">Currently Viewing</div>
        <div class="vb-val"><i class="fa-solid fa-eye"></i> Finance Office View</div>
    </div>
    <?php if ($user_role === 'super_admin' || isset($_SESSION['current_admin_view'])): ?>
    <a href="admin_dashboard.php?clear_view=1" class="btn-back"><i class="fa-solid fa-arrow-left"></i> Back to System Admin</a>
    <?php endif; ?>
    <div class="nav-section">
        <div class="nav-gtitle">Navigation</div>
        <a href="finance_dashboard.php" class="nav-item"><i class="fa-solid fa-grid-2"></i> Dashboard</a>
        <a href="finance_dashboard.php?page=all_documents" class="nav-item"><i class="fa-solid fa-folder-open"></i> All Documents</a>
        <a href="finance_reports.php" class="nav-item active"><i class="fa-solid fa-chart-bar"></i> Reports</a>
        <a href="finance_dashboard.php#composeSection" class="nav-item"><i class="fa-solid fa-envelope-open-text"></i> Compose Email</a>
        <div class="nav-gtitle" style="margin-top:8px;">Account</div>
        <a href="logout.php" class="nav-item"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
    </div>
    <div class="sidebar-footer">
        <div class="user-card">
            <div class="user-av"><?php echo strtoupper(substr($full_name,0,1)); ?></div>
            <div class="user-info"><div class="name"><?php echo htmlspecialchars($full_name); ?></div><div class="role">Finance Officer</div></div>
        </div>
        <a href="logout.php" class="btn-logout"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
    </div>
</aside>

<main class="main-content">
    <div class="page-hdr">
        <div class="page-hdr-left">
            <h1><i class="fa-solid fa-chart-bar" style="color:var(--fin);margin-right:8px;"></i>Finance Reports</h1>
            <p>Analytics and summaries for all Finance Office document processing – <?php echo $year_filter; ?></p>
        </div>
        <div style="display:flex;gap:10px;align-items:center;" class="no-print">
            <form class="year-form" method="GET">
                <label style="font-size:.82rem;font-weight:600;color:var(--text-light);">Year:</label>
                <select name="year" class="year-select" onchange="this.form.submit()">
                    <?php foreach($years as $y): ?>
                    <option value="<?php echo $y; ?>" <?php echo $y==$year_filter?'selected':''; ?>><?php echo $y; ?></option>
                    <?php endforeach; ?>
                </select>
            </form>
            <button class="btn-print" onclick="window.print()"><i class="fa-solid fa-print"></i> Print</button>
        </div>
    </div>

    <!-- Overall Stats -->
    <div class="stats-grid">
        <div class="stat-card"><div class="stat-ic si-t"><i class="fa-solid fa-folder"></i></div><div><div class="stat-val"><?php echo $summary['total']??0; ?></div><div class="stat-lbl">Total Documents</div></div></div>
        <div class="stat-card"><div class="stat-ic si-p"><i class="fa-solid fa-hourglass-half"></i></div><div><div class="stat-val"><?php echo $summary['pending']??0; ?></div><div class="stat-lbl">Currently Pending</div></div></div>
        <div class="stat-card"><div class="stat-ic si-a"><i class="fa-solid fa-check-double"></i></div><div><div class="stat-val"><?php echo $summary['approved']??0; ?></div><div class="stat-lbl">Total Approved</div></div></div>
        <div class="stat-card"><div class="stat-ic si-r"><i class="fa-solid fa-xmark"></i></div><div><div class="stat-val"><?php echo $summary['rejected']??0; ?></div><div class="stat-lbl">Total Rejected</div></div></div>
    </div>

    <!-- Charts Row -->
    <?php
    // Collect data for charts
    $by_module->data_seek(0);
    $chart_labels = []; $chart_total = []; $chart_approved = []; $chart_rejected = []; $chart_pending = [];
    while ($bm = $by_module->fetch_assoc()) {
        $chart_labels[]   = $bm['module_type'];
        $chart_total[]    = intval($bm['total']);
        $chart_approved[] = intval($bm['approved']);
        $chart_rejected[] = intval($bm['rejected']);
        $chart_pending[]  = intval($bm['pending']);
    }
    $by_module->data_seek(0);
    $chart_labels_json   = json_encode($chart_labels);
    $chart_total_json    = json_encode($chart_total);
    $chart_approved_json = json_encode($chart_approved);
    $chart_rejected_json = json_encode($chart_rejected);
    $chart_pending_json  = json_encode($chart_pending);

    $pie_approved = intval($summary['approved'] ?? 0);
    $pie_rejected = intval($summary['rejected'] ?? 0);
    $pie_pending  = intval($summary['pending']  ?? 0);
    ?>
    <div style="display:grid;grid-template-columns:1.6fr 1fr;gap:20px;margin-bottom:24px;">
        <!-- Bar Chart: Documents by Type -->
        <div class="rcard">
            <div class="rcard-hdr">
                <div class="rcard-title"><i class="fa-solid fa-chart-bar"></i> Documents by Type (<?php echo $year_filter; ?>)</div>
            </div>
            <div style="padding:20px;">
                <canvas id="barChart" height="200"></canvas>
            </div>
        </div>
        <!-- Pie Chart: Overall Status -->
        <div class="rcard">
            <div class="rcard-hdr">
                <div class="rcard-title"><i class="fa-solid fa-chart-pie"></i> Overall Status Distribution</div>
            </div>
            <div style="padding:20px;display:flex;flex-direction:column;align-items:center;">
                <canvas id="pieChart" height="200" style="max-width:220px;"></canvas>
                <div style="display:flex;gap:16px;margin-top:14px;flex-wrap:wrap;justify-content:center;">
                    <span style="display:flex;align-items:center;gap:5px;font-size:.78rem;font-weight:600;color:#16a34a;"><span style="width:12px;height:12px;background:#22c55e;border-radius:3px;display:inline-block;"></span>Approved (<?php echo $pie_approved; ?>)</span>
                    <span style="display:flex;align-items:center;gap:5px;font-size:.78rem;font-weight:600;color:#dc2626;"><span style="width:12px;height:12px;background:#ef4444;border-radius:3px;display:inline-block;"></span>Rejected (<?php echo $pie_rejected; ?>)</span>
                    <span style="display:flex;align-items:center;gap:5px;font-size:.78rem;font-weight:600;color:#0284c7;"><span style="width:12px;height:12px;background:#0ea5e9;border-radius:3px;display:inline-block;"></span>Pending (<?php echo $pie_pending; ?>)</span>
                </div>
            </div>
        </div>
    </div>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
    <script>
    (function(){
        const labels   = <?php echo $chart_labels_json; ?>;
        const approved = <?php echo $chart_approved_json; ?>;
        const rejected = <?php echo $chart_rejected_json; ?>;
        const pending  = <?php echo $chart_pending_json; ?>;

        // Bar Chart
        new Chart(document.getElementById('barChart'), {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [
                    { label: 'Approved', data: approved, backgroundColor: '#22c55e', borderRadius: 5 },
                    { label: 'Rejected', data: rejected, backgroundColor: '#ef4444', borderRadius: 5 },
                    { label: 'Pending',  data: pending,  backgroundColor: '#0ea5e9', borderRadius: 5 },
                ]
            },
            options: {
                responsive: true,
                plugins: { legend: { position: 'top', labels: { font: { family: 'Inter', size: 11 }, boxWidth: 12 } } },
                scales: {
                    x: { stacked: false, grid: { display: false }, ticks: { font: { family: 'Inter', size: 11 } } },
                    y: { beginAtZero: true, grid: { color: '#f1f5f9' }, ticks: { font: { family: 'Inter', size: 11 }, stepSize: 1 } }
                }
            }
        });

        // Pie Chart
        new Chart(document.getElementById('pieChart'), {
            type: 'doughnut',
            data: {
                labels: ['Approved', 'Rejected', 'Pending'],
                datasets: [{
                    data: [<?php echo $pie_approved; ?>, <?php echo $pie_rejected; ?>, <?php echo $pie_pending; ?>],
                    backgroundColor: ['#22c55e','#ef4444','#0ea5e9'],
                    borderWidth: 2,
                    borderColor: '#fff',
                    hoverOffset: 6,
                }]
            },
            options: {
                responsive: true,
                cutout: '60%',
                plugins: { legend: { display: false } }
            }
        });
    })();
    </script>

    <!-- By Module + By Month -->
    <div class="grid-2">
        <!-- By Module Type -->
        <div class="rcard">
            <div class="rcard-hdr">
                <div class="rcard-title"><i class="fa-solid fa-layer-group"></i> By Document Type (<?php echo $year_filter; ?>)</div>
            </div>
            <?php if ($by_module && $by_module->num_rows > 0): ?>
            <table class="rt">
                <thead><tr><th>Type</th><th>Total</th><th>Approved</th><th>Rejected</th><th>Pending</th></tr></thead>
                <tbody>
                <?php while ($bm = $by_module->fetch_assoc()):
                    $mc = $mod_cls[$bm['module_type']] ?? 'mod-bursary';
                    $pct = $bm['total'] > 0 ? round($bm['approved']/$bm['total']*100) : 0;
                ?>
                <tr>
                    <td><span class="mod-tag <?php echo $mc; ?>"><?php echo htmlspecialchars($bm['module_type']); ?></span></td>
                    <td style="font-weight:700;"><?php echo $bm['total']; ?></td>
                    <td style="color:#16a34a;font-weight:600;"><?php echo $bm['approved']; ?></td>
                    <td style="color:#dc2626;font-weight:600;"><?php echo $bm['rejected']; ?></td>
                    <td style="color:#0284c7;font-weight:600;"><?php echo $bm['pending']; ?></td>
                </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
            <?php else: ?>
            <div class="empty-st"><i class="fa-solid fa-inbox"></i><p>No data for <?php echo $year_filter; ?>.</p></div>
            <?php endif; ?>
        </div>

        <!-- Monthly Breakdown -->
        <div class="rcard">
            <div class="rcard-hdr">
                <div class="rcard-title"><i class="fa-solid fa-calendar-days"></i> Monthly Breakdown (<?php echo $year_filter; ?>)</div>
            </div>
            <?php if ($monthly && $monthly->num_rows > 0): ?>
            <table class="rt">
                <thead><tr><th>Month</th><th>Total</th><th>Approved</th><th>Rejected</th><th>Pending</th></tr></thead>
                <tbody>
                <?php while ($mo = $monthly->fetch_assoc()):
                    $app_pct = $mo['total'] > 0 ? round($mo['approved']/$mo['total']*100) : 0;
                ?>
                <tr>
                    <td style="font-weight:600;"><?php echo htmlspecialchars($mo['label']); ?></td>
                    <td><?php echo $mo['total']; ?></td>
                    <td>
                        <div style="display:flex;align-items:center;gap:6px;">
                            <span style="color:#16a34a;font-weight:600;min-width:18px;"><?php echo $mo['approved']; ?></span>
                            <div class="pbar-wrap" style="width:50px;"><div class="pbar ok" style="width:<?php echo $app_pct; ?>%;"></div></div>
                            <span style="font-size:.7rem;color:var(--text-light);"><?php echo $app_pct; ?>%</span>
                        </div>
                    </td>
                    <td style="color:#dc2626;font-weight:600;"><?php echo $mo['rejected']; ?></td>
                    <td style="color:#0284c7;font-weight:600;"><?php echo $mo['pending']; ?></td>
                </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
            <?php else: ?>
            <div class="empty-st"><i class="fa-solid fa-calendar-xmark"></i><p>No monthly data for <?php echo $year_filter; ?>.</p></div>
            <?php endif; ?>
        </div>
    </div>

    <!-- By Department -->
    <div class="full-card">
        <div class="rcard-hdr">
            <div class="rcard-title"><i class="fa-solid fa-building"></i> By Department (<?php echo $year_filter; ?>)</div>
        </div>
        <?php if ($by_dept && $by_dept->num_rows > 0): ?>
        <div style="overflow-x:auto;">
        <table class="rt">
            <thead><tr><th>#</th><th>Department</th><th>School</th><th>Total</th><th>Approved</th><th>Rejected</th><th>Approval Rate</th></tr></thead>
            <tbody>
            <?php $dn = 1; while ($bd = $by_dept->fetch_assoc()):
                $ar = $bd['total'] > 0 ? round($bd['approved']/$bd['total']*100) : 0;
            ?>
            <tr>
                <td style="color:var(--text-light);"><?php echo $dn++; ?></td>
                <td style="font-weight:600;"><?php echo htmlspecialchars($bd['dept_name']??'Unknown'); ?></td>
                <td style="font-size:.8rem;color:var(--text-light);"><?php echo htmlspecialchars($bd['school']??''); ?></td>
                <td style="font-weight:700;"><?php echo $bd['total']; ?></td>
                <td style="color:#16a34a;font-weight:600;"><?php echo $bd['approved']; ?></td>
                <td style="color:#dc2626;font-weight:600;"><?php echo $bd['rejected']; ?></td>
                <td>
                    <div style="display:flex;align-items:center;gap:7px;">
                        <div class="pbar-wrap" style="width:70px;"><div class="pbar ok" style="width:<?php echo $ar; ?>%;"></div></div>
                        <span style="font-size:.8rem;font-weight:600;"><?php echo $ar; ?>%</span>
                    </div>
                </td>
            </tr>
            <?php endwhile; ?>
            </tbody>
        </table>
        </div>
        <?php else: ?>
        <div class="empty-st"><i class="fa-solid fa-inbox"></i><p>No department data for <?php echo $year_filter; ?>.</p></div>
        <?php endif; ?>
    </div>

    <!-- Recent Finance Activity -->
    <div class="full-card">
        <div class="rcard-hdr">
            <div class="rcard-title"><i class="fa-solid fa-clock-rotate-left"></i> Recent Finance Activity (<?php echo $year_filter; ?>)</div>
            <span style="font-size:.78rem;color:var(--text-light);">Last 20 processed</span>
        </div>
        <?php if ($recent_act && $recent_act->num_rows > 0): ?>
        <div style="overflow-x:auto;">
        <table class="rt">
            <thead><tr><th>Student</th><th>Document</th><th>Type</th><th>Department</th><th>Status</th><th>Processed</th></tr></thead>
            <tbody>
            <?php while ($ra = $recent_act->fetch_assoc()):
                $mc = $mod_cls[$ra['module_type']] ?? 'mod-bursary';
                $proc_date = $ra['finance_approved_at'] ?? $ra['rejected_at'] ?? $ra['updated_at'];
            ?>
            <tr>
                <td>
                    <div style="font-weight:600;"><?php echo htmlspecialchars($ra['student_name']); ?></div>
                    <div style="font-size:.73rem;color:var(--text-light);font-family:monospace;"><?php echo htmlspecialchars($ra['reg_number']); ?></div>
                </td>
                <td style="font-size:.85rem;"><?php echo htmlspecialchars($ra['title']); ?></td>
                <td><span class="mod-tag <?php echo $mc; ?>"><?php echo htmlspecialchars($ra['module_type']); ?></span></td>
                <td style="font-size:.82rem;"><?php echo htmlspecialchars($ra['dept_name']??'N/A'); ?></td>
                <td>
                    <?php if ($ra['status']==='Approved'): ?>
                    <span class="sp sp-ok"><i class="fa-solid fa-check"></i> Approved</span>
                    <?php elseif($ra['status']==='Rejected'): ?>
                    <span class="sp sp-rej"><i class="fa-solid fa-xmark"></i> Rejected</span>
                    <?php else: ?>
                    <span class="sp sp-oth"><?php echo htmlspecialchars($ra['status']); ?></span>
                    <?php endif; ?>
                </td>
                <td style="font-size:.8rem;color:var(--text-light);"><?php echo $proc_date ? date('M j, Y', strtotime($proc_date)) : '—'; ?></td>
            </tr>
            <?php endwhile; ?>
            </tbody>
        </table>
        </div>
        <?php else: ?>
        <div class="empty-st"><i class="fa-solid fa-inbox"></i><p>No processed documents found for <?php echo $year_filter; ?>.</p></div>
        <?php endif; ?>
    </div>

</main>
</div>
</body>
</html>
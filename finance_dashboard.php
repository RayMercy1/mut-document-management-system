<?php
/**
 * MUT DMS — Finance Office Dashboard
 * finance_dashboard.php
 */
session_start();
require_once 'db_config.php';
require_once 'mailer.php';

if (!isset($_SESSION['reg_number'])) { header("Location: login.php"); exit(); }

$user_role       = $_SESSION['role'];
$user_admin_role = $_SESSION['admin_role'] ?? 'none';
$reg_number      = $_SESSION['reg_number'];
$full_name       = $_SESSION['full_name'] ?? 'Finance Officer';
$current_view    = $_SESSION['current_admin_view'] ?? $user_admin_role;

$is_finance = ($user_role === 'admin' && $user_admin_role === 'finance')
           || ($user_role === 'super_admin' && $current_view === 'finance');

if (!$is_finance && $user_role !== 'super_admin') {
    header("Location: login.php"); exit();
}

// Handle Compose Email POST
$email_success = '';
$email_error   = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['compose_email'])) {
    $to_email = trim($_POST['to_email'] ?? '');
    $to_name  = trim($_POST['to_name']  ?? '');
    $subject  = trim($_POST['subject']  ?? '');
    $body     = trim($_POST['body']     ?? '');
    $att      = $_FILES['attachment']   ?? null;

    if (empty($to_email) || empty($subject) || empty($body)) {
        $email_error = 'Please fill in all required fields.';
    } elseif (!filter_var($to_email, FILTER_VALIDATE_EMAIL)) {
        $email_error = 'Invalid email address.';
    } else {
        $attachments = [];
        if ($att && $att['error'] === UPLOAD_ERR_OK) {
            $attachments[] = ['path' => $att['tmp_name'], 'name' => basename($att['name'])];
        }
        $html_body   = nl2br(htmlspecialchars($body));
        $mail_result = sendEmail($to_email, $to_name ?: $to_email, $subject, $html_body, $attachments);
        if ($mail_result['success']) {
            $email_success = "Email sent to " . htmlspecialchars($to_name ?: $to_email) . " (" . htmlspecialchars($to_email) . ").";
            logActivity($conn, $reg_number, 'FINANCE_EMAIL', "Email to {$to_email} – {$subject}");
            header("Location: finance_dashboard.php?success=email_sent");
            exit();
        } else {
            $email_error = 'Could not send: ' . $mail_result['error'];
        }
    }
}

$page          = $_GET['page']   ?? 'dashboard';
$search        = trim($_GET['search'] ?? '');
$filter_module = $_GET['module'] ?? 'all';
$filter_month  = $_GET['month']  ?? 'all';
$filter_status = ($page === 'all_documents') ? ($_GET['status'] ?? 'all') : 'pending';

$fin_modules = "'Resit','Retake','Special_Exam','Bursary','Fees'";

$stat_pending  = $conn->query("SELECT COUNT(*) c FROM documents WHERE status='Pending_Finance' AND module_type IN ({$fin_modules})")->fetch_assoc()['c'];
$stat_approved = $conn->query("SELECT COUNT(*) c FROM documents WHERE status='Approved' AND module_type IN ({$fin_modules}) AND finance_approved=1")->fetch_assoc()['c'];
$stat_rejected = $conn->query("SELECT COUNT(*) c FROM documents WHERE status='Rejected' AND module_type IN ({$fin_modules}) AND finance_rejection_reason IS NOT NULL")->fetch_assoc()['c'];
$stat_total    = $conn->query("SELECT COUNT(*) c FROM documents WHERE module_type IN ({$fin_modules})")->fetch_assoc()['c'];

// All-documents query
if ($page === 'all_documents') {
    $wp = ["d.module_type IN ({$fin_modules})"];
    if ($filter_status === 'approved') $wp[] = "d.status='Approved' AND d.finance_approved=1";
    elseif ($filter_status === 'rejected') $wp[] = "d.status='Rejected' AND d.finance_rejection_reason IS NOT NULL";
    elseif ($filter_status === 'pending')  $wp[] = "d.status='Pending_Finance'";
    if ($filter_module !== 'all' && $filter_module !== '') $wp[] = "d.module_type='" . $conn->real_escape_string($filter_module) . "'";
    // Filter by exam_month from resit_retake_forms (the month student applied for, not upload date)
    if (!empty($filter_month) && $filter_month !== 'all') $wp[] = "EXISTS (SELECT 1 FROM resit_retake_forms rrf WHERE rrf.document_id = d.id AND rrf.exam_month = '" . $conn->real_escape_string($filter_month) . "')";
    if (!empty($search)) { $sq=$conn->real_escape_string($search); $wp[]="(u.full_name LIKE '%{$sq}%' OR d.reg_number LIKE '%{$sq}%' OR d.title LIKE '%{$sq}%')"; }
    $all_docs = $conn->query("SELECT d.*,u.full_name AS student_name,u.email AS student_email,dept.dept_name FROM documents d JOIN users u ON d.reg_number=u.reg_number LEFT JOIN departments dept ON u.department_id=dept.id WHERE " . implode(' AND ',$wp) . " ORDER BY d.upload_date DESC");
}

$pending_docs = $conn->query("SELECT d.*,u.full_name AS student_name,u.email AS student_email,dept.dept_name FROM documents d JOIN users u ON d.reg_number=u.reg_number LEFT JOIN departments dept ON u.department_id=dept.id WHERE d.status='Pending_Finance' AND d.module_type IN ({$fin_modules}) ORDER BY d.upload_date ASC");

// Approved docs for dashboard section
$appr_filter_module = $_GET['appr_module'] ?? 'all';
$appr_filter_month  = $_GET['appr_month']  ?? 'all';
$appr_search        = trim($_GET['appr_search'] ?? '');
$appr_wp = ["d.status='Approved'","d.finance_approved=1","d.module_type IN ({$fin_modules})"];
if ($appr_filter_module !== 'all' && $appr_filter_module !== '') $appr_wp[] = "d.module_type='" . $conn->real_escape_string($appr_filter_module) . "'";
if (!empty($appr_filter_month) && $appr_filter_month !== 'all') $appr_wp[] = "EXISTS (SELECT 1 FROM resit_retake_forms rrf WHERE rrf.document_id=d.id AND rrf.exam_month='" . $conn->real_escape_string($appr_filter_month) . "')";
if (!empty($appr_search)) { $asq=$conn->real_escape_string($appr_search); $appr_wp[]="(u.full_name LIKE '%{$asq}%' OR d.reg_number LIKE '%{$asq}%' OR d.title LIKE '%{$asq}%')"; }
$appr_all_docs = $conn->query("SELECT d.*,u.full_name AS student_name,dept.dept_name FROM documents d JOIN users u ON d.reg_number=u.reg_number LEFT JOIN departments dept ON u.department_id=dept.id WHERE " . implode(' AND ',$appr_wp) . " ORDER BY d.upload_date DESC");
$appr_all_rows = [];
if ($appr_all_docs) { while ($r=$appr_all_docs->fetch_assoc()) $appr_all_rows[]=$r; }
$appr_preview   = array_slice($appr_all_rows, 0, 8);
$appr_remaining = array_slice($appr_all_rows, 8);
$appr_total     = count($appr_all_rows);
$show_all_appr  = isset($_GET['appr_show_all']);

$toast = null;
if (isset($_GET['success'])) {
    $tmap = ['finalised'=>['✅','Form finalised and emailed to student successfully!'],'bursary_approved'=>['✅','Bursary approved. Student notified by email.'],'bursary_rejected'=>['❌','Bursary rejected. Student notified by email.'],'fees_approved'=>['✅','Fee Adjustment approved. Student notified by email.'],'fees_rejected'=>['❌','Fee Adjustment rejected. Student notified by email.'],'email_sent'=>['✅','Email sent successfully to student.']];
    $toast = $tmap[$_GET['success']] ?? null;
}

$mod_cls_map = ['Resit'=>'mod-resit','Retake'=>'mod-retake','Special_Exam'=>'mod-special','Bursary'=>'mod-bursary','Fees'=>'mod-fees'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Finance Office | MUT DMS</title>
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
.logo-text h3{color:#fff;font-size:1rem;font-weight:700;line-height:1.2}
.logo-text span{font-size:.7rem;color:rgba(255,255,255,.5)}
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
.nav-badge{margin-left:auto;background:var(--fin);color:#fff;border-radius:20px;padding:2px 8px;font-size:.64rem;font-weight:700}
.sidebar-footer{padding:14px 16px;border-top:1px solid rgba(255,255,255,.08);margin-top:auto}
.user-card{display:flex;align-items:center;gap:10px;margin-bottom:10px}
.user-av{width:36px;height:36px;border-radius:9px;background:linear-gradient(135deg,var(--fin),var(--fin-dark));display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:.88rem;flex-shrink:0}
.user-info .name{color:#fff;font-size:.875rem;font-weight:600}
.user-info .role{color:rgba(255,255,255,.45);font-size:.7rem}
.btn-logout{display:flex;align-items:center;justify-content:center;gap:8px;width:100%;padding:10px;background:rgba(239,68,68,.18);color:#ef4444;border:none;border-radius:8px;font-size:.82rem;font-weight:600;cursor:pointer;transition:all .2s;text-decoration:none}
.btn-logout:hover{background:rgba(239,68,68,.3)}
.main-content{margin-left:272px;flex:1;padding:28px 30px;min-height:100vh}
.page-hdr{margin-bottom:24px}
.page-hdr h1{font-size:1.6rem;font-weight:800}
.page-hdr p{color:var(--text-light);margin-top:4px;font-size:.88rem}
.stats-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:24px}
.stat-card{background:var(--card);border:1px solid var(--border);border-radius:14px;padding:20px;display:flex;align-items:center;gap:14px;box-shadow:var(--shadow)}
.stat-ic{width:48px;height:48px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:1.2rem;flex-shrink:0}
.si-p{background:#e0f2fe;color:#0284c7}.si-a{background:#dcfce7;color:#16a34a}.si-r{background:#fee2e2;color:#dc2626}.si-t{background:#f3f4f6;color:#374151}
.stat-val{font-size:1.85rem;font-weight:800;line-height:1}
.stat-lbl{font-size:.75rem;color:var(--text-light);margin-top:3px}
.filter-bar{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:14px 18px;margin-bottom:18px;box-shadow:var(--shadow)}
.filter-bar form{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.f-select,.f-input{padding:7px 11px;border:1px solid var(--border);border-radius:7px;font-family:inherit;font-size:.83rem;background:#fff;color:var(--text)}
.f-select:focus,.f-input:focus{outline:none;border-color:var(--fin)}
.sw{position:relative;flex:1;min-width:160px}
.sw i{position:absolute;left:9px;top:50%;transform:translateY(-50%);color:var(--text-light);font-size:.82rem}
.sw input{width:100%;padding:7px 11px 7px 30px;border:1px solid var(--border);border-radius:7px;font-family:inherit;font-size:.83rem}
.sw input:focus{outline:none;border-color:var(--fin)}
.btn-filter{padding:7px 16px;background:var(--fin);color:#fff;border:none;border-radius:7px;font-size:.83rem;font-weight:600;cursor:pointer}
.btn-clear{padding:7px 12px;background:var(--bg);color:var(--text-light);border:1px solid var(--border);border-radius:7px;font-size:.83rem;text-decoration:none}
.status-tabs{display:flex;gap:7px;margin-bottom:18px;flex-wrap:wrap}
.stab{padding:6px 14px;border-radius:7px;border:1px solid var(--border);font-size:.8rem;font-weight:600;text-decoration:none;color:var(--text-light);background:var(--card);transition:all .2s}
.stab:hover,.stab.active{background:var(--fin);color:#fff;border-color:var(--fin)}
.stab.sa:hover,.stab.sa.active{background:#16a34a;border-color:#16a34a}
.stab.sr:hover,.stab.sr.active{background:var(--danger);border-color:var(--danger)}
.stab.sl:hover,.stab.sl.active{background:#374151;border-color:#374151}
.table-card{background:var(--card);border:1px solid var(--border);border-radius:14px;overflow:hidden;box-shadow:var(--shadow);margin-bottom:26px}
.table-hdr{padding:14px 18px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center}
.table-title{font-weight:700;font-size:.92rem;display:flex;align-items:center;gap:8px}
.table-title i{color:var(--fin)}
.tcount{font-size:.78rem;color:var(--text-light)}
table{width:100%;border-collapse:collapse}
th{font-size:.69rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--text-light);background:#f8fafc;padding:10px 14px;text-align:left;border-bottom:1px solid var(--border)}
td{padding:11px 14px;border-bottom:1px solid var(--border);font-size:.858rem;vertical-align:middle}
tr:last-child td{border-bottom:none}
tr:hover td{background:#fafafa}
.mod-tag{display:inline-block;padding:2px 8px;border-radius:11px;font-size:.7rem;font-weight:600}
.mod-resit{background:#dbeafe;color:#1d4ed8}.mod-retake{background:#ede9fe;color:#6d28d9}
.mod-special{background:#fef3c7;color:#92400e}.mod-bursary{background:#dcfce7;color:#166534}.mod-fees{background:#fee2e2;color:#991b1b}
.sp{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:18px;font-size:.7rem;font-weight:600}
.sp-pf{background:#e0f2fe;color:#0284c7}.sp-ok{background:#dcfce7;color:#166534}.sp-rej{background:#fee2e2;color:#991b1b}.sp-oth{background:#f3f4f6;color:#374151}
.act{display:flex;gap:6px;flex-wrap:nowrap}
.ba{padding:5px 10px;border:none;border-radius:6px;font-size:.75rem;font-weight:600;cursor:pointer;transition:all .2s;display:inline-flex;align-items:center;gap:4px;text-decoration:none;white-space:nowrap}
.bv{background:#f1f5f9;color:var(--text);border:1px solid var(--border)}.bv:hover{background:var(--border)}
.bapp{background:#dcfce7;color:#166534;border:1px solid #bbf7d0}.bapp:hover{background:#16a34a;color:#fff}
.brej{background:#fee2e2;color:#991b1b;border:1px solid #fca5a5}.brej:hover{background:#dc2626;color:#fff}
.bfin{background:var(--fin);color:#fff}.bfin:hover{background:var(--fin-dark)}
.empty-st{text-align:center;padding:50px 32px}
.empty-st i{font-size:2.4rem;color:var(--border);margin-bottom:12px;display:block}
.empty-st h3{font-weight:700;margin-bottom:5px;font-size:.95rem}
.empty-st p{color:var(--text-light);font-size:.85rem}
.compose-card{background:var(--card);border-radius:14px;box-shadow:var(--shadow);border:1px solid var(--border);margin-bottom:26px;overflow:hidden}
.compose-hdr{display:flex;justify-content:space-between;align-items:center;padding:16px 20px;border-bottom:1px solid var(--border)}
.compose-title{font-size:.95rem;font-weight:700;display:flex;align-items:center;gap:8px}
.compose-title i{color:var(--fin)}
.compose-body{padding:20px}
.c-row{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:12px}
.c-grp{margin-bottom:12px}
.c-grp label{display:block;font-size:.72rem;font-weight:600;color:var(--text-light);margin-bottom:4px;text-transform:uppercase;letter-spacing:.04em}
.c-in{width:100%;padding:8px 12px;border:1px solid var(--border);border-radius:7px;font-size:.87rem;font-family:inherit;color:var(--text);transition:border-color .2s}
.c-in:focus{outline:none;border-color:var(--fin);box-shadow:0 0 0 3px rgba(14,165,233,.1)}
.c-ta{width:100%;padding:8px 12px;border:1px solid var(--border);border-radius:7px;font-size:.87rem;font-family:inherit;color:var(--text);resize:vertical;min-height:115px;transition:border-color .2s}
.c-ta:focus{outline:none;border-color:var(--fin);box-shadow:0 0 0 3px rgba(14,165,233,.1)}
.btn-send{background:var(--fin);color:#fff;border:none;padding:9px 20px;border-radius:7px;font-size:.87rem;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:7px;transition:all .2s}
.btn-send:hover{background:var(--fin-dark);transform:translateY(-1px)}
.c-alert{padding:10px 14px;border-radius:8px;font-size:.84rem;font-weight:600;margin-bottom:12px;display:flex;align-items:center;gap:7px}
.c-ok{background:#d1fae5;color:#065f46;border:1px solid #6ee7b7}
.c-err{background:#fee2e2;color:#991b1b;border:1px solid #fca5a5}
.sug-box{position:absolute;top:100%;left:0;right:0;background:#fff;border:1px solid var(--border);border-top:none;border-radius:0 0 8px 8px;max-height:175px;overflow-y:auto;z-index:300;display:none;box-shadow:var(--shadow-lg)}
.sug-item{padding:8px 12px;cursor:pointer;font-size:.84rem;border-bottom:1px solid var(--border)}
.sug-item:last-child{border-bottom:none}
.sug-item:hover{background:#f0f9ff}
.sug-name{font-weight:600}
.sug-meta{font-size:.73rem;color:var(--text-light)}
.sug-wrap{position:relative}
.toast{position:fixed;top:24px;left:50%;transform:translateX(-50%);background:#fff;border-radius:12px;box-shadow:0 8px 30px rgba(0,0,0,.15);padding:14px 42px 14px 16px;display:flex;align-items:center;gap:11px;min-width:290px;z-index:9999;animation:slideD .32s ease forwards}
@keyframes slideD{from{opacity:0;transform:translateX(-50%) translateY(-14px)}to{opacity:1;transform:translateX(-50%) translateY(0)}}
.toast-close{position:absolute;top:9px;right:11px;background:none;border:none;cursor:pointer;color:#94a3b8;font-size:.95rem}
.toast-bar{position:absolute;bottom:0;left:0;height:3px;background:var(--fin);border-radius:0 0 12px 12px;animation:shrk 4.5s linear forwards}
@keyframes shrk{from{width:100%}to{width:0}}
</style>
</head>
<body>

<?php if ($toast): ?>
<div class="toast" id="theToast">
    <div style="font-size:1.5rem;flex-shrink:0;"><?php echo $toast[0]; ?></div>
    <div><div style="font-weight:700;font-size:.9rem;">Action Confirmed</div><div style="font-size:.82rem;color:#475569;"><?php echo htmlspecialchars($toast[1]); ?></div></div>
    <button class="toast-close" onclick="document.getElementById('theToast').remove()">✕</button>
    <div class="toast-bar"></div>
</div>
<script>setTimeout(()=>{const t=document.getElementById('theToast');if(t)t.remove();},5000);</script>
<?php endif; ?>

<?php if (!empty($email_success)): ?>
<div class="toast" id="emailToast">
    <div style="font-size:1.5rem;flex-shrink:0;">📧</div>
    <div><div style="font-weight:700;font-size:.9rem;">Email Sent Successfully</div><div style="font-size:.82rem;color:#475569;"><?php echo $email_success; ?></div></div>
    <button class="toast-close" onclick="document.getElementById('emailToast').remove()">✕</button>
    <div class="toast-bar"></div>
</div>
<script>setTimeout(()=>{const t=document.getElementById('emailToast');if(t)t.remove();},5000);</script>
<?php endif; ?>

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
        <a href="finance_dashboard.php" class="nav-item <?php echo $page==='dashboard'?'active':''; ?>">
            <i class="fa-solid fa-grid-2"></i> Dashboard
            <?php if ($stat_pending > 0): ?><span class="nav-badge"><?php echo $stat_pending; ?></span><?php endif; ?>
        </a>
        <a href="finance_dashboard.php?page=all_documents" class="nav-item <?php echo $page==='all_documents'?'active':''; ?>">
            <i class="fa-solid fa-folder-open"></i> All Documents
        </a>
        <a href="finance_reports.php" class="nav-item">
            <i class="fa-solid fa-chart-bar"></i> Reports
        </a>
        <a href="#composeSection" class="nav-item" onclick="document.getElementById('composeSection').scrollIntoView({behavior:'smooth'});return false;">
            <i class="fa-solid fa-envelope-open-text"></i> Compose Email
        </a>
        <div class="nav-gtitle" style="margin-top:8px;">Account</div>
        <a href="logout.php" class="nav-item"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
    </div>
    <div class="sidebar-footer">
        <div class="user-card">
            <div class="user-av"><?php echo strtoupper(substr($full_name,0,1)); ?></div>
            <div class="user-info">
                <div class="name"><?php echo htmlspecialchars($full_name); ?></div>
                <div class="role">Finance Officer</div>
            </div>
        </div>
    </div>
</aside>

<main class="main-content">

<?php if ($page === 'dashboard'): ?>
<div class="page-hdr">
    <h1><i class="fa-solid fa-building-columns" style="color:var(--fin);margin-right:8px;"></i>Finance Office Dashboard</h1>
    <p>Manage fee payments, bursary approvals and registration form finalisations.</p>
</div>

<div class="stats-grid">
    <div class="stat-card"><div class="stat-ic si-p"><i class="fa-solid fa-hourglass-half"></i></div><div><div class="stat-val"><?php echo $stat_pending; ?></div><div class="stat-lbl">Pending Action</div></div></div>
    <div class="stat-card"><div class="stat-ic si-a"><i class="fa-solid fa-check-double"></i></div><div><div class="stat-val"><?php echo $stat_approved; ?></div><div class="stat-lbl">Approved</div></div></div>
    <div class="stat-card"><div class="stat-ic si-r"><i class="fa-solid fa-xmark"></i></div><div><div class="stat-val"><?php echo $stat_rejected; ?></div><div class="stat-lbl">Rejected</div></div></div>
    <div class="stat-card"><div class="stat-ic si-t"><i class="fa-solid fa-folder"></i></div><div><div class="stat-val"><?php echo $stat_total; ?></div><div class="stat-lbl">Total Documents</div></div></div>
</div>

<div class="table-card">
    <div class="table-hdr">
        <div class="table-title"><i class="fa-solid fa-clock"></i> Pending Finance Action</div>
        <span class="tcount"><?php echo $pending_docs ? $pending_docs->num_rows : 0; ?> record(s)</span>
    </div>
    <?php if ($pending_docs && $pending_docs->num_rows > 0): ?>
    <div style="overflow-x:auto;"><table>
        <thead><tr><th>Document</th><th>Student</th><th>Department</th><th>Submitted</th><th>Actions</th></tr></thead>
        <tbody>
        <?php while ($d = $pending_docs->fetch_assoc()):
            $mod = $d['module_type'];
            $mc  = $mod_cls_map[$mod] ?? 'mod-bursary';
            $dw  = floor((time()-strtotime($d['upload_date']))/86400);
        ?>
        <tr>
            <td>
                <div style="font-weight:600;font-size:.87rem;"><?php echo htmlspecialchars($d['title']); ?></div>
                <span class="mod-tag <?php echo $mc; ?>"><?php echo htmlspecialchars($mod); ?></span>
                <?php if ($dw > 5): ?><span style="background:#fee2e2;color:#991b1b;padding:1px 6px;border-radius:9px;font-size:.67rem;font-weight:700;margin-left:4px;"><?php echo $dw; ?>d</span><?php endif; ?>
            </td>
            <td><div style="font-weight:600;"><?php echo htmlspecialchars($d['student_name']); ?></div><div style="font-size:.74rem;color:var(--text-light);font-family:monospace;"><?php echo htmlspecialchars($d['reg_number']); ?></div></td>
            <td style="font-size:.82rem;"><?php echo htmlspecialchars($d['dept_name']??'N/A'); ?></td>
            <td style="font-size:.79rem;color:var(--text-light);"><?php echo date('M j, Y', strtotime($d['upload_date'])); ?></td>
            <td><div class="act">
                <a href="view_form.php?id=<?php echo $d['id']; ?>" class="ba bv"><i class="fa-solid fa-eye"></i> View</a>
                <?php if (in_array($mod,['Resit','Retake','Special_Exam'])): ?>
                <button class="ba bfin" onclick="openFinalise(<?php echo $d['id']; ?>,'<?php echo htmlspecialchars(addslashes($d['student_name'])); ?>','<?php echo $mod; ?>')"><i class="fa-solid fa-check-double"></i> Finalise</button>
                <?php elseif ($mod==='Bursary'): ?>
                <button class="ba bapp" onclick="doAction(<?php echo $d['id']; ?>,'finance_approve_bursary')"><i class="fa-solid fa-check"></i> Approve</button>
                <button class="ba brej" onclick="openReject(<?php echo $d['id']; ?>,'finance_reject_bursary')"><i class="fa-solid fa-times"></i> Reject</button>
                <?php elseif ($mod==='Fees'): ?>
                <button class="ba bapp" onclick="doAction(<?php echo $d['id']; ?>,'finance_approve_fees')"><i class="fa-solid fa-check"></i> Approve</button>
                <button class="ba brej" onclick="openReject(<?php echo $d['id']; ?>,'finance_reject_fees')"><i class="fa-solid fa-times"></i> Reject</button>
                <?php endif; ?>
            </div></td>
        </tr>
        <?php endwhile; ?>
        </tbody>
    </table></div>
    <?php else: ?>
    <div class="empty-st"><i class="fa-solid fa-check-circle" style="color:#22c55e;"></i><h3>All Caught Up!</h3><p>No documents awaiting your action.</p></div>
    <?php endif; ?>
</div>

<!-- ══════ APPROVED DOCUMENTS SECTION ══════ -->
<div class="table-card" style="margin-top:0;">
    <!-- Filter bar -->
    <div style="padding:14px 18px;border-bottom:1px solid var(--border);background:#f8fafc;border-radius:14px 14px 0 0;">
        <form method="GET" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
            <input type="hidden" name="page" value="dashboard">
            <?php if($show_all_appr): ?><input type="hidden" name="appr_show_all" value="1"><?php endif; ?>
            <div style="font-weight:700;font-size:.92rem;display:flex;align-items:center;gap:8px;margin-right:6px;">
                <i class="fa-solid fa-circle-check" style="color:#22c55e;"></i> Approved Documents
                <span style="background:#dcfce7;color:#166534;border-radius:20px;padding:2px 9px;font-size:.7rem;font-weight:700;"><?php echo $appr_total; ?></span>
            </div>
            <select name="appr_module" class="f-select" onchange="this.form.submit()">
                <option value="all" <?php echo $appr_filter_module==='all'?'selected':''; ?>>All Types</option>
                <?php foreach(['Resit','Retake','Special_Exam','Bursary','Fees'] as $mt): ?>
                <option value="<?php echo $mt; ?>" <?php echo $appr_filter_module===$mt?'selected':''; ?>><?php echo $mt; ?></option>
                <?php endforeach; ?>
            </select>
            <select name="appr_month" class="f-select" onchange="this.form.submit()">
                <option value="all">All Months</option>
                <option value="April"    <?php echo $appr_filter_month==='April'   ?'selected':''; ?>>April</option>
                <option value="August"   <?php echo $appr_filter_month==='August'  ?'selected':''; ?>>August</option>
                <option value="December" <?php echo $appr_filter_month==='December'?'selected':''; ?>>December</option>
            </select>
            <div class="sw"><i class="fa-solid fa-search"></i><input type="text" name="appr_search" placeholder="Search name, reg no…" value="<?php echo htmlspecialchars($appr_search); ?>"></div>
            <button type="submit" class="btn-filter"><i class="fa-solid fa-filter"></i> Filter</button>
            <?php if($appr_filter_module!=='all'||$appr_filter_month!=='all'||!empty($appr_search)): ?>
            <a href="?page=dashboard" class="btn-clear">Clear</a>
            <?php endif; ?>
            <button type="button" onclick="printApprovedList()" class="btn-filter" style="background:#475569;margin-left:auto;"><i class="fa-solid fa-print"></i> Print List</button>
        </form>
    </div>

    <?php if (!empty($appr_all_rows)): ?>
    <div style="overflow-x:auto;" id="approvedTableWrap">
    <table id="approvedTable">
        <thead><tr><th>#</th><th>Document</th><th>Student</th><th>Department</th><th>Type</th><th>Approved Date</th><th>Action</th></tr></thead>
        <tbody>
        <?php foreach($appr_preview as $i=>$d):
            $mc = $mod_cls_map[$d['module_type']] ?? 'mod-bursary';
            $approved_date = !empty($d['finance_approved_at']) ? date('M j, Y', strtotime($d['finance_approved_at'])) : date('M j, Y', strtotime($d['upload_date']));
        ?>
        <tr>
            <td style="color:var(--text-light);font-size:.78rem;"><?php echo $i+1; ?></td>
            <td><div style="font-weight:600;font-size:.87rem;"><?php echo htmlspecialchars($d['title']); ?></div></td>
            <td><div style="font-weight:600;"><?php echo htmlspecialchars($d['student_name']); ?></div><div style="font-size:.74rem;color:var(--text-light);font-family:monospace;"><?php echo htmlspecialchars($d['reg_number']); ?></div></td>
            <td style="font-size:.82rem;"><?php echo htmlspecialchars($d['dept_name']??'N/A'); ?></td>
            <td><span class="mod-tag <?php echo $mc; ?>"><?php echo htmlspecialchars($d['module_type']); ?></span></td>
            <td style="font-size:.79rem;color:var(--text-light);"><?php echo $approved_date; ?></td>
            <td><a href="view_form.php?id=<?php echo $d['id']; ?>" class="ba bv"><i class="fa-solid fa-eye"></i> View</a></td>
        </tr>
        <?php endforeach; ?>

        <?php if(!empty($appr_remaining)): ?>
        <!-- Hidden extra rows shown when "View All" clicked -->
        <tr id="apprViewAllRow" style="<?php echo $show_all_appr?'display:none':''; ?>">
            <td colspan="7" style="text-align:center;padding:14px;">
                <button onclick="showAllApproved()" style="background:none;border:none;color:var(--fin);font-weight:700;font-size:.88rem;cursor:pointer;display:inline-flex;align-items:center;gap:6px;">
                    <i class="fa-solid fa-chevron-down"></i> View All <?php echo $appr_total; ?> Approved Documents
                </button>
            </td>
        </tr>
        <?php foreach($appr_remaining as $i=>$d):
            $mc = $mod_cls_map[$d['module_type']] ?? 'mod-bursary';
            $approved_date = !empty($d['finance_approved_at']) ? date('M j, Y', strtotime($d['finance_approved_at'])) : date('M j, Y', strtotime($d['upload_date']));
        ?>
        <tr class="appr-extra" style="<?php echo $show_all_appr?'':'display:none'; ?>">
            <td style="color:var(--text-light);font-size:.78rem;"><?php echo $i+9; ?></td>
            <td><div style="font-weight:600;font-size:.87rem;"><?php echo htmlspecialchars($d['title']); ?></div></td>
            <td><div style="font-weight:600;"><?php echo htmlspecialchars($d['student_name']); ?></div><div style="font-size:.74rem;color:var(--text-light);font-family:monospace;"><?php echo htmlspecialchars($d['reg_number']); ?></div></td>
            <td style="font-size:.82rem;"><?php echo htmlspecialchars($d['dept_name']??'N/A'); ?></td>
            <td><span class="mod-tag <?php echo $mc; ?>"><?php echo htmlspecialchars($d['module_type']); ?></span></td>
            <td style="font-size:.79rem;color:var(--text-light);"><?php echo $approved_date; ?></td>
            <td><a href="view_form.php?id=<?php echo $d['id']; ?>" class="ba bv"><i class="fa-solid fa-eye"></i> View</a></td>
        </tr>
        <?php endforeach; ?>
        <?php if($show_all_appr): ?>
        <tr>
            <td colspan="7" style="text-align:center;padding:12px;">
                <a href="?page=dashboard<?php echo ($appr_filter_module!=='all'?'&appr_module='.urlencode($appr_filter_module):'').($appr_filter_month!=='all'?'&appr_month='.urlencode($appr_filter_month):'').(!empty($appr_search)?'&appr_search='.urlencode($appr_search):''); ?>" style="color:var(--text-light);font-size:.83rem;text-decoration:none;font-weight:600;"><i class="fa-solid fa-chevron-up"></i> Show Less</a>
            </td>
        </tr>
        <?php endif; ?>
        <?php endif; ?>
        </tbody>
    </table></div>
    <?php else: ?>
    <div class="empty-st"><i class="fa-solid fa-circle-check"></i><h3>No Approved Documents</h3><p>No approved documents match your filters.</p></div>
    <?php endif; ?>
</div>

<?php elseif ($page === 'all_documents'): ?>
<div class="page-hdr">
    <h1><i class="fa-solid fa-folder-open" style="color:var(--fin);margin-right:8px;"></i>All Documents</h1>
    <p>View, search and filter all documents handled by Finance Office.</p>
</div>

<div class="status-tabs">
    <?php
    $stabs = ['all'=>['All','sl',$stat_total],'pending'=>['Pending','',$stat_pending],'approved'=>['Approved','sa',$stat_approved],'rejected'=>['Rejected','sr',$stat_rejected]];
    foreach ($stabs as $sv=>[$sl,$sc,$sn]):
        $ac = ($filter_status===$sv)?'active':'';
        $qp = http_build_query(['page'=>'all_documents','status'=>$sv,'module'=>$filter_module,'month'=>$filter_month,'search'=>$search]);
    ?>
    <a href="?<?php echo $qp; ?>" class="stab <?php echo $sc; ?> <?php echo $ac; ?>"><?php echo $sl; ?> <span style="background:rgba(255,255,255,.25);border-radius:9px;padding:1px 6px;font-size:.7rem;margin-left:3px;"><?php echo $sn; ?></span></a>
    <?php endforeach; ?>
</div>

<div class="filter-bar">
    <form method="GET">
        <input type="hidden" name="page" value="all_documents">
        <input type="hidden" name="status" value="<?php echo htmlspecialchars($filter_status); ?>">
        <select name="module" class="f-select" onchange="this.form.submit()">
            <option value="all" <?php echo $filter_module==='all'?'selected':''; ?>>All Types</option>
            <?php foreach(['Resit','Retake','Special_Exam','Bursary','Fees'] as $mt): ?>
            <option value="<?php echo $mt; ?>" <?php echo $filter_module===$mt?'selected':''; ?>><?php echo $mt; ?></option>
            <?php endforeach; ?>
        </select>
        <select name="month" class="f-select" onchange="this.form.submit()">
            <option value="all">All Months</option>
            <option value="April"    <?php echo $filter_month === 'April'    ? 'selected' : ''; ?>>April</option>
            <option value="August"   <?php echo $filter_month === 'August'   ? 'selected' : ''; ?>>August</option>
            <option value="December" <?php echo $filter_month === 'December' ? 'selected' : ''; ?>>December</option>
        </select>
        <div class="sw"><i class="fa-solid fa-search"></i><input type="text" name="search" placeholder="Search name, reg no…" value="<?php echo htmlspecialchars($search); ?>"></div>
        <button type="submit" class="btn-filter"><i class="fa-solid fa-filter"></i> Filter</button>
        <a href="?page=all_documents" class="btn-clear">Clear</a>
    </form>
</div>

<div class="table-card">
    <div class="table-hdr">
        <div class="table-title"><i class="fa-solid fa-file-lines"></i> <?php $tl=['all'=>'All Finance Documents','pending'=>'Pending','approved'=>'Approved Documents','rejected'=>'Rejected Documents']; echo $tl[$filter_status]??'Documents'; ?></div>
        <span class="tcount"><?php echo ($all_docs?$all_docs->num_rows:0); ?> record(s)</span>
    </div>
    <?php if ($all_docs && $all_docs->num_rows > 0): ?>
    <div style="overflow-x:auto;"><table>
        <thead><tr><th>Document</th><th>Student</th><th>Department</th><th>Submitted</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
        <?php while ($d = $all_docs->fetch_assoc()):
            $mod = $d['module_type'];
            $mc  = $mod_cls_map[$mod] ?? 'mod-bursary';
            $st  = $d['status'];
            if ($st==='Pending_Finance'){$sc='sp-pf';$sl2='Pending Finance';}
            elseif($st==='Approved'){$sc='sp-ok';$sl2='Approved';}
            elseif($st==='Rejected'){$sc='sp-rej';$sl2='Rejected';}
            else{$sc='sp-oth';$sl2=str_replace('_',' ',$st);}
        ?>
        <tr>
            <td><div style="font-weight:600;font-size:.87rem;"><?php echo htmlspecialchars($d['title']); ?></div><span class="mod-tag <?php echo $mc; ?>"><?php echo htmlspecialchars($mod); ?></span></td>
            <td><div style="font-weight:600;"><?php echo htmlspecialchars($d['student_name']); ?></div><div style="font-size:.74rem;color:var(--text-light);font-family:monospace;"><?php echo htmlspecialchars($d['reg_number']); ?></div></td>
            <td style="font-size:.82rem;"><?php echo htmlspecialchars($d['dept_name']??'N/A'); ?></td>
            <td style="font-size:.79rem;color:var(--text-light);"><?php echo date('M j, Y', strtotime($d['upload_date'])); ?></td>
            <td><span class="sp <?php echo $sc; ?>"><?php echo $sl2; ?></span></td>
            <td><div class="act">
                <a href="view_form.php?id=<?php echo $d['id']; ?>" class="ba bv"><i class="fa-solid fa-eye"></i> View</a>
                <?php if ($st==='Pending_Finance'): ?>
                    <?php if(in_array($mod,['Resit','Retake','Special_Exam'])): ?>
                    <button class="ba bfin" onclick="openFinalise(<?php echo $d['id']; ?>,'<?php echo htmlspecialchars(addslashes($d['student_name'])); ?>','<?php echo $mod; ?>')"><i class="fa-solid fa-check-double"></i> Finalise</button>
                    <?php elseif($mod==='Bursary'): ?>
                    <button class="ba bapp" onclick="doAction(<?php echo $d['id']; ?>,'finance_approve_bursary')"><i class="fa-solid fa-check"></i> Approve</button>
                    <button class="ba brej" onclick="openReject(<?php echo $d['id']; ?>,'finance_reject_bursary')"><i class="fa-solid fa-times"></i> Reject</button>
                    <?php elseif($mod==='Fees'): ?>
                    <button class="ba bapp" onclick="doAction(<?php echo $d['id']; ?>,'finance_approve_fees')"><i class="fa-solid fa-check"></i> Approve</button>
                    <button class="ba brej" onclick="openReject(<?php echo $d['id']; ?>,'finance_reject_fees')"><i class="fa-solid fa-times"></i> Reject</button>
                    <?php endif; ?>
                <?php endif; ?>
            </div></td>
        </tr>
        <?php endwhile; ?>
        </tbody>
    </table></div>
    <?php else: ?>
    <div class="empty-st"><i class="fa-solid fa-inbox"></i><h3>No Documents Found</h3><p>No documents match your current filters.</p></div>
    <?php endif; ?>
</div>

<?php endif; // end page ?>

<!-- COMPOSE EMAIL -->
<div class="compose-card" id="composeSection">
    <div class="compose-hdr">
        <div class="compose-title"><i class="fa-solid fa-envelope-open-text"></i> Compose Email to Student</div>
    </div>
    <div class="compose-body">
        <?php if(!empty($email_error)): ?><div class="c-alert c-err"><i class="fa-solid fa-circle-exclamation"></i> <?php echo htmlspecialchars($email_error); ?></div><?php endif; ?>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="compose_email" value="1">
            <input type="hidden" name="to_name" id="hiddenToName">
            <div class="c-row">
                <div class="c-grp">
                    <label>Search Student <span style="color:var(--danger)">*</span></label>
                    <div class="sug-wrap">
                        <input type="text" id="studentSearch" class="c-in" placeholder="Type name or reg number…" autocomplete="off" oninput="searchStudents(this.value)">
                        <div class="sug-box" id="studentSuggestions"></div>
                    </div>
                </div>
                <div class="c-grp">
                    <label>Recipient Email <span style="color:var(--danger)">*</span></label>
                    <input type="email" name="to_email" id="toEmail" class="c-in" placeholder="Auto-filled or type manually" value="<?php echo htmlspecialchars($_POST['to_email']??''); ?>" required>
                </div>
            </div>
            <div class="c-grp">
                <label>Subject <span style="color:var(--danger)">*</span></label>
                <input type="text" name="subject" class="c-in" placeholder="e.g. Bursary Approval – <?php echo date('F Y'); ?>" value="<?php echo htmlspecialchars($_POST['subject']??''); ?>" required>
            </div>
            <div class="c-grp">
                <label>Message <span style="color:var(--danger)">*</span></label>
                <textarea name="body" class="c-ta" placeholder="Write your message here…" required><?php echo htmlspecialchars($_POST['body']??''); ?></textarea>
            </div>
            <div class="c-grp">
                <label>Attach Document <span style="font-weight:400;text-transform:none;font-size:.72rem;">(optional)</span></label>
                <input type="file" name="attachment" class="c-in" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png">
            </div>
            <button type="submit" class="btn-send"><i class="fa-solid fa-paper-plane"></i> Send Email</button>
        </form>
    </div>
</div>

</main>
</div>

<!-- Finalise Modal -->
<div id="finaliseModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:14px;padding:28px;width:450px;max-width:95vw;font-family:'Inter',sans-serif;">
        <h3 style="margin-bottom:5px;font-size:1rem;"><i class="fa-solid fa-check-double" style="color:var(--fin);"></i> Finalise Application</h3>
        <p style="color:var(--text-light);font-size:.83rem;margin-bottom:16px;" id="finaliseStudentName"></p>
        <form method="POST" action="process_approval.php">
            <input type="hidden" name="action" value="finance_finalise">
            <input type="hidden" name="doc_id" id="finaliseDocId">
            <label style="font-size:.8rem;font-weight:600;display:block;margin-bottom:4px;">Amount Paid</label>
            <input type="text" name="amount_paid" id="finaliseAmount" style="width:100%;padding:8px 12px;border:2px solid var(--border);border-radius:7px;font-family:'Inter',sans-serif;font-size:.88rem;margin-bottom:14px;">
            <p style="font-size:.78rem;color:var(--text-light);margin-bottom:14px;"><i class="fa-solid fa-circle-info"></i> The completed form will be emailed directly to the student.</p>
            <div style="display:flex;gap:9px;">
                <button type="submit" style="flex:1;padding:9px;background:var(--fin);color:#fff;border:none;border-radius:7px;font-weight:700;cursor:pointer;font-size:.86rem;font-family:'Inter',sans-serif;"><i class="fa-solid fa-paper-plane"></i> Finalise &amp; Email Student</button>
                <button type="button" onclick="document.getElementById('finaliseModal').style.display='none'" style="padding:9px 14px;background:#f1f5f9;border:1px solid var(--border);border-radius:7px;cursor:pointer;font-weight:600;font-family:'Inter',sans-serif;">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Reject Modal -->
<div id="rejectModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:14px;padding:28px;width:450px;max-width:95vw;font-family:'Inter',sans-serif;">
        <h3 style="margin-bottom:12px;font-size:1rem;"><i class="fa-solid fa-times-circle" style="color:var(--danger);"></i> Reject Application</h3>
        <form method="POST" action="process_approval.php">
            <input type="hidden" name="action" id="rejectAction">
            <input type="hidden" name="doc_id" id="rejectDocId">
            <label style="font-size:.8rem;font-weight:600;display:block;margin-bottom:4px;">Reason <span style="color:var(--danger);">*</span></label>
            <textarea name="reason" required rows="4" style="width:100%;padding:8px 12px;border:2px solid var(--border);border-radius:7px;font-family:'Inter',sans-serif;font-size:.86rem;margin-bottom:14px;resize:vertical;" placeholder="Provide a clear reason that will be emailed to the student…"></textarea>
            <div style="display:flex;gap:9px;">
                <button type="submit" style="flex:1;padding:9px;background:var(--danger);color:#fff;border:none;border-radius:7px;font-weight:700;cursor:pointer;font-size:.86rem;font-family:'Inter',sans-serif;"><i class="fa-solid fa-paper-plane"></i> Submit Rejection</button>
                <button type="button" onclick="document.getElementById('rejectModal').style.display='none'" style="padding:9px 14px;background:#f1f5f9;border:1px solid var(--border);border-radius:7px;cursor:pointer;font-weight:600;font-family:'Inter',sans-serif;">Cancel</button>
            </div>
        </form>
    </div>
</div>

<form id="quickForm" method="POST" action="process_approval.php" style="display:none;">
    <input type="hidden" name="action" id="qfAction">
    <input type="hidden" name="doc_id" id="qfDocId">
</form>

<script>
function openFinalise(id,name,mod){document.getElementById('finaliseDocId').value=id;document.getElementById('finaliseStudentName').textContent='Student: '+name;const d={Resit:'KSh 800',Retake:'KSh 10,023',Special_Exam:'KSh 800'};document.getElementById('finaliseAmount').value=d[mod]||'';document.getElementById('finaliseModal').style.display='flex';}
function openReject(id,action){document.getElementById('rejectDocId').value=id;document.getElementById('rejectAction').value=action;document.getElementById('rejectModal').style.display='flex';}
function showAllApproved(){
    document.querySelectorAll('.appr-extra').forEach(r=>r.style.display='');
    document.getElementById('apprViewAllRow').style.display='none';
}
function printApprovedList(){
    var rows = Array.from(document.querySelectorAll('#approvedTable tbody tr'))
        .filter(r=>r.style.display!=='none' && !r.id);
    var html='<html><head><title>Approved Documents – Finance Office</title><style>'
        +'body{font-family:Arial,sans-serif;font-size:11pt;padding:20px}'
        +'h2{text-align:center;margin-bottom:4px}p.sub{text-align:center;color:#555;margin-bottom:16px;font-size:9pt}'
        +'table{width:100%;border-collapse:collapse}th,td{border:1px solid #000;padding:6px 8px;text-align:left;font-size:9.5pt}'
        +'th{background:#d9d9d9;font-weight:bold}tr:nth-child(even) td{background:#f5f5f5}'
        +'@media print{@page{margin:15mm}}'
        +'</style></head><body>'
        +'<h2>MURANG\'A UNIVERSITY OF TECHNOLOGY</h2>'
        +'<p class="sub">Finance Office – Approved Documents List &nbsp;|&nbsp; Printed: '+new Date().toLocaleDateString('en-GB',{day:'2-digit',month:'long',year:'numeric'})+'</p>'
        +'<table><thead><tr><th>#</th><th>Document</th><th>Student</th><th>Department</th><th>Type</th><th>Date Approved</th></tr></thead><tbody>';
    rows.forEach((r,i)=>{
        var cells=r.querySelectorAll('td');
        if(cells.length<6)return;
        html+='<tr><td>'+(i+1)+'</td>'
            +'<td>'+cells[1].innerText.trim()+'</td>'
            +'<td>'+cells[2].innerText.trim()+'</td>'
            +'<td>'+cells[3].innerText.trim()+'</td>'
            +'<td>'+cells[4].innerText.trim()+'</td>'
            +'<td>'+cells[5].innerText.trim()+'</td></tr>';
    });
    html+='</tbody></table></body></html>';
    var w=window.open('','_blank');w.document.write(html);w.document.close();w.focus();w.print();
}
function doAction(id,action){if(!confirm('Are you sure you want to proceed?'))return;document.getElementById('qfDocId').value=id;document.getElementById('qfAction').value=action;document.getElementById('quickForm').submit();}
['finaliseModal','rejectModal'].forEach(function(mid){document.getElementById(mid).addEventListener('click',function(e){if(e.target===this)this.style.display='none';});});

const allStudents=<?php $st=$conn->query("SELECT full_name,reg_number,email FROM users WHERE role='student' AND email!='' ORDER BY full_name");$arr=[];while($r=$st->fetch_assoc())$arr[]=$r;echo json_encode($arr); ?>;
function searchStudents(val){const box=document.getElementById('studentSuggestions');if(val.length<2){box.style.display='none';return;}const v=val.toLowerCase();const m=allStudents.filter(s=>s.full_name.toLowerCase().includes(v)||s.reg_number.toLowerCase().includes(v)).slice(0,8);if(!m.length){box.style.display='none';return;}box.innerHTML=m.map(s=>`<div class="sug-item" onclick="selectStudent('${s.full_name.replace(/'/g,"\\'")}','${s.email}')"><div class="sug-name">${s.full_name}</div><div class="sug-meta">${s.reg_number} &bull; ${s.email}</div></div>`).join('');box.style.display='block';}
function selectStudent(name,email){document.getElementById('studentSearch').value=name;document.getElementById('toEmail').value=email;document.getElementById('hiddenToName').value=name;document.getElementById('studentSuggestions').style.display='none';}
document.addEventListener('click',e=>{if(!e.target.closest('.sug-wrap'))document.getElementById('studentSuggestions').style.display='none';});
</script>
</body>
</html>

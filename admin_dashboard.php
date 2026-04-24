<?php
session_start();
require_once 'db_config.php';

if (!isset($_SESSION['reg_number']) || ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'super_admin')) {
    header("Location: login.php");
    exit();
}

$reg_number = $_SESSION['reg_number'];
$user_role  = $_SESSION['role'];
$full_name  = $_SESSION['full_name'] ?? 'Admin';

// ── VIEW SELECTION (Super Admin) ──
$current_view  = $_SESSION['current_admin_view'] ?? null;
$selected_dept = $_SESSION['selected_department'] ?? null;

if (isset($_GET['set_view']) && $user_role === 'super_admin') {
    $view = $_GET['set_view'];

    if (in_array($view, ['cod', 'dean', 'registrar', 'finance', 'dvc_arsa'])) {

        // COD/Dean need department/school – ask first
        if ($view === 'cod' && !isset($_GET['dept'])) {
            $_SESSION['pending_view'] = $view;
            header("Location: admin_dashboard.php?select_dept=1");
            exit();
        }
        if ($view === 'dean' && !isset($_GET['school'])) {
            $_SESSION['pending_view'] = $view;
            header("Location: admin_dashboard.php?select_school=1");
            exit();
        }

        $_SESSION['current_admin_view'] = $view;
        if (isset($_GET['dept']))   $_SESSION['selected_department'] = intval($_GET['dept']);
        if (isset($_GET['school'])) $_SESSION['selected_school']     = $_GET['school'];

        // ── Redirect directly to the role's dashboard ──
        switch ($view) {
            case 'cod':
                header("Location: cod_dashboard.php?dept=" . ($_SESSION['selected_department'] ?? ''));
                exit();
            case 'dean':
                header("Location: dean_dashboard.php?school=" . urlencode($_SESSION['selected_school'] ?? ''));
                exit();
            case 'registrar':
                header("Location: registrar_dashboard.php");
                exit();
            case 'finance':
                header("Location: finance_dashboard.php");
                exit();
            case 'dvc_arsa':
                header("Location: admin_dashboard.php");
                exit();
        }
    }
}

// Dept selection modal for COD
if (isset($_GET['select_dept']) && $user_role === 'super_admin') {
    $show_dept_modal  = true;
    $modal_mode       = 'dept';
    $pending_view     = $_SESSION['pending_view'] ?? 'cod';
} elseif (isset($_GET['select_school']) && $user_role === 'super_admin') {
    $show_dept_modal  = true;
    $modal_mode       = 'school';
    $pending_view     = $_SESSION['pending_view'] ?? 'dean';
} else {
    $show_dept_modal = false;
    $modal_mode      = '';
    $pending_view    = '';
}

// POST: COD login by reg number + password
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['select_department'])) {
    $cod_reg      = trim($_POST['cod_reg_number'] ?? '');
    $cod_password = $_POST['cod_password'] ?? '';
    $view         = $_POST['view_type'];

    if ($cod_reg !== '' && $cod_password !== '') {
        $stmt = $conn->prepare("SELECT * FROM users WHERE reg_number = ? AND admin_role = 'cod' AND is_active = 1");
        $stmt->bind_param("s", $cod_reg);
        $stmt->execute();
        $cod_user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($cod_user && password_verify($cod_password, $cod_user['password'] ?? $cod_user['password_hash'] ?? '')) {
            $_SESSION['current_admin_view']   = $view;
            $_SESSION['selected_department']  = $cod_user['department_id'];
            $_SESSION['cod_logged_in_name']   = $cod_user['full_name'];
            $_SESSION['cod_logged_in_reg']    = $cod_user['reg_number'];
            unset($_SESSION['pending_view']);
            header("Location: cod_dashboard.php?dept=" . $cod_user['department_id']);
            exit();
        } else {
            $_SESSION['modal_error'] = 'cod_no_user';
            header("Location: admin_dashboard.php?select_dept=1");
            exit();
        }
    } else {
        $_SESSION['modal_error'] = 'cod_no_user';
        header("Location: admin_dashboard.php?select_dept=1");
        exit();
    }
}

// POST: Dean login by reg number + password
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['select_school'])) {
    $dean_reg      = trim($_POST['dean_reg_number'] ?? '');
    $dean_password = $_POST['dean_password'] ?? '';
    $view          = $_POST['view_type'];

    if ($dean_reg !== '' && $dean_password !== '') {
        // Fetch dean user — school is stored directly on users table
        $stmt = $conn->prepare("SELECT * FROM users WHERE reg_number = ? AND admin_role = 'dean' AND is_active = 1");
        $stmt->bind_param("s", $dean_reg);
        $stmt->execute();
        $dean_user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($dean_user && password_verify($dean_password, $dean_user['password'] ?? $dean_user['password_hash'] ?? '')) {
            // School is stored directly in users.school column for Deans
            $dean_school = $dean_user['school'] ?? null;

            // Fallback: if school column empty, try deriving from department
            if (empty($dean_school) && !empty($dean_user['department_id'])) {
                $sq = $conn->prepare("SELECT school FROM departments WHERE id = ?");
                $sq->bind_param("i", $dean_user['department_id']);
                $sq->execute();
                $dean_school = $sq->get_result()->fetch_assoc()['school'] ?? null;
            }

            if (empty($dean_school)) {
                $_SESSION['modal_error'] = 'dean_no_dept';
                header("Location: admin_dashboard.php?select_school=1");
                exit();
            }

            $_SESSION['current_admin_view']    = $view;
            $_SESSION['selected_school']       = $dean_school;
            $_SESSION['dean_logged_in_name']   = $dean_user['full_name'];
            $_SESSION['dean_logged_in_reg']    = $dean_user['reg_number'];
            unset($_SESSION['pending_view']);
            header("Location: dean_dashboard.php?school=" . urlencode($dean_school));
            exit();
        } else {
            $_SESSION['modal_error'] = 'dean_no_user';
            header("Location: admin_dashboard.php?select_school=1");
            exit();
        }
    } else {
        $_SESSION['modal_error'] = 'dean_no_user';
        header("Location: admin_dashboard.php?select_school=1");
        exit();
    }
}


// Clear view
if (isset($_GET['clear_view'])) {
    unset($_SESSION['current_admin_view'], $_SESSION['selected_department'],
          $_SESSION['selected_school'], $_SESSION['pending_view'],
          $_SESSION['cod_logged_in_name'], $_SESSION['cod_logged_in_reg'],
          $_SESSION['dean_logged_in_name'], $_SESSION['dean_logged_in_reg'],
          $_SESSION['finance_logged_in_name']);
    header("Location: admin_dashboard.php");
    exit();
}

// Read and clear modal errors
$modal_error = $_SESSION['modal_error'] ?? '';
unset($_SESSION['modal_error']);

// Fetch departments + schools
$deptResult = $conn->query("SELECT * FROM departments ORDER BY school, dept_name");
$schools    = [];
$departments = [];
$dept_name   = '';

while ($row = $deptResult->fetch_assoc()) {
    $departments[] = $row;
    $schools[$row['school']][] = $row;
    if ($selected_dept && $row['id'] == $selected_dept) $dept_name = $row['dept_name'];
}

// Unique school list
$school_names = array_keys($schools);

// ── DVC: documents pending DVC review ──
$dvc_pending = null;
$dvc_all_docs = null;
$dvc_show_all = isset($_GET['show']) && $_GET['show'] === 'all_docs';
if ($current_view === 'dvc_arsa') {
    $dvcStmt = $conn->prepare("SELECT d.*, u.full_name, u.reg_number as student_reg, dept.dept_name
        FROM documents d JOIN users u ON d.reg_number = u.reg_number
        LEFT JOIN departments dept ON u.department_id = dept.id
        WHERE d.status = 'Pending_DVC'
        ORDER BY d.upload_date DESC");
    $dvcStmt->execute();
    $dvc_pending = $dvcStmt->get_result();

    if ($dvc_show_all) {
        $dvcAllStmt = $conn->prepare("SELECT d.*, u.full_name, u.reg_number as student_reg, dept.dept_name
            FROM documents d JOIN users u ON d.reg_number = u.reg_number
            LEFT JOIN departments dept ON u.department_id = dept.id
            WHERE d.module_type = 'Special_Exam'
            ORDER BY d.upload_date DESC");
        $dvcAllStmt->execute();
        $dvc_all_docs = $dvcAllStmt->get_result();
    }
}

// Helper
if (!function_exists('getStudentStatus')) {
    function getStudentStatus($s) {
        $m = ['Pending_COD'=>'Pending COD','Pending_Dean'=>'Pending Dean','Pending_Registrar'=>'Pending Registrar',
              'Pending_DVC'=>'Pending DVC','Approved'=>'Approved','Rejected'=>'Rejected','Draft'=>'Draft','Completed'=>'Completed'];
        return $m[$s] ?? $s;
    }
}
function getViewTitle($v) {
    return ['cod'=>'COD View','dean'=>'Dean View','registrar'=>'Registrar View','finance'=>'Finance Office View','dvc_arsa'=>'DVC ARSA View'][$v] ?? 'System Admin';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo $current_view ? getViewTitle($current_view) : 'System Admin'; ?> | MUT DMS</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
:root{--primary:#22c55e;--primary-dark:#16a34a;--secondary:#0f172a;--accent:#3b82f6;--warning:#f59e0b;--danger:#ef4444;--success:#10b981;--purple:#7c3aed;--orange:#f97316;--bg:#f8fafc;--card:#fff;--text:#1e293b;--text-light:#64748b;--border:#e2e8f0;--shadow:0 1px 3px rgba(0,0,0,.1);--shadow-lg:0 10px 15px -3px rgba(0,0,0,.1);--shadow-xl:0 20px 25px -5px rgba(0,0,0,.1)}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Inter',sans-serif;background:var(--bg);color:var(--text);min-height:100vh}
.layout{display:flex;min-height:100vh}
.sidebar{width:300px;background:var(--secondary);position:fixed;height:100vh;overflow-y:auto;z-index:100;display:flex;flex-direction:column}
.sidebar-header{padding:24px;border-bottom:1px solid rgba(255,255,255,.1)}
.logo{display:flex;align-items:center;gap:12px}
.logo-icon{width:48px;height:48px;background:linear-gradient(135deg,var(--primary) 0%,var(--primary-dark) 100%);border-radius:12px;display:flex;align-items:center;justify-content:center;color:white;font-size:1.5rem}
.logo-text{color:white}.logo-text h3{font-size:1.1rem;font-weight:700}.logo-text span{font-size:.75rem;color:rgba(255,255,255,.6)}
.system-admin-section{padding:20px;border-bottom:1px solid rgba(255,255,255,.1)}
.section-label{font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:rgba(255,255,255,.5);margin-bottom:16px;display:flex;align-items:center;gap:8px}
.view-buttons{display:flex;flex-direction:column;gap:10px}
.view-btn{display:flex;align-items:center;gap:12px;padding:14px;background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.1);border-radius:12px;color:rgba(255,255,255,.8);text-decoration:none;font-size:.9rem;font-weight:500;transition:all .2s;position:relative;overflow:hidden}
.view-btn::before{content:'';position:absolute;left:0;top:0;height:100%;width:3px;background:transparent;transition:all .2s}
.view-btn:hover{background:rgba(255,255,255,.1);transform:translateX(4px)}
.view-btn.active{background:rgba(34,197,94,.15);border-color:rgba(34,197,94,.4);color:var(--primary)}
.view-btn.active::before{background:var(--primary)}
.view-btn i{width:24px;text-align:center;font-size:1.1rem}
.view-btn.cod i{color:#3b82f6}.view-btn.dean i{color:#7c3aed}.view-btn.registrar i{color:#f59e0b}.view-btn.dvc i{color:#ef4444}
.view-btn.active i{color:var(--primary)}
.current-view-box{margin:16px;padding:16px;background:linear-gradient(135deg,rgba(34,197,94,.2) 0%,rgba(34,197,94,.1) 100%);border:1px solid rgba(34,197,94,.3);border-radius:12px;color:white;position:relative}
.current-view-box::after{content:'ACTIVE';position:absolute;top:8px;right:8px;font-size:.65rem;font-weight:700;background:var(--primary);color:white;padding:2px 6px;border-radius:4px}
.current-view-box .label{font-size:.75rem;color:rgba(255,255,255,.6);margin-bottom:4px;text-transform:uppercase;letter-spacing:.05em}
.current-view-box .value{font-size:1.1rem;font-weight:700;color:var(--primary);display:flex;align-items:center;gap:8px}
.current-view-box .dept{font-size:.85rem;color:rgba(255,255,255,.8);margin-top:6px;padding-top:6px;border-top:1px solid rgba(255,255,255,.1)}
.btn-back-to-admin{display:flex;align-items:center;justify-content:center;gap:8px;margin:0 16px 16px;padding:10px;background:rgba(255,255,255,.1);border:1px dashed rgba(255,255,255,.3);border-radius:8px;color:rgba(255,255,255,.7);font-size:.85rem;text-decoration:none;transition:all .2s}
.btn-back-to-admin:hover{background:rgba(255,255,255,.2);color:white;border-style:solid}
.nav-section{padding:20px 0;flex:1}
.nav-title{padding:8px 24px;font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:rgba(255,255,255,.4)}
.nav-item{display:flex;align-items:center;gap:12px;padding:12px 24px;color:rgba(255,255,255,.7);text-decoration:none;transition:all .2s;border-left:3px solid transparent}
.nav-item:hover,.nav-item.active{background:rgba(255,255,255,.05);color:white;border-left-color:var(--primary)}
.nav-item i{width:20px;text-align:center}
.sidebar-footer{padding:16px 24px;border-top:1px solid rgba(255,255,255,.1);margin-top:auto}
.admin-info{display:flex;align-items:center;gap:12px;margin-bottom:16px;padding-bottom:16px;border-bottom:1px solid rgba(255,255,255,.1)}
.admin-avatar{width:40px;height:40px;border-radius:50%;background:linear-gradient(135deg,var(--primary) 0%,var(--primary-dark) 100%);display:flex;align-items:center;justify-content:center;color:white;font-weight:700;font-size:1rem}
.admin-details .name{color:white;font-weight:600;font-size:.9rem}
.admin-details .role{color:rgba(255,255,255,.5);font-size:.75rem;text-transform:uppercase}
.btn-logout{display:flex;align-items:center;justify-content:center;gap:8px;width:100%;padding:12px;background:rgba(239,68,68,.2);color:#ef4444;border:none;border-radius:8px;font-size:.875rem;font-weight:600;cursor:pointer;transition:all .2s;text-decoration:none}
.btn-logout:hover{background:rgba(239,68,68,.3)}
.main-content{flex:1;margin-left:300px;min-height:100vh}
.header{background:var(--card);padding:20px 32px;display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid var(--border);position:sticky;top:0;z-index:50}
.page-title h1{font-size:1.75rem;font-weight:800}
.page-title p{font-size:.875rem;color:var(--text-light);margin-top:4px}
.content{padding:32px}
/* Modal */
.modal-overlay{position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(15,23,42,.6);display:flex;align-items:center;justify-content:center;z-index:1000;backdrop-filter:blur(8px);padding:20px}
.dept-modal{background:var(--card);border-radius:24px;padding:40px;width:100%;max-width:500px;box-shadow:var(--shadow-xl);animation:slideUp .4s cubic-bezier(.16,1,.3,1)}
@keyframes slideUp{from{opacity:0;transform:translateY(30px) scale(.95)}to{opacity:1;transform:translateY(0) scale(1)}}
.dept-modal-header{text-align:center;margin-bottom:32px}
.dept-modal-header .icon-box{width:80px;height:80px;background:linear-gradient(135deg,var(--primary),var(--primary-dark));border-radius:20px;display:flex;align-items:center;justify-content:center;margin:0 auto 20px;color:white;font-size:2rem;box-shadow:0 10px 20px -5px rgba(34,197,94,.3)}
.dept-modal-header h2{font-size:1.75rem;font-weight:800;margin-bottom:8px}
.dept-modal-header p{color:var(--text-light)}
.form-group{margin-bottom:24px}
.form-group label{display:block;font-weight:600;font-size:.9rem;margin-bottom:10px}
.form-group select{width:100%;padding:14px 18px;border:2px solid var(--border);border-radius:12px;font-size:1rem;font-family:inherit;background:white;cursor:pointer;transition:all .2s;appearance:none;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='24' height='24' viewBox='0 0 24 24' fill='none' stroke='%2364748b' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 14px center;background-size:20px;padding-right:48px}
.form-group select:focus{border-color:var(--primary);outline:none;box-shadow:0 0 0 4px rgba(34,197,94,.1)}
.btn-primary-modal{width:100%;background:linear-gradient(135deg,var(--primary),var(--primary-dark));color:white;padding:16px;border:none;border-radius:12px;font-size:1rem;font-weight:600;cursor:pointer;transition:all .2s;display:flex;align-items:center;justify-content:center;gap:8px;box-shadow:0 4px 12px rgba(34,197,94,.3)}
.btn-primary-modal:hover{transform:translateY(-2px);box-shadow:0 8px 20px rgba(34,197,94,.4)}
.btn-secondary-modal{width:100%;background:var(--bg);color:var(--text);padding:14px;border:2px solid var(--border);border-radius:12px;font-size:1rem;font-weight:600;cursor:pointer;transition:all .2s;margin-top:12px;text-decoration:none;display:flex;align-items:center;justify-content:center}
.btn-secondary-modal:hover{background:var(--border)}
/* Welcome */
.welcome-container{max-width:960px;margin:0 auto}
.welcome-header{text-align:center;margin-bottom:48px}
.welcome-header h2{font-size:2.5rem;font-weight:800;margin-bottom:16px}
.welcome-header p{font-size:1.125rem;color:var(--text-light);max-width:600px;margin:0 auto;line-height:1.6}
/* View cards grid – now 4 cards */
.views-grid{display:grid;grid-template-columns:repeat(5,1fr);gap:20px;margin-bottom:48px}
.view-card{background:var(--card);border:2px solid var(--border);border-radius:20px;padding:28px 20px;text-align:center;cursor:pointer;transition:all .3s cubic-bezier(.16,1,.3,1);text-decoration:none;color:inherit;position:relative;overflow:hidden}
.view-card::before{content:'';position:absolute;top:0;left:0;right:0;height:4px;background:transparent;transition:all .3s}
.view-card:hover{border-color:transparent;transform:translateY(-8px);box-shadow:var(--shadow-xl)}
.view-card.cod:hover::before,.view-card.cod:hover{border-color:#3b82f6}.view-card.cod:hover::before{background:#3b82f6}
.view-card.dean:hover,.view-card.dean:hover::before{border-color:#7c3aed}.view-card.dean:hover::before{background:#7c3aed}
.view-card.registrar:hover,.view-card.registrar:hover::before{border-color:#f59e0b}.view-card.registrar:hover::before{background:#f59e0b}
.view-card.dvc:hover,.view-card.dvc:hover::before{border-color:#ef4444}.view-card.dvc:hover::before{background:#ef4444}
.view-icon{width:70px;height:70px;border-radius:20px;display:flex;align-items:center;justify-content:center;margin:0 auto 16px;font-size:1.75rem;transition:all .3s}
.view-card.cod .view-icon{background:#dbeafe;color:#2563eb}
.view-card.dean .view-icon{background:#ede9fe;color:#7c3aed}
.view-card.registrar .view-icon{background:#fef3c7;color:#d97706}
.view-card.dvc .view-icon{background:#fee2e2;color:#dc2626}
.view-card.finance .view-icon{background:#e0f2fe;color:#0284c7}
.view-btn.finance i{color:#0ea5e9}
.view-card:hover .view-icon{transform:scale(1.1)}
.view-card h3{font-size:1.1rem;font-weight:700;margin-bottom:6px}
.view-card p{font-size:.8rem;color:var(--text-light);line-height:1.4}
.view-arrow{margin-top:16px;width:36px;height:36px;border-radius:50%;background:var(--bg);display:flex;align-items:center;justify-content:center;margin-left:auto;margin-right:auto;color:var(--text-light);transition:all .3s}
.view-card:hover .view-arrow{background:var(--primary);color:white;transform:translateX(4px)}
/* Quick actions */
.quick-actions{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;margin-top:32px}
.quick-action-card{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:20px;display:flex;align-items:center;gap:16px;text-decoration:none;color:inherit;transition:all .2s}
.quick-action-card:hover{border-color:var(--primary);background:rgba(34,197,94,.02);transform:translateY(-2px)}
.quick-action-icon{width:48px;height:48px;border-radius:12px;background:var(--bg);display:flex;align-items:center;justify-content:center;color:var(--primary);font-size:1.25rem}
.quick-action-text h4{font-weight:600;margin-bottom:4px}
.quick-action-text p{font-size:.8rem;color:var(--text-light)}
/* DVC panel */
.dvc-panel{background:var(--card);border-radius:20px;box-shadow:var(--shadow);border:1px solid var(--border)}
.dvc-header{padding:20px 24px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center}
.dvc-title{font-size:1.1rem;font-weight:700;display:flex;align-items:center;gap:10px}
.dvc-title i{color:#ef4444}
.documents-table-wrapper{overflow-x:auto;border-radius:12px;border:1px solid var(--border)}
.documents-table{width:100%;border-collapse:collapse;min-width:700px}
.documents-table th,.documents-table td{padding:14px 16px;text-align:left;border-bottom:1px solid var(--border)}
.documents-table th{font-weight:600;font-size:.75rem;text-transform:uppercase;letter-spacing:.05em;color:var(--text-light);background:var(--bg)}
.documents-table tr:last-child td{border-bottom:none}
.status-badge{display:inline-flex;align-items:center;gap:6px;padding:5px 11px;border-radius:20px;font-size:.75rem;font-weight:600}
.status-dvc{background:#fee2e2;color:#991b1b}
.action-btns{display:flex;gap:8px;flex-wrap:nowrap}
.btn-action{padding:7px 13px;border:none;border-radius:8px;font-size:.8rem;font-weight:600;cursor:pointer;transition:all .2s;display:inline-flex;align-items:center;gap:5px;text-decoration:none;white-space:nowrap}
.btn-view{background:var(--bg);color:var(--text);border:1px solid var(--border)}.btn-view:hover{background:var(--border)}
.btn-approve{background:var(--success);color:white}.btn-approve:hover{background:#059669}
.btn-reject{background:var(--danger);color:white}.btn-reject:hover{background:#dc2626}
.empty-state{text-align:center;padding:64px 32px}
.empty-state i{font-size:3rem;color:var(--border);margin-bottom:16px;display:block}
.empty-state h3{font-size:1.25rem;margin-bottom:8px;font-weight:700}
.empty-state p{color:var(--text-light)}
/* Carousel */
.carousel-card{width:100%;height:260px;background:var(--card);border-radius:16px;border:1px solid var(--border);overflow:hidden;position:relative;margin-bottom:32px}
.carousel-inner{display:flex;width:300%;height:100%;animation:slideAction 15s infinite ease-in-out}
.carousel-inner img{width:33.33%;height:100%;object-fit:cover}
@keyframes slideAction{0%,20%{transform:translateX(0)}33%,53%{transform:translateX(-33.33%)}66%,86%{transform:translateX(-66.66%)}100%{transform:translateX(0)}}
.carousel-overlay{position:absolute;bottom:0;left:0;right:0;height:50%;background:linear-gradient(transparent,rgba(15,23,42,.8));pointer-events:none}
/* Responsive */
@media(max-width:1024px){.sidebar{transform:translateX(-100%);transition:transform .3s}.sidebar.open{transform:translateX(0)}.main-content{margin-left:0}.views-grid{grid-template-columns:repeat(2,1fr)}}
.mobile-toggle{display:none;position:fixed;top:20px;left:20px;z-index:200;width:48px;height:48px;background:var(--card);border:1px solid var(--border);border-radius:12px;align-items:center;justify-content:center;cursor:pointer;box-shadow:var(--shadow-lg)}
@media(max-width:1024px){.mobile-toggle{display:flex}}
</style>
</head>
<body>
<button class="mobile-toggle" onclick="document.querySelector('.sidebar').classList.toggle('open')">
    <i class="fa-solid fa-bars"></i>
</button>

<!-- Department Selection Modal (COD) -->
<?php if ($show_dept_modal && $modal_mode === 'dept'): ?>
<div class="modal-overlay">
    <div class="dept-modal">
        <div class="dept-modal-header">
            <div class="icon-box"><i class="fa-solid fa-user-tie"></i></div>
            <h2>COD Login</h2>
            <p>Enter your staff registration number and password</p>
        </div>

        <?php if ($modal_error === 'cod_no_user'): ?>
        <div style="background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;border-radius:10px;padding:12px 16px;margin-bottom:18px;font-size:.9rem;font-weight:600;display:flex;align-items:center;gap:10px;">
            <i class="fa-solid fa-circle-exclamation"></i> No COD account found. Check your registration number and password.
        </div>
        <?php endif; ?>

        <form method="POST" id="deptForm" autocomplete="off">
            <input type="hidden" name="view_type" value="cod">
            <div class="form-group">
                <label>COD Registration Number <span style="color:#dc2626;">*</span></label>
                <input type="text" name="cod_reg_number" required
                    placeholder="e.g. STAFF/001/2020"
                    autocomplete="off"
                    style="width:100%;padding:14px 18px;border:2px solid var(--border);border-radius:12px;font-size:1rem;font-family:inherit;transition:border-color .2s;"
                    onfocus="this.style.borderColor='var(--primary)'" onblur="this.style.borderColor='var(--border)'">
            </div>
            <div class="form-group">
                <label>COD Password <span style="color:#dc2626;">*</span></label>
                <div style="position:relative;">
                    <input type="password" name="cod_password" id="codPasswordInput" required
                        placeholder="Enter COD account password"
                        autocomplete="new-password"
                        style="width:100%;padding:14px 48px 14px 18px;border:2px solid var(--border);border-radius:12px;font-size:1rem;font-family:inherit;transition:border-color .2s;"
                        onfocus="this.style.borderColor='var(--primary)'" onblur="this.style.borderColor='var(--border)'" value="">
                    <button type="button" onclick="togglePwdCOD()"
                        style="position:absolute;right:14px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--text-light);font-size:1rem;">
                        <i class="fa-solid fa-eye" id="codPwdIcon"></i>
                    </button>
                </div>
            </div>
            <button type="submit" name="select_department" class="btn-primary-modal">
                <i class="fa-solid fa-arrow-right"></i> Go to COD Dashboard
            </button>
        </form>
        <script>
        function togglePwdCOD() {
            const inp = document.getElementById('codPasswordInput');
            const icon = document.getElementById('codPwdIcon');
            if (inp.type === 'password') { inp.type = 'text'; icon.className = 'fa-solid fa-eye-slash'; }
            else { inp.type = 'password'; icon.className = 'fa-solid fa-eye'; }
        }
        </script>
        <a href="admin_dashboard.php" class="btn-secondary-modal">Cancel</a>
    </div>
</div>
<?php endif; ?>

<!-- Dean Login Modal -->
<?php if ($show_dept_modal && $modal_mode === 'school'): ?>
<div class="modal-overlay">
    <div class="dept-modal">
        <div class="dept-modal-header">
            <div class="icon-box" style="background:linear-gradient(135deg,#7c3aed,#6d28d9);box-shadow:0 10px 20px -5px rgba(124,58,237,.3);">
                <i class="fa-solid fa-user-graduate"></i>
            </div>
            <h2>Dean Login</h2>
            <p>Enter your staff registration number and password</p>
        </div>

        <?php if ($modal_error === 'dean_no_user'): ?>
        <div style="background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;border-radius:10px;padding:12px 16px;margin-bottom:18px;font-size:.9rem;font-weight:600;display:flex;align-items:center;gap:10px;">
            <i class="fa-solid fa-circle-exclamation"></i> No Dean account found. Check your registration number and password.
        </div>
        <?php elseif ($modal_error === 'dean_no_dept'): ?>
        <div style="background:#fef3c7;color:#92400e;border:1px solid #fde68a;border-radius:10px;padding:12px 16px;margin-bottom:18px;font-size:.9rem;font-weight:600;display:flex;align-items:center;gap:10px;">
            <i class="fa-solid fa-triangle-exclamation"></i> This Dean account has no department assigned. Please contact the system administrator.
        </div>
        <?php endif; ?>

        <form method="POST" autocomplete="off">
            <input type="hidden" name="view_type" value="dean">
            <div class="form-group">
                <label>Dean Registration Number <span style="color:#dc2626;">*</span></label>
                <input type="text" name="dean_reg_number" required
                    placeholder="e.g. STAFF/002/2020"
                    autocomplete="off"
                    style="width:100%;padding:14px 18px;border:2px solid var(--border);border-radius:12px;font-size:1rem;font-family:inherit;transition:border-color .2s;"
                    onfocus="this.style.borderColor='#7c3aed'" onblur="this.style.borderColor='var(--border)'">
            </div>
            <div class="form-group">
                <label>Dean Password <span style="color:#dc2626;">*</span></label>
                <div style="position:relative;">
                    <input type="password" name="dean_password" id="deanPasswordInput" required
                        placeholder="Enter Dean account password"
                        autocomplete="new-password"
                        style="width:100%;padding:14px 48px 14px 18px;border:2px solid var(--border);border-radius:12px;font-size:1rem;font-family:inherit;transition:border-color .2s;"
                        onfocus="this.style.borderColor='#7c3aed'" onblur="this.style.borderColor='var(--border)'" value="">
                    <button type="button" onclick="togglePwdDean()"
                        style="position:absolute;right:14px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--text-light);font-size:1rem;">
                        <i class="fa-solid fa-eye" id="deanPwdIcon"></i>
                    </button>
                </div>
            </div>
            <button type="submit" name="select_school" class="btn-primary-modal" style="background:linear-gradient(135deg,#7c3aed,#6d28d9);box-shadow:0 4px 12px rgba(124,58,237,.3);">
                <i class="fa-solid fa-arrow-right"></i> Go to Dean Dashboard
            </button>
        </form>
        <script>
        function togglePwdDean() {
            const inp = document.getElementById('deanPasswordInput');
            const icon = document.getElementById('deanPwdIcon');
            if (inp.type === 'password') { inp.type = 'text'; icon.className = 'fa-solid fa-eye-slash'; }
            else { inp.type = 'password'; icon.className = 'fa-solid fa-eye'; }
        }
        </script>
        <a href="admin_dashboard.php" class="btn-secondary-modal">Cancel</a>
    </div>
</div>
<?php endif; ?>

<div class="layout">
    <!-- Sidebar -->
    <aside class="sidebar">
        <div class="sidebar-header">
            <div class="logo">
                <div class="logo-icon"><i class="fa-solid fa-shield-halved"></i></div>
                <div class="logo-text"><h3>Admin Portal</h3><span>MUT Documents</span></div>
            </div>
        </div>

        <?php if ($user_role === 'super_admin'): ?>
        <div class="system-admin-section">
            <div class="section-label"><i class="fa-solid fa-layer-group"></i> System Admin Access</div>
            <div class="view-buttons">
                <a href="?set_view=cod" class="view-btn cod <?php echo $current_view==='cod'?'active':''; ?>">
                    <i class="fa-solid fa-user-tie"></i><span>COD View</span>
                    <i class="fa-solid fa-chevron-right" style="margin-left:auto;font-size:.75rem;opacity:.5;"></i>
                </a>
                <a href="?set_view=dean" class="view-btn dean <?php echo $current_view==='dean'?'active':''; ?>">
                    <i class="fa-solid fa-user-graduate"></i><span>Dean View</span>
                    <i class="fa-solid fa-chevron-right" style="margin-left:auto;font-size:.75rem;opacity:.5;"></i>
                </a>
                <a href="?set_view=registrar" class="view-btn registrar <?php echo $current_view==='registrar'?'active':''; ?>">
                    <i class="fa-solid fa-stamp"></i><span>Registrar View</span>
                    <i class="fa-solid fa-chevron-right" style="margin-left:auto;font-size:.75rem;opacity:.5;"></i>
                </a>
                <a href="?set_view=finance" class="view-btn finance <?php echo $current_view==='finance'?'active':''; ?>">
                    <i class="fa-solid fa-building-columns"></i><span>Finance Office View</span>
                    <i class="fa-solid fa-chevron-right" style="margin-left:auto;font-size:.75rem;opacity:.5;"></i>
                </a>
                <a href="?set_view=dvc_arsa" class="view-btn dvc <?php echo $current_view==='dvc_arsa'?'active':''; ?>">
                    <i class="fa-solid fa-crown"></i><span>DVC ARSA View</span>
                    <i class="fa-solid fa-chevron-right" style="margin-left:auto;font-size:.75rem;opacity:.5;"></i>
                </a>
            </div>
        </div>

        <?php if ($current_view): ?>
        <div class="current-view-box">
            <div class="label">Currently Viewing</div>
            <div class="value"><i class="fa-solid fa-eye"></i> <?php echo getViewTitle($current_view); ?></div>
            <?php if ($dept_name): ?>
            <div class="dept"><i class="fa-solid fa-building"></i> <?php echo htmlspecialchars($dept_name); ?></div>
            <?php elseif (!empty($_SESSION['selected_school'])): ?>
            <div class="dept"><i class="fa-solid fa-university"></i> <?php echo htmlspecialchars($_SESSION['selected_school']); ?></div>
            <?php endif; ?>
        </div>
        <a href="?clear_view=1" class="btn-back-to-admin">
            <i class="fa-solid fa-arrow-left"></i> Back to System Admin
        </a>
        <?php endif; ?>
        <?php endif; ?>

        <!-- Nav -->
        <nav class="nav-section">
            <div class="nav-title">Menu</div>
            <?php if ($current_view === 'dvc_arsa'): ?>
                <a href="admin_dashboard.php" class="nav-item <?php echo !$dvc_show_all?'active':''; ?>"><i class="fa-solid fa-grid-2"></i><span>Dashboard</span></a>
                <a href="admin_dashboard.php?show=all_docs" class="nav-item <?php echo $dvc_show_all?'active':''; ?>"><i class="fa-solid fa-folder-open"></i><span>All Documents</span></a>
            <?php elseif ($current_view === 'registrar'): ?>
                <a href="registrar_dashboard.php" class="nav-item"><i class="fa-solid fa-grid-2"></i><span>Dashboard</span></a>
                <a href="registrar_documents.php" class="nav-item"><i class="fa-solid fa-folder-open"></i><span>Students Applications</span></a>
                <a href="registrar_reports.php" class="nav-item"><i class="fa-solid fa-chart-bar"></i><span>Reports</span></a>
            <?php elseif ($current_view === 'cod'): ?>
                <a href="cod_dashboard.php?dept=<?php echo $selected_dept; ?>" class="nav-item"><i class="fa-solid fa-grid-2"></i><span>Dashboard</span></a>
                <a href="cod_documents.php?dept=<?php echo $selected_dept; ?>" class="nav-item"><i class="fa-solid fa-folder-open"></i><span>Students Applications</span></a>
                <a href="cod_reports.php?dept=<?php echo $selected_dept; ?>" class="nav-item"><i class="fa-solid fa-chart-bar"></i><span>Reports</span></a>
            <?php elseif ($current_view === 'dean'): ?>
                <a href="dean_dashboard.php?school=<?php echo urlencode($_SESSION['selected_school']??''); ?>" class="nav-item"><i class="fa-solid fa-grid-2"></i><span>Dashboard</span></a>
                <a href="dean_documents.php?school=<?php echo urlencode($_SESSION['selected_school']??''); ?>" class="nav-item"><i class="fa-solid fa-folder-open"></i><span>Students Applications</span></a>
                <a href="dean_reports.php?school=<?php echo urlencode($_SESSION['selected_school']??''); ?>" class="nav-item"><i class="fa-solid fa-chart-bar"></i><span>Reports</span></a>
            <?php else: ?>
                <a href="admin_dashboard.php" class="nav-item active"><i class="fa-solid fa-grid-2"></i><span>Dashboard</span></a>
                <a href="admin_documents.php" class="nav-item"><i class="fa-solid fa-folder-open"></i><span>All Documents</span></a>
                <?php if ($user_role === 'super_admin'): ?>
                <a href="admin_users.php" class="nav-item"><i class="fa-solid fa-users"></i><span>Manage Users</span></a>
                <a href="admin_departments.php" class="nav-item"><i class="fa-solid fa-building"></i><span>Departments</span></a>
                <a href="admin_audit.php" class="nav-item"><i class="fa-solid fa-list-check"></i><span>Audit Logs</span></a>
                <?php endif; ?>
            <?php endif; ?>
        </nav>

        <div class="sidebar-footer">
            <div class="admin-info">
                <div class="admin-avatar"><?php echo strtoupper(substr($full_name,0,1)); ?></div>
                <div class="admin-details">
                    <div class="name"><?php echo htmlspecialchars($full_name); ?></div>
                    <div class="role"><?php echo ucfirst(str_replace('_',' ',$user_role)); ?></div>
                </div>
            </div>
            <a href="logout.php" class="btn-logout"><i class="fa-solid fa-sign-out-alt"></i><span>Logout</span></a>
        </div>
    </aside>

    <!-- Main Content -->
    <main class="main-content">
        <header class="header">
            <div class="page-title">
                <h1><?php echo $current_view ? getViewTitle($current_view) : 'System Admin Dashboard'; ?></h1>
                <p><?php
                    if ($current_view === 'dvc_arsa') echo 'DVC Academic Research &amp; Student Affairs — Special Exam approvals';
                    elseif ($current_view === 'registrar') echo 'Final approval authority';
                    elseif ($current_view) echo 'Document approval';
                    else echo 'Welcome back, ' . htmlspecialchars($full_name) . ' · Select a view to manage documents';
                ?></p>
            </div>
        </header>

        <div class="content">
            <?php if (!$current_view): ?>
            <!-- Welcome / View Selection Screen -->
            <div class="welcome-container">
                <div class="welcome-header">
                    <h2>Welcome to Admin Portal</h2>
                    <p>Select a view below to manage documents at that approval level. Each view will take you directly to the respective dashboard.</p>
                </div>

                <div class="carousel-card">
                    <div class="carousel-inner">
                        <img src="slide1.jpg" alt="MUT Campus">
                        <img src="slide2.jpg" alt="MUT Library">
                        <img src="slide3.jpg" alt="MUT Tech Hub">
                    </div>
                    <div class="carousel-overlay"></div>
                    <div style="position:absolute;bottom:15px;left:20px;z-index:2;">
                        <h4 style="color:white;text-shadow:0 2px 4px rgba(0,0,0,.5);">MUT News &amp; Updates</h4>
                        <p style="color:#cbd5e1;font-size:.8rem;">Stay updated with campus events</p>
                    </div>
                </div>

                <div class="views-grid">
                    <a href="?set_view=cod" class="view-card cod">
                        <div class="view-icon"><i class="fa-solid fa-user-tie"></i></div>
                        <h3>COD View</h3>
                        <p>Recommend or not recommend student applications at department level.</p>
                        <div class="view-arrow"><i class="fa-solid fa-arrow-right"></i></div>
                    </a>
                    <a href="?set_view=dean" class="view-card dean">
                        <div class="view-icon"><i class="fa-solid fa-user-graduate"></i></div>
                        <h3>Dean View</h3>
                        <p>Approve or reject applications for your school's departments.</p>
                        <div class="view-arrow"><i class="fa-solid fa-arrow-right"></i></div>
                    </a>
                    <a href="?set_view=registrar" class="view-card registrar">
                        <div class="view-icon"><i class="fa-solid fa-stamp"></i></div>
                        <h3>Registrar View</h3>
                        <p>Finance, Planning &amp; Development — Final processing for all documents.</p>
                        <div class="view-arrow"><i class="fa-solid fa-arrow-right"></i></div>
                    </a>
                    <a href="?set_view=finance" class="view-card finance">
                    <div class="view-icon"><i class="fa-solid fa-building-columns"></i></div>
                    <h3>Finance Office</h3>
                    <p>Process fee payments, bursary approvals and finalise exam forms</p>
                    <div class="view-arrow"><i class="fa-solid fa-arrow-right"></i></div>
                </a>
                <a href="?set_view=dvc_arsa" class="view-card dvc">
                        <div class="view-icon"><i class="fa-solid fa-crown"></i></div>
                        <h3>DVC ARSA View</h3>
                        <p>Approve or reject special exam applications at the highest level.</p>
                        <div class="view-arrow"><i class="fa-solid fa-arrow-right"></i></div>
                    </a>
                </div>

                <?php if ($user_role === 'super_admin'): ?>
                <div class="quick-actions">
                    <a href="admin_users.php" class="quick-action-card">
                        <div class="quick-action-icon"><i class="fa-solid fa-users"></i></div>
                        <div class="quick-action-text"><h4>Manage Users</h4><p>Add, edit, or remove system users</p></div>
                    </a>
                    <a href="admin_departments.php" class="quick-action-card">
                        <div class="quick-action-icon"><i class="fa-solid fa-building"></i></div>
                        <div class="quick-action-text"><h4>Departments</h4><p>Manage schools and departments</p></div>
                    </a>
                    <a href="admin_settings.php" class="quick-action-card">
                        <div class="quick-action-icon"><i class="fa-solid fa-cog"></i></div>
                        <div class="quick-action-text"><h4>System Settings</h4><p>Configure system preferences</p></div>
                    </a>
                    <a href="admin_audit.php" class="quick-action-card">
                        <div class="quick-action-icon"><i class="fa-solid fa-shield-halved"></i></div>
                        <div class="quick-action-text"><h4>Audit Logs</h4><p>View system activity logs</p></div>
                    </a>
                </div>
                <?php endif; ?>
            </div>

            <?php elseif ($current_view === 'dvc_arsa'): ?>
            <!-- DVC ARSA Toast -->
            <?php
            $dvc_popup_msg = ''; $dvc_popup_icon = '✅';
            if (!empty($_GET['success'])) {
                if ($_GET['success'] === 'dvc_approved') $dvc_popup_msg = 'Special Exam application approved. Approval letter sent to student\'s email.';
                elseif ($_GET['success'] === 'dvc_rejected') { $dvc_popup_icon = '❌'; $dvc_popup_msg = 'Special Exam application rejected. Student has been notified.'; }
            }
            ?>
            <?php if ($dvc_popup_msg): ?>
            <style>
            .mut-toast{position:fixed;top:30px;left:50%;transform:translateX(-50%) translateY(-20px);background:#fff;border-radius:14px;box-shadow:0 8px 30px rgba(0,0,0,.15);padding:18px 48px 18px 20px;display:flex;align-items:flex-start;gap:14px;min-width:320px;max-width:480px;z-index:9999;animation:slideDown .4s ease forwards}
            @keyframes slideDown{to{transform:translateX(-50%) translateY(0);opacity:1}}
            @keyframes shrink{from{width:100%}to{width:0}}
            .mut-toast-close{position:absolute;top:10px;right:14px;background:none;border:none;font-size:1.1rem;color:#94a3b8;cursor:pointer}
            .mut-toast-bar{position:absolute;bottom:0;left:0;height:4px;background:#22c55e;border-radius:0 0 14px 14px;animation:shrink 4s linear forwards}
            </style>
            <div class="mut-toast" id="mutToast">
              <div style="font-size:2rem;line-height:1;flex-shrink:0;"><?php echo $dvc_popup_icon; ?></div>
              <div>
                <div style="font-weight:700;font-size:1rem;color:#1e293b;margin-bottom:4px;">Action Confirmed</div>
                <div style="font-size:.875rem;color:#475569;line-height:1.5;"><?php echo htmlspecialchars($dvc_popup_msg); ?></div>
              </div>
              <button class="mut-toast-close" onclick="document.getElementById('mutToast').remove()">✕</button>
              <div class="mut-toast-bar"></div>
            </div>
            <script>setTimeout(()=>{const t=document.getElementById('mutToast');if(t)t.remove();},4500);</script>
            <?php endif; ?>

            <!-- DVC ARSA Panel -->
            <div class="dvc-panel">
                <div class="dvc-header" style="display:flex;justify-content:space-between;align-items:center;">
                    <h2 class="dvc-title">
                        <i class="fa-solid fa-crown"></i>
                        <?php echo $dvc_show_all ? 'All Special Exam Applications' : 'Special Exam Applications — Awaiting DVC Decision'; ?>
                    </h2>
                    <?php if ($dvc_show_all): ?>
                    <a href="admin_dashboard.php" style="font-size:.85rem;color:#ef4444;text-decoration:none;display:flex;align-items:center;gap:6px;padding:8px 16px;border:1px solid #fca5a5;border-radius:8px;font-weight:600;">
                        <i class="fa-solid fa-clock"></i> Pending Only
                    </a>
                    <?php else: ?>
                    <a href="admin_dashboard.php?show=all_docs" style="font-size:.85rem;color:#64748b;text-decoration:none;display:flex;align-items:center;gap:6px;padding:8px 16px;border:1px solid #e2e8f0;border-radius:8px;font-weight:600;">
                        <i class="fa-solid fa-folder-open"></i> All Documents
                    </a>
                    <?php endif; ?>
                </div>

                <?php $active_result = $dvc_show_all ? $dvc_all_docs : $dvc_pending; ?>
                <?php if ($active_result && $active_result->num_rows > 0): ?>
                <div class="documents-table-wrapper" style="margin:20px;">
                    <table class="documents-table">
                        <thead>
                            <tr>
                                <th>Document</th>
                                <th>Student</th>
                                <th>Department</th>
                                <th>Submitted</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($doc = $active_result->fetch_assoc()):
                                $letter_path = null;
                                if ($doc['status'] === 'Approved') {
                                    $lStmt = $conn->prepare("SELECT approval_letter_path FROM special_exam_applications WHERE document_id = ? ORDER BY id DESC LIMIT 1");
                                    $lStmt->bind_param("i", $doc['id']);
                                    $lStmt->execute();
                                    $lRow = $lStmt->get_result()->fetch_assoc();
                                    $letter_path = $lRow['approval_letter_path'] ?? null;
                                }
                            ?>
                            <tr>
                                <td>
                                    <div style="font-weight:600;"><?php echo htmlspecialchars($doc['title']); ?></div>
                                    <div style="font-size:.8rem;color:var(--text-light);"><?php echo htmlspecialchars($doc['module_type']); ?></div>
                                </td>
                                <td>
                                    <div style="font-weight:600;"><?php echo htmlspecialchars($doc['full_name']); ?></div>
                                    <div style="font-size:.8rem;color:var(--text-light);font-family:monospace;"><?php echo htmlspecialchars($doc['student_reg']); ?></div>
                                </td>
                                <td><?php echo htmlspecialchars($doc['dept_name']??'N/A'); ?></td>
                                <td><?php echo date('M j, Y', strtotime($doc['upload_date'])); ?></td>
                                <td>
                                    <?php if ($doc['status'] === 'Approved'): ?>
                                    <span class="status-badge" style="background:#dcfce7;color:#166534;"><i class="fa-solid fa-check-circle"></i> Approved</span>
                                    <?php elseif ($doc['status'] === 'Rejected'): ?>
                                    <span class="status-badge" style="background:#fee2e2;color:#991b1b;"><i class="fa-solid fa-xmark-circle"></i> Rejected</span>
                                    <?php else: ?>
                                    <span class="status-badge status-dvc"><i class="fa-solid fa-clock"></i> Pending DVC</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="action-btns">
                                        <a href="view_form.php?id=<?php echo $doc['id']; ?>" class="btn-action btn-view">
                                            <i class="fa-solid fa-eye"></i> View
                                        </a>
                                        <?php if ($letter_path): ?>
                                        <a href="<?php echo htmlspecialchars($letter_path); ?>" download class="btn-action" style="background:#dcfce7;color:#166534;border:1px solid #bbf7d0;">
                                            <i class="fa-solid fa-file-arrow-down"></i> Letter
                                        </a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div class="empty-state">
                    <i class="fa-solid fa-check-circle"></i>
                    <h3><?php echo $dvc_show_all ? 'No Applications Yet' : 'All Caught Up!'; ?></h3>
                    <p><?php echo $dvc_show_all ? 'No special exam applications found.' : 'No special exam applications are currently awaiting your decision.'; ?></p>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </main>
</div>

<script>
document.addEventListener('click', function(e) {
    const sidebar = document.querySelector('.sidebar');
    const toggle  = document.querySelector('.mobile-toggle');
    if (window.innerWidth <= 1024 && !sidebar.contains(e.target) && !toggle.contains(e.target) && sidebar.classList.contains('open')) {
        sidebar.classList.remove('open');
    }
});
</script>
</body>
</html>
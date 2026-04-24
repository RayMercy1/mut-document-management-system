<?php
session_start();
require_once 'db_config.php';

// Check if user is logged in
if (!isset($_SESSION['reg_number'])) {
    header("Location: login.php");
    exit();
}

$reg_number = $_SESSION['reg_number'];

// Fetch all documents for the user
$stmt = $conn->prepare("SELECT d.*, dept.dept_name 
    FROM documents d 
    LEFT JOIN users u ON d.reg_number = u.reg_number 
    LEFT JOIN departments dept ON u.department_id = dept.id 
    WHERE d.reg_number = ? 
    ORDER BY d.upload_date DESC"); // Added 'BY' here
$stmt->bind_param("s", $reg_number);
$stmt->execute();
$documents = $stmt->get_result();

// Get user data for the header
$userStmt = $conn->prepare("SELECT full_name, profile_pix FROM users WHERE reg_number = ?");
$userStmt->bind_param("s", $reg_number);
$userStmt->execute();
$user = $userStmt->get_result()->fetch_assoc();

// Function to handle status display logic
function getStatusDetails($status) {
    return match($status) {
        'Approved' => ['success', 'fa-check-double', 'Approved'],
        'Rejected' => ['danger', 'fa-xmark', 'Rejected'],
        'Pending_COD', 'Pending_Dean', 'Pending_Registrar' => ['processing', 'fa-spinner fa-spin', 'Processing'],
        default => ['pending', 'fa-clock', 'Pending']
    };
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Documents | MUT Portal</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #22c55e;
            --primary-dark: #16a34a;
            --secondary: #0f172a;
            --text-main: #ffffff;
            --text-muted: rgba(255, 255, 255, 0.7);
            --glass: rgba(255, 255, 255, 0.1);
            --glass-border: rgba(255, 255, 255, 0.15);
            --card-shadow: 0 8px 32px 0 rgba(0, 0, 0, 0.3);
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            color: var(--text-main);
            min-height: 100vh;
            /* Multi-layered gradient background consistent with your dashboard */
            background: 
                radial-gradient(at 0% 0%, rgba(34, 197, 94, 0.15) 0px, transparent 50%),
                radial-gradient(at 100% 0%, rgba(15, 23, 42, 0.3) 0px, transparent 50%),
                #111827; 
            background-attachment: fixed;
            padding: 40px 20px;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
        }

        /* Top Navigation Bar */
        .top-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 40px;
            background: rgba(255, 255, 255, 0.05);
            backdrop-filter: blur(10px);
            padding: 20px 30px;
            border-radius: 24px;
            border: 1px solid var(--glass-border);
        }

        .back-btn {
            display: flex;
            align-items: center;
            gap: 10px;
            text-decoration: none;
            color: white;
            font-weight: 600;
            background: var(--glass);
            padding: 12px 24px;
            border-radius: 14px;
            border: 1px solid var(--glass-border);
            transition: all 0.3s ease;
        }

        .back-btn:hover {
            background: rgba(255, 255, 255, 0.2);
            transform: translateX(-5px);
        }

        /* Stats Row */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 40px;
        }

        .stat-card {
            background: var(--glass);
            backdrop-filter: blur(10px);
            padding: 24px;
            border-radius: 24px;
            display: flex;
            align-items: center;
            gap: 15px;
            border: 1px solid var(--glass-border);
            box-shadow: var(--card-shadow);
        }

        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            background: rgba(34, 197, 94, 0.2);
            color: var(--primary);
        }

        /* Filter & Search Section */
        .filter-section {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            flex-wrap: wrap;
            gap: 20px;
        }

        .search-box {
            position: relative;
            flex: 1;
            max-width: 400px;
        }

        .search-box input {
            width: 100%;
            padding: 14px 20px 14px 45px;
            border-radius: 16px;
            background: var(--glass);
            border: 1px solid var(--glass-border);
            outline: none;
            color: white;
            font-size: 0.95rem;
            transition: all 0.3s;
        }

        .search-box input:focus {
            background: rgba(255, 255, 255, 0.15);
            border-color: var(--primary);
        }

        .search-box i {
            position: absolute;
            left: 18px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted);
        }

        .filter-btns {
            display: flex;
            gap: 8px;
            background: var(--glass);
            padding: 6px;
            border-radius: 16px;
            border: 1px solid var(--glass-border);
        }

        .filter-tab {
            padding: 10px 20px;
            border: none;
            background: transparent;
            border-radius: 12px;
            font-weight: 600;
            font-size: 0.85rem;
            cursor: pointer;
            color: var(--text-muted);
            transition: all 0.3s;
        }

        .filter-tab.active {
            background: var(--primary);
            color: white;
        }

        /* Document Grid */
        .doc-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 25px;
        }

        .doc-card {
            background: var(--glass);
            backdrop-filter: blur(15px);
            border-radius: 28px;
            padding: 26px;
            border: 1px solid var(--glass-border);
            transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            position: relative;
            overflow: hidden;
            box-shadow: var(--card-shadow);
        }

        .doc-card:hover {
            transform: translateY(-10px);
            background: rgba(255, 255, 255, 0.15);
            border-color: rgba(34, 197, 94, 0.5);
        }

        .doc-type-icon {
            width: 55px;
            height: 55px;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.6rem;
            margin-bottom: 22px;
            box-shadow: 0 10px 20px rgba(0,0,0,0.2);
        }

        .type-pdf { background: rgba(239, 68, 68, 0.2); color: #ef4444; }
        .type-form { background: rgba(59, 130, 246, 0.2); color: #3b82f6; }

        .doc-title {
            font-size: 1.2rem;
            font-weight: 700;
            margin-bottom: 6px;
            color: white;
        }

        .doc-subtitle {
            font-size: 0.85rem;
            color: var(--text-muted);
            margin-bottom: 22px;
        }

        .status-pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 16px;
            border-radius: 12px;
            font-size: 0.7rem;
            font-weight: 800;
            letter-spacing: 0.5px;
            text-transform: uppercase;
            margin-bottom: 24px;
        }

        .status-success { background: rgba(34, 197, 94, 0.15); color: #4ade80; }
        .status-danger { background: rgba(239, 68, 68, 0.15); color: #f87171; }
        .status-pending { background: rgba(251, 191, 36, 0.15); color: #fbbf24; }
        .status-processing { background: rgba(56, 189, 248, 0.15); color: #38bdf8; }

        .doc-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-top: 20px;
            border-top: 1px solid var(--glass-border);
        }

        .doc-date {
            font-size: 0.75rem;
            color: var(--text-muted);
        }

        .doc-actions {
            display: flex;
            gap: 10px;
        }

        .action-btn {
            width: 42px;
            height: 42px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            transition: all 0.3s;
            background: rgba(255, 255, 255, 0.08);
            color: white;
            border: 1px solid var(--glass-border);
        }

        .action-btn:hover {
            background: var(--primary);
            border-color: var(--primary);
            color: white;
            transform: scale(1.1);
        }

        .empty-state {
            grid-column: 1 / -1;
            text-align: center;
            padding: 100px 0;
            background: var(--glass);
            border-radius: 30px;
            border: 1px solid var(--glass-border);
        }

        .empty-state i { font-size: 4rem; color: var(--text-muted); margin-bottom: 25px; }

        @media (max-width: 640px) {
            .filter-section { flex-direction: column; align-items: stretch; }
            .doc-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

<div class="container">
    <div class="top-bar">
        <a href="index.php" class="back-btn">
            <i class="fa-solid fa-chevron-left"></i>
            <span>Dashboard</span>
        </a>
        <h1 style="font-weight: 800; font-size: 1.6rem; letter-spacing: -1px;">My Archive</h1>
    </div>

    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon"><i class="fa-solid fa-layer-group"></i></div>
            <div>
                <p style="font-size: 0.75rem; color: var(--text-muted); font-weight: 600; text-transform: uppercase;">Total Files</p>
                <h3 style="font-size: 1.6rem;"><?php echo $documents->num_rows; ?></h3>
            </div>
        </div>
    </div>

    <div class="filter-section">
        <div class="search-box">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="text" id="docSearch" placeholder="Search by document title..." onkeyup="searchDocs()">
        </div>
        <div class="filter-btns">
            <button class="filter-tab active" onclick="filterDocs('all', this)">All</button>
            <button class="filter-tab" onclick="filterDocs('approved', this)">Approved</button>
            <button class="filter-tab" onclick="filterDocs('pending', this)">Pending</button>
        </div>
    </div>

    <div class="doc-grid" id="documentContainer">
        <?php if ($documents->num_rows > 0): ?>
            <?php while ($doc = $documents->fetch_assoc()): 
                $status = getStatusDetails($doc['status']);
                $file_ext = $doc['file_path'] ? pathinfo($doc['file_path'], PATHINFO_EXTENSION) : '';
                $is_pdf = (strtolower($file_ext) === 'pdf');
            ?>
                <div class="doc-card" data-status="<?php echo strtolower($doc['status']); ?>" data-title="<?php echo strtolower($doc['title']); ?>">
                    <div class="doc-type-icon <?php echo $doc['file_path'] ? 'type-pdf' : 'type-form'; ?>">
                        <i class="fa-solid <?php echo $doc['file_path'] ? 'fa-file-pdf' : 'fa-file-signature'; ?>"></i>
                    </div>
                    
                    <h4 class="doc-title"><?php echo htmlspecialchars($doc['title']); ?></h4>
                    <p class="doc-subtitle"><?php echo htmlspecialchars($doc['module_type']); ?></p>
                    
                    <div class="status-pill status-<?php echo $status[0]; ?>">
                        <i class="fa-solid <?php echo $status[1]; ?>"></i>
                        <?php echo $status[2]; ?>
                    </div>

                    <div class="doc-footer">
                        <span class="doc-date">
                            <i class="fa-regular fa-calendar-days"></i> 
                            <?php echo date('M d, Y', strtotime($doc['upload_date'])); ?>
                        </span>
                        <div class="doc-actions">
                            <?php if ($doc['file_path']): ?>
                                <a href="<?php echo htmlspecialchars($doc['file_path']); ?>" target="_blank" class="action-btn" title="View PDF">
                                    <i class="fa-solid fa-eye"></i>
                                </a>
                                <a href="<?php echo htmlspecialchars($doc['file_path']); ?>" download class="action-btn" title="Download">
                                    <i class="fa-solid fa-download"></i>
                                </a>
                            <?php else: ?>
                                <a href="view_form.php?id=<?php echo $doc['id']; ?>" class="action-btn" title="View Form Details">
                                    <i class="fa-solid fa-arrow-up-right-from-square"></i>
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endwhile; ?>
        <?php else: ?>
            <div class="empty-state">
                <i class="fa-solid fa-folder-open"></i>
                <h3 style="margin-bottom: 10px;">Archive is Empty</h3>
                <p style="color: var(--text-muted); margin-bottom: 30px;">You haven't submitted any documents for digital processing yet.</p>
                <a href="index.php" class="back-btn" style="display:inline-flex; background: var(--primary); border: none;">
                    New Submission
                </a>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
    function filterDocs(status, btn) {
        document.querySelectorAll('.filter-tab').forEach(t => t.classList.remove('active'));
        btn.classList.add('active');

        const cards = document.querySelectorAll('.doc-card');
        cards.forEach(card => {
            const cardStatus = card.dataset.status;
            if (status === 'all') {
                card.style.display = 'block';
            } else if (status === 'pending' && cardStatus.includes('pending')) {
                card.style.display = 'block';
            } else if (status === 'approved' && cardStatus === 'approved') {
                card.style.display = 'block';
            } else {
                card.style.display = 'none';
            }
        });
    }

    function searchDocs() {
        const query = document.getElementById('docSearch').value.toLowerCase();
        const cards = document.querySelectorAll('.doc-card');
        
        cards.forEach(card => {
            const title = card.dataset.title;
            card.style.display = title.includes(query) ? 'block' : 'none';
        });
    }
</script>

</body>
</html>
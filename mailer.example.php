<?php
/**
 * MUT DMS — Email Helper (mailer.php)
 * Compatible with PHPMailer 7.0.2
 * Place this file at: C:\xampp\htdocs\mut_dms\mailer.php
 */

// ════════════════════════════════════════════════════════════════
//  EMAIL CONFIGURATION  ←  ONLY EDIT LINES 16, 20, AND 21
// ════════════════════════════════════════════════════════════════
define('MAIL_FROM_EMAIL',    'your_email@gmail.com');   
define('MAIL_FROM_NAME',     'MUT Document System');    
define('MAIL_SMTP_HOST',     'smtp.gmail.com');         
define('MAIL_SMTP_PORT',     587);                      
define('MAIL_SMTP_USERNAME', 'your_email@gmail.com');   
define('MAIL_SMTP_PASSWORD', 'your_gmail_app_password');   
define('MAIL_SMTP_SECURE',   'tls');                    
// ════════════════════════════════════════════════════════════════


/**
 * Core send function — used by all other functions in this file.
 * Uses PHPMailer 7 (SMTP via Gmail). Falls back to PHP mail() if PHPMailer not found.
 *
 * @param string $to_email    Recipient email address
 * @param string $to_name     Recipient display name
 * @param string $subject     Email subject line
 * @param string $html_body   Full HTML email body
 * @param array  $attachments Optional file attachments: [['path'=>'...','name'=>'...'], ...]
 * @return array ['success' => bool, 'error' => string]
 */
function sendEmail(string $to_email, string $to_name, string $subject, string $html_body, array $attachments = []): array
{
    $phpmailer_path = __DIR__ . '/PHPMailer/src/PHPMailer.php';

    if (file_exists($phpmailer_path)) {
       
        require_once __DIR__ . '/PHPMailer/src/Exception.php';
        require_once __DIR__ . '/PHPMailer/src/PHPMailer.php';
        require_once __DIR__ . '/PHPMailer/src/SMTP.php';

        $mail = new PHPMailer\PHPMailer\PHPMailer(true);

        try {
           
            $mail->isSMTP();
            $mail->Host       = MAIL_SMTP_HOST;
            $mail->SMTPAuth   = true;
            $mail->Username   = MAIL_SMTP_USERNAME;
            $mail->Password   = MAIL_SMTP_PASSWORD;
            $mail->SMTPSecure = MAIL_SMTP_SECURE;
            $mail->Port       = MAIL_SMTP_PORT;
            $mail->CharSet    = 'UTF-8';
            $mail->SMTPDebug  = 0; 

            $mail->setFrom(MAIL_FROM_EMAIL, MAIL_FROM_NAME);
            $mail->addAddress($to_email, $to_name);
            $mail->addReplyTo(MAIL_FROM_EMAIL, MAIL_FROM_NAME);

            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $html_body;
            $mail->AltBody = strip_tags(
                str_replace(
                    ['<br>', '<br/>', '<br />', '</p>', '</div>', '</h1>', '</h2>', '</h3>', '</li>'],
                    "\n",
                    $html_body
                )
            );
            foreach ($attachments as $att) {
                if (!empty($att['path']) && file_exists($att['path'])) {
                    $mail->addAttachment($att['path'], $att['name'] ?? basename($att['path']));
                }
            }

            $mail->send();
            return ['success' => true, 'error' => ''];

        } catch (PHPMailer\PHPMailer\Exception $e) {
            return ['success' => false, 'error' => 'PHPMailer error: ' . $mail->ErrorInfo];
        }
    }


    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-type: text/html; charset=UTF-8\r\n";
    $headers .= "From: " . MAIL_FROM_NAME . " <" . MAIL_FROM_EMAIL . ">\r\n";
    $headers .= "Reply-To: " . MAIL_FROM_EMAIL . "\r\n";

    $sent = @mail($to_email, $subject, $html_body, $headers);
    if ($sent) {
        return ['success' => true, 'error' => ''];
    }
    return [
        'success' => false,
        'error'   => 'PHPMailer not found at /PHPMailer/src/ and PHP mail() also failed. ' .
                     'Ensure PHPMailer/src/PHPMailer.php, SMTP.php and Exception.php exist in mut_dms.'
    ];
}


/**
 * Sends the official DVC Special Exam Approval Letter to the student.
 * Called automatically from process_approval.php when DVC approves.
 */
function sendSpecialExamApprovalLetter(array $student, array $sea, string $dvc_name): array
{
    $approval_date = date('F j, Y');
    $ref_number    = 'MUT/ARSA/' . date('Y') . '/' . str_pad($sea['id'] ?? rand(100, 999), 4, '0', STR_PAD_LEFT);
    $student_name  = htmlspecialchars($student['full_name']);
    $reg_number    = htmlspecialchars($student['reg_number']);
    $course        = htmlspecialchars($student['course'] ?? 'N/A');
    $app_type      = htmlspecialchars($sea['application_type'] ?? '');
    $exam_period   = htmlspecialchars(($sea['exam_month'] ?? '') . ' ' . ($sea['exam_year'] ?? date('Y')));
    $units_raw     = nl2br(htmlspecialchars($sea['units'] ?? ''));
    $reason        = htmlspecialchars($sea['reason_description'] ?? '');
    $dvc_display   = htmlspecialchars($dvc_name);
    $subject       = "Special Exam Approval Letter – {$student['full_name']} – MUT";

    $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
  body{font-family:'Times New Roman',Times,serif;background:#f0f0f0;margin:0;padding:24px;}
  .letter{max-width:720px;margin:0 auto;background:#fff;padding:50px 56px;box-shadow:0 2px 16px rgba(0,0,0,.12);}
  .ltr-header{text-align:center;border-bottom:3px double #1a3a6b;padding-bottom:18px;margin-bottom:22px;}
  .uni-name{font-size:15pt;font-weight:bold;text-transform:uppercase;color:#1a3a6b;margin-bottom:4px;letter-spacing:.5px;}
  .office-name{font-size:10pt;font-weight:bold;text-transform:uppercase;color:#1a3a6b;margin-bottom:2px;}
  .contact-line{font-size:8.5pt;color:#555;margin-top:4px;}
  .ref-date{display:flex;justify-content:space-between;font-size:10.5pt;margin-bottom:18px;}
  .addressee{font-size:11pt;margin-bottom:18px;line-height:1.6;}
  .letter-title{text-align:center;font-size:12pt;font-weight:bold;text-decoration:underline;text-transform:uppercase;margin:16px 0 18px;color:#1a3a6b;}
  .approved-badge{display:inline-block;background:#d1fae5;color:#065f46;padding:5px 20px;border-radius:20px;font-weight:bold;font-size:10.5pt;margin-bottom:14px;border:1px solid #6ee7b7;}
  .body-text{font-size:11pt;line-height:1.75;margin-bottom:14px;text-align:justify;}
  .info-table{width:100%;border-collapse:collapse;margin:14px 0 18px;font-size:10.5pt;}
  .info-table td{padding:7px 12px;border:1px solid #ccc;vertical-align:top;}
  .info-table td:first-child{font-weight:bold;width:36%;background:#f5f5f5;}
  .conditions{background:#fffbeb;border:1px solid #fbbf24;border-radius:6px;padding:14px 18px;margin:16px 0;font-size:10pt;color:#78350f;}
  .conditions strong{display:block;margin-bottom:6px;font-size:10.5pt;}
  .conditions ul{margin:0 0 0 18px;padding:0;}
  .conditions li{margin-bottom:5px;line-height:1.55;}
  .sig-section{margin-top:34px;}
  .stamp-circle{border:2.5px solid #1a3a6b;border-radius:50%;width:88px;height:88px;display:inline-flex;align-items:center;justify-content:center;text-align:center;font-size:7.5pt;font-weight:bold;color:#1a3a6b;margin:0 0 14px 0;line-height:1.4;}
  .sig-line{border-bottom:1.5px solid #000;height:28px;margin-bottom:5px;max-width:300px;}
  .sig-name{font-weight:bold;font-size:11pt;margin-bottom:2px;}
  .sig-label{font-size:10pt;color:#444;line-height:1.5;}
  .footer-note{margin-top:28px;border-top:1px solid #ddd;padding-top:12px;font-size:8.5pt;color:#777;text-align:center;}
</style>
</head>
<body>
<div class="letter">

  <div class="ltr-header">
    <div class="uni-name">MURANG'A UNIVERSITY OF TECHNOLOGY</div>
    <div class="office-name">OFFICE OF THE DEPUTY VICE-CHANCELLOR</div>
    <div class="office-name">ACADEMIC RESEARCH &amp; STUDENT AFFAIRS (ARSA)</div>
    <div class="contact-line">P.O. Box 75-10200, Murang'a &nbsp;|&nbsp; Tel: +254 60 2026000 &nbsp;|&nbsp; www.mut.ac.ke</div>
  </div>

  <div class="ref-date">
    <span><strong>Ref:</strong> {$ref_number}</span>
    <span><strong>Date:</strong> {$approval_date}</span>
  </div>

  <div class="addressee">
    <strong>{$student_name}</strong><br>
    Reg. No: <strong>{$reg_number}</strong><br>
    {$course}<br>
    Murang'a University of Technology
  </div>

  <div class="letter-title">APPROVAL LETTER &ndash; SPECIAL EXAMINATION REGISTRATION ({$app_type})</div>

  <div class="approved-badge">&#10003; &nbsp;APPROVED</div>

  <p class="body-text">
    Following your application for a Special Examination on grounds of <strong>{$app_type}</strong>,
    and upon review by your Head of Department, Dean, and the Registrar, the Office of the DVC
    Academic Research &amp; Student Affairs is pleased to inform you that your application has been
    <strong>APPROVED</strong>.
  </p>

  <p class="body-text">The details of your approved application are as follows:</p>

  <table class="info-table">
    <tr><td>Student Name</td><td>{$student_name}</td></tr>
    <tr><td>Registration Number</td><td>{$reg_number}</td></tr>
    <tr><td>Course</td><td>{$course}</td></tr>
    <tr><td>Application Type</td><td>{$app_type}</td></tr>
    <tr><td>Examination Period</td><td>{$exam_period}</td></tr>
    <tr><td>Units Applied For</td><td>{$units_raw}</td></tr>
    <tr><td>Grounds / Reason</td><td>{$reason}</td></tr>
  </table>


  <div class="sig-section">
    <div class="sig-label">Deputy Vice-Chancellor &ndash; Academic Research &amp; Student Affairs</div>
    <div class="sig-label">Murang'a University of Technology</div>
    <div class="sig-label" style="margin-top:5px;">Date: {$approval_date}</div>
  </div>

  <div class="footer-note">
    This is an official computer-generated letter issued by the MUT Document Management System.<br>
    For queries: registrar@mut.ac.ke &nbsp;|&nbsp; +254 60 2026000
  </div>

</div>
</body>
</html>
HTML;

    // ── Save letter as HTML file and attach so student can download it for Phase 2 upload ──
    $safe_name   = preg_replace('/[^A-Za-z0-9_\-]/', '_', $student['full_name']);
    $attach_path = sys_get_temp_dir() . '/MUT_Special_Exam_Approval_' . $safe_name . '_' . time() . '.html';
    file_put_contents($attach_path, $html);

    // ── Wrapper email body pointing student to the attachment ──
    $email_body = <<<BODY
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8">
<style>
  body{font-family:Arial,sans-serif;background:#f1f5f9;margin:0;padding:20px;}
  .wrap{max-width:600px;margin:0 auto;background:#fff;border-radius:10px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,.1);}
  .hdr{background:linear-gradient(135deg,#0f172a,#1a3a6b);color:#fff;padding:22px 30px;text-align:center;}
  .hdr h1{font-size:15pt;margin:0 0 3px;}.hdr p{opacity:.8;font-size:9pt;margin:0;}
  .strip{background:#d1fae5;color:#065f46;font-weight:bold;text-align:center;padding:9px;font-size:10pt;border-bottom:2px solid #6ee7b7;}
  .body{padding:24px 30px;font-size:10.5pt;color:#334155;line-height:1.7;}
  .note{background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:12px 18px;margin:14px 0;font-size:10.5pt;color:#1e40af;font-weight:600;text-align:center;}
  .steps{background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:14px 18px;margin:14px 0;font-size:10pt;color:#334155;}
  .steps ol{margin:8px 0 0 16px;padding:0;line-height:1.9;}
  .footer{background:#f8fafc;padding:12px 30px;text-align:center;font-size:8.5pt;color:#94a3b8;border-top:1px solid #e2e8f0;}
</style>
</head>
<body>
<div class="wrap">
  <div class="hdr">
    <h1>MUT Document System</h1>
    <p>Special Exam Approval Letter &nbsp;&bull;&nbsp; {$approval_date}</p>
  </div>
  <div class="strip">&#10003; &nbsp;YOUR SPECIAL EXAM APPLICATION HAS BEEN APPROVED</div>
  <div class="body">
    <p>Dear <strong>{$student_name}</strong>,</p>
    <p>Your Special Examination application has been reviewed and <strong>approved</strong> by the DVC Academic Research &amp; Student Affairs.</p>
    <div class="note">&#128438; &nbsp;Your official approval letter is attached to this email.</div>
    <div class="steps">
      <strong>Next Steps:</strong>
      <ol>
        <li>Download the attached approval letter.</li>
        <li>Log in to the <strong>MUT Document Management Portal</strong>.</li>
        <li>Go to <em>Special Exam &rarr; Upload Letter &amp; Fill Digital Form</em>.</li>
        <li>Upload this letter and complete the Special Exam Digital Registration Form.</li>
      </ol>
    </div>
  </div>
  <div class="footer">
    Murang'a University of Technology &nbsp;|&nbsp; registrar@mut.ac.ke &nbsp;|&nbsp; +254 60 2026000<br>
    This is an automated notification — please do not reply.
  </div>
</div>
</body>
</html>
BODY;

    $attachments = [['path' => $attach_path, 'name' => 'MUT_Special_Exam_Approval_Letter_' . $safe_name . '.html']];
    $result = sendEmail($student['email'], $student['full_name'], $subject, $email_body, $attachments);
    if (file_exists($attach_path)) @unlink($attach_path);
    return $result;
}


/**
 * Sends a formal rejection email when DVC rejects a Special Exam application.
 */
function sendSpecialExamRejectionEmail(array $student, array $sea, string $reason, string $dvc_name): array
{
    $date    = date('F j, Y');
    $name    = htmlspecialchars($student['full_name']);
    $reg     = htmlspecialchars($student['reg_number']);
    $type    = htmlspecialchars($sea['application_type'] ?? '');
    $dvc     = htmlspecialchars($dvc_name);
    $rsn     = nl2br(htmlspecialchars($reason));
    $subject = "Special Exam Application – Not Approved – MUT";

    $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
  body{font-family:'Times New Roman',Times,serif;background:#f0f0f0;margin:0;padding:24px;}
  .letter{max-width:700px;margin:0 auto;background:#fff;padding:48px 52px;box-shadow:0 2px 16px rgba(0,0,0,.12);}
  .ltr-header{text-align:center;border-bottom:3px double #1a3a6b;padding-bottom:16px;margin-bottom:20px;}
  .uni-name{font-size:14.5pt;font-weight:bold;text-transform:uppercase;color:#1a3a6b;margin-bottom:3px;}
  .office-name{font-size:10pt;font-weight:bold;text-transform:uppercase;color:#1a3a6b;}
  .contact-line{font-size:8.5pt;color:#555;margin-top:4px;}
  .ref-date{font-size:10.5pt;text-align:right;margin-bottom:18px;}
  .addressee{font-size:11pt;margin-bottom:18px;line-height:1.6;}
  .letter-title{text-align:center;font-size:12pt;font-weight:bold;text-decoration:underline;text-transform:uppercase;margin:16px 0 18px;color:#7f1d1d;}
  .body-text{font-size:11pt;line-height:1.75;margin-bottom:14px;text-align:justify;}
  .reason-box{background:#fef2f2;border:1px solid #fca5a5;border-radius:6px;padding:14px 18px;margin:16px 0;font-size:10.5pt;color:#7f1d1d;line-height:1.6;}
  .reason-box strong{display:block;margin-bottom:6px;}
  .sig-section{margin-top:32px;}
  .sig-line{border-bottom:1.5px solid #000;height:26px;margin-bottom:5px;max-width:300px;}
  .sig-name{font-weight:bold;font-size:11pt;margin-bottom:2px;}
  .sig-label{font-size:10pt;color:#444;line-height:1.5;}
  .footer-note{margin-top:26px;border-top:1px solid #ddd;padding-top:10px;font-size:8.5pt;color:#777;text-align:center;}
</style>
</head>
<body>
<div class="letter">

  <div class="ltr-header">
    <div class="uni-name">MURANG'A UNIVERSITY OF TECHNOLOGY</div>
    <div class="office-name">OFFICE OF THE DVC &ndash; ACADEMIC RESEARCH &amp; STUDENT AFFAIRS</div>
    <div class="contact-line">P.O. Box 75-10200, Murang'a &nbsp;|&nbsp; Tel: +254 60 2026000 &nbsp;|&nbsp; www.mut.ac.ke</div>
  </div>

  <div class="ref-date">Date: <strong>{$date}</strong></div>

  <div class="addressee">
    <strong>{$name}</strong><br>
    Reg. No: <strong>{$reg}</strong><br>
    Murang'a University of Technology
  </div>

  <div class="letter-title">SPECIAL EXAMINATION APPLICATION &ndash; NOT APPROVED ({$type})</div>

  <p class="body-text">
    Following a careful review of your Special Examination application on grounds of <strong>{$type}</strong>,
    I regret to inform you that your application has <strong>not been approved</strong> for the following reason(s):
  </p>

  <div class="reason-box">
    <strong>Reason for Non-Approval:</strong>
    {$rsn}
  </div>

  <p class="body-text">
    If you believe the stated concerns can be addressed, you are welcome to resubmit a new application
    through the MUT Student Document Portal with appropriate supporting documentation. For further clarification,
    please visit the Dean's Office or contact the Registrar.
  </p>

  <div class="sig-section">
    <div class="sig-line"></div>
    <div class="sig-name">{$dvc}</div>
    <div class="sig-label">Deputy Vice-Chancellor &ndash; Academic Research &amp; Student Affairs</div>
    <div class="sig-label">Murang'a University of Technology</div>
    <div class="sig-label" style="margin-top:5px;">Date: {$date}</div>
  </div>

  <div class="footer-note">
    MUT Document Management System &nbsp;|&nbsp; registrar@mut.ac.ke &nbsp;|&nbsp; +254 60 2026000
  </div>

</div>
</body>
</html>
HTML;

    return sendEmail($student['email'], $student['full_name'], $subject, $html);
}


/**
 * Generic status update email — call from anywhere in the system.
 */
function sendStatusUpdateEmail(string $to_email, string $to_name, string $doc_title, string $new_status, string $message = ''): array
{
    $date    = date('F j, Y');
    $nameE   = htmlspecialchars($to_name);
    $titleE  = htmlspecialchars($doc_title);
    $statusE = htmlspecialchars($new_status);
    $msgE    = $message
        ? '<div style="margin-top:14px;padding:12px 18px;background:#f0f9ff;border-left:4px solid #0ea5e9;border-radius:4px;font-size:10.5pt;color:#0c4a6e;">'
          . htmlspecialchars($message) . '</div>'
        : '';
    $subject = "Application Update: {$doc_title} – MUT Portal";

    $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
  body{font-family:Arial,sans-serif;background:#f1f5f9;margin:0;padding:24px;}
  .wrap{max-width:600px;margin:0 auto;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,.1);}
  .hdr{background:linear-gradient(135deg,#0f172a 0%,#1e3a5f 100%);color:white;padding:28px 34px;text-align:center;}
  .hdr h1{font-size:15pt;margin:0 0 4px;}
  .hdr p{opacity:.75;font-size:9.5pt;margin:0;}
  .body{padding:28px 34px;}
  .status-pill{display:inline-block;padding:6px 22px;border-radius:20px;font-weight:bold;font-size:10pt;background:#d1fae5;color:#065f46;border:1px solid #6ee7b7;margin:12px 0;}
  p{font-size:10.5pt;line-height:1.7;color:#334155;margin-bottom:12px;}
  .btn{display:inline-block;padding:12px 30px;background:#0d9488;color:white;border-radius:8px;text-decoration:none;font-weight:bold;font-size:10.5pt;margin-top:16px;}
  .footer{background:#f8fafc;padding:16px 34px;text-align:center;font-size:9pt;color:#94a3b8;border-top:1px solid #e2e8f0;}
</style>
</head>
<body>
<div class="wrap">
  <div class="hdr">
    <h1>MUT Document System</h1>
    <p>Application Status Update &nbsp;&bull;&nbsp; {$date}</p>
  </div>
  <div class="body">
    <p>Dear <strong>{$nameE}</strong>,</p>
    <p>Your application <strong>&ldquo;{$titleE}&rdquo;</strong> has a new status update:</p>
    <div class="status-pill">{$statusE}</div>
    {$msgE}
    <p>Please log in to the MUT Student Portal to view the full details and take any required action.</p>
    <a class="btn" href="http://localhost/mut_dms/index.php">View in Portal &rarr;</a>
  </div>
  <div class="footer">
    Murang'a University of Technology &nbsp;|&nbsp; This is an automated notification &mdash; please do not reply.
  </div>
</div>
</body>
</html>
HTML;

    return sendEmail($to_email, $to_name, $subject, $html);
}

/**
 * Sends a payment notification email to the student after Dean approves Resit/Retake.
 * Called from process_approval.php on dean_approve when module_type is Resit or Retake.
 *
 * @param array  $student      ['full_name', 'email', 'reg_number', 'course']
 * @param string $module_type  'Resit' or 'Retake'
 * @param string $doc_title    Document title
 * @param string $exam_period  e.g. "April 2026"
 * @param string $units        Comma-separated unit codes
 */
function sendPaymentNotificationEmail(array $student, string $module_type, string $doc_title, string $exam_period = '', string $units = ''): array
{
    $date        = date('F j, Y');
    $name        = htmlspecialchars($student['full_name']);
    $reg         = htmlspecialchars($student['reg_number']);
    $course      = htmlspecialchars($student['course'] ?? 'N/A');
    $title       = htmlspecialchars($doc_title);
    $period      = htmlspecialchars($exam_period);
    $units_disp  = htmlspecialchars($units);

    if (strtolower($module_type) === 'resit') {
        $amount_total = '800';
        $type_label   = 'RESIT EXAMINATION';
        $rows = "
          <tr><td>1.</td><td>Examination fee</td><td><strong>Ksh 800</strong></td></tr>
          <tr style='font-weight:700;background:#f5f5f5;'><td colspan='2'>TOTAL</td><td>Ksh 800</td></tr>";
        $amount_words = 'eight hundred shillings only';
        $subject_unit = $units_disp ? htmlspecialchars($units) : $title;
    } else {
        $amount_total = '10,023';
        $type_label   = 'RETAKE EXAMINATION';
        $rows = "
          <tr><td>1.</td><td>Tuition fee</td><td>Ksh 1,333</td></tr>
          <tr><td>2.</td><td>Statutory fee</td><td>Ksh 8,690</td></tr>
          <tr style='font-weight:700;background:#f5f5f5;'><td colspan='2'>TOTAL</td><td>Ksh 10,023</td></tr>";
        $amount_words = 'ten thousand and twenty-three shillings only';
        $subject_unit = $units_disp ? htmlspecialchars($units) : $title;
    }

    $subject = "RE: PAYMENT FOR {$type_label} ({$subject_unit}) EXAMINATION – MUT";

    $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
  body{font-family:'Times New Roman',Times,serif;background:#f0f0f0;margin:0;padding:24px;}
  .letter{max-width:700px;margin:0 auto;background:#fff;padding:50px 56px;box-shadow:0 2px 16px rgba(0,0,0,.12);}
  .ltr-header{text-align:center;border-bottom:3px double #1a3a6b;padding-bottom:18px;margin-bottom:22px;}
  .uni-name{font-size:14pt;font-weight:bold;text-transform:uppercase;color:#1a3a6b;margin-bottom:3px;}
  .office-name{font-size:10pt;font-weight:bold;text-transform:uppercase;color:#1a3a6b;}
  .contact{font-size:8.5pt;color:#555;margin-top:4px;}
  .ref-date{text-align:right;font-size:10.5pt;margin-bottom:16px;}
  .addressee{font-size:11pt;margin-bottom:18px;line-height:1.7;}
  .subject-line{font-size:11.5pt;font-weight:bold;text-transform:uppercase;text-decoration:underline;margin:14px 0 18px;}
  .body-text{font-size:11pt;line-height:1.8;margin-bottom:14px;text-align:justify;}
  .fee-table{width:60%;border-collapse:collapse;margin:16px 0 20px;font-size:10.5pt;}
  .fee-table th{background:#1a3a6b;color:#fff;padding:8px 12px;text-align:left;}
  .fee-table td{padding:7px 12px;border:1px solid #ccc;}
  .amount-bold{font-weight:bold;color:#1a3a6b;}
  .sig-section{margin-top:32px;}
  .sig-line{border-bottom:1.5px solid #000;height:26px;margin-bottom:5px;max-width:260px;}
  .sig-name{font-weight:bold;font-size:11pt;}
  .sig-label{font-size:10pt;color:#444;line-height:1.5;}
  .footer{margin-top:24px;border-top:1px solid #ddd;padding-top:10px;font-size:8pt;color:#777;text-align:center;}
</style>
</head>
<body>
<div class="letter">
  <div class="ltr-header">
    <div class="uni-name">MURANG'A UNIVERSITY OF TECHNOLOGY</div>
    <div class="office-name">OFFICE OF THE REGISTRAR (ACADEMIC &amp; STUDENT AFFAIRS)</div>
    <div class="contact">P.O. Box 75-10200, Murang'a &nbsp;|&nbsp; Tel: 0711463 515 &nbsp;|&nbsp; Email: info@mut.ac.ke</div>
  </div>

  <div class="ref-date">Date: <strong>{$date}</strong></div>

  <div class="addressee">
    <strong>{$name}</strong><br>
    Reg. No: <strong>{$reg}</strong><br>
    {$course}<br>
    Murang'a University of Technology
  </div>

  <div class="subject-line">RE: PAYMENT FOR {$type_label} ({$subject_unit}) EXAMINATION</div>

  <p class="body-text">The above subject matter refers.</p>

  <p class="body-text">
    This is to inform you that your request for {$type_label} of <strong>{$subject_unit}</strong>
    has been <strong>approved</strong>. The fee payable is <span class="amount-bold">Ksh {$amount_total} ({$amount_words})</span>.
  </p>

  <p class="body-text">Broken down as follows:</p>

  <table class="fee-table">
    <thead><tr><th>S/No.</th><th>Description</th><th>Amount</th></tr></thead>
    <tbody>{$rows}</tbody>
  </table>

  <p class="body-text">
    Please proceed to payment of your Resit Examination paper for finalization in the Finance Office. An approved letter will be sent to you after.
    Carry that letter and your student ID to the specific examination.
  </p>

  <p class="body-text">Yours sincerely,</p>

  <div class="sig-section">
    <div class="sig-line"></div>
    <div class="sig-name">For: The Registrar</div>
    <div class="sig-label">Academic &amp; Student Affairs</div>
    <div class="sig-label">Murang'a University of Technology</div>
  </div>

  <div class="footer">
    MUT Document Management System &nbsp;|&nbsp; info@mut.ac.ke &nbsp;|&nbsp; +254 60 2026000
  </div>
</div>
</body>
</html>
HTML;

    return sendEmail($student['email'], $student['full_name'], $subject, $html);
}


/**
 * Sends the fully completed/finalised form to the student.
 * The email body IS the complete filled form — ready to print/download.
 */
function sendFinalisedDocumentEmail(array $student, array $doc, string $registrar_name): array
{
    $date       = date('F j, Y');
    $stamp_date = strtoupper(date('d M Y'));
    $name       = htmlspecialchars($student['full_name']);
    $reg        = htmlspecialchars($student['reg_number']);
    $phone      = htmlspecialchars($student['phone'] ?? '');
    $email_addr = htmlspecialchars($student['email']);
    $course     = htmlspecialchars($student['course'] ?? '');
    $mod        = $doc['module_type'] ?? $doc['exam_type'] ?? '';
    $exam_month = htmlspecialchars($doc['exam_month'] ?? '');
    $exam_year  = htmlspecialchars($doc['exam_year']  ?? '');
    $dept       = htmlspecialchars($doc['dept_name']  ?? 'N/A');

    $cod_name  = htmlspecialchars($doc['cod_signer_name']  ?? '');
    $cod_date  = !empty($doc['cod_signed_at'])  ? date('F j, Y', strtotime($doc['cod_signed_at']))  : $date;
    $dean_name = htmlspecialchars($doc['dean_signer_name'] ?? '');
    $dean_date = !empty($doc['dean_signed_at']) ? date('F j, Y', strtotime($doc['dean_signed_at'])) : $date;
    $sig_date  = !empty($doc['student_signature_date'])
                    ? date('F j, Y', strtotime($doc['student_signature_date']))
                    : (!empty($doc['upload_date']) ? date('F j, Y', strtotime($doc['upload_date'])) : $date);

    if ($mod === 'Special_Exam' || $mod === 'Special') {
        $amount_display = 'N/A'; $type_label = 'Special Examination';
        $chk_s = 'checked'; $chk_r = ''; $chk_t = '';
    } elseif ($mod === 'Resit') {
        $amount_display = 'KSh 800'; $type_label = 'Resit Examination';
        $chk_s = ''; $chk_r = 'checked'; $chk_t = '';
    } elseif ($mod === 'Retake') {
        $amount_display = 'KSh 10,023'; $type_label = 'Retake Examination';
        $chk_s = ''; $chk_r = ''; $chk_t = 'checked';
    } else {
        $amount_display = !empty($doc['amount_paid']) ? htmlspecialchars($doc['amount_paid']) : 'N/A';
        $type_label = htmlspecialchars($mod);
        $chk_s = ''; $chk_r = ''; $chk_t = '';
    }

    // Build units rows
    $units_html = '';
    $units_arr  = $doc['units'] ?? [];
    $filled = 0;
    if (!empty($units_arr) && is_array($units_arr)) {
        foreach ($units_arr as $i => $u) {
            $units_html .= '<tr>'
                . '<td style="text-align:center;padding:6px 8px;border:1px solid #000;">' . ($i+1) . '</td>'
                . '<td style="text-align:center;padding:6px 8px;border:1px solid #000;">' . htmlspecialchars($u['unit_code'] ?? '') . '</td>'
                . '<td style="padding:6px 8px;border:1px solid #000;">' . htmlspecialchars($u['unit_title'] ?? '') . '</td>'
                . '</tr>';
        }
        $filled = count($units_arr);
    }
    for ($e = $filled; $e < 5; $e++) {
        $units_html .= '<tr>'
            . '<td style="text-align:center;padding:6px 8px;border:1px solid #000;">' . ($e+1) . '</td>'
            . '<td style="padding:6px 8px;border:1px solid #000;">&nbsp;</td>'
            . '<td style="padding:6px 8px;border:1px solid #000;">&nbsp;</td>'
            . '</tr>';
    }

    $subject = "Your {$type_label} Form – MUT Portal (Print & Keep)";

    // ── Build the form HTML (used both as attachment and email body preview) ──
    $form_html = <<<FORMHTML
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>MUT Exam Registration Form – {$name}</title>
<style>
  body{font-family:'Times New Roman',Times,serif;margin:30px;font-size:11pt;color:#000;}
  .fhdr{text-align:center;margin-bottom:16px;position:relative;}
  .fcode{position:absolute;top:0;right:0;font-size:9pt;font-weight:bold;}
  .uni{font-size:13pt;font-weight:bold;text-transform:uppercase;margin-bottom:3px;}
  .office{font-size:10pt;font-weight:bold;text-transform:uppercase;margin-bottom:3px;}
  .ftitle{font-size:11pt;font-weight:bold;text-decoration:underline;margin-bottom:14px;}
  table.ft{width:100%;border-collapse:collapse;margin-bottom:12px;}
  table.ft th{background:#d9d9d9;font-weight:bold;text-align:center;padding:7px;border:1px solid #000;}
  table.ft td{padding:7px;border:1px solid #000;vertical-align:top;}
  .decl{font-size:9pt;text-align:justify;margin-bottom:10px;line-height:1.5;}
  .sig{font-style:italic;font-family:Georgia,'Times New Roman',serif;font-size:15pt;color:#1a1a2e;border-bottom:2px solid #000;display:inline-block;min-width:200px;padding:2px 0;}
  .arow{display:flex;margin-bottom:10px;align-items:center;}
  .albl{width:130px;font-weight:bold;font-size:10pt;}
  .afld{flex:1;border-bottom:1px solid #000;margin:0 8px;min-height:20px;padding:2px 4px;font-style:italic;font-family:Georgia,'Times New Roman',serif;font-size:12pt;color:#1a1a2e;}
  .adt{width:110px;border-bottom:1px solid #000;font-size:9.5pt;color:#1a1a2e;padding:2px 4px;}
  .pay{margin-top:14px;border-top:2px solid #000;padding-top:10px;}
  .pay-title{font-weight:bold;font-style:italic;margin-bottom:8px;}
  .stmp{display:inline-flex;flex-direction:column;align-items:center;justify-content:center;
    border:2.5px solid #1a3a6b;border-radius:3px;padding:8px 12px;min-width:130px;
    background:#fff;position:relative;}
  .stmp-in{position:absolute;inset:2px;border:1px solid #1a3a6b;border-radius:1px;}
  .sfo{font-weight:900;text-transform:uppercase;letter-spacing:1.5px;color:#1a3a6b;font-size:.62rem;}
  .smut{font-weight:700;text-transform:uppercase;color:#1a3a6b;font-size:.52rem;margin-top:2px;text-align:center;line-height:1.3;}
  .sdt{font-weight:900;color:#c0392b;margin:4px 0 3px;font-size:.78rem;letter-spacing:1.5px;}
  .spo{color:#1a3a6b;font-size:.44rem;text-align:center;line-height:1.4;}
  .siso{margin-top:4px;width:28px;height:28px;border:1px solid #1a3a6b;border-radius:50%;
    display:inline-flex;align-items:center;justify-content:center;text-align:center;
    font-size:.38rem;font-weight:700;color:#1a3a6b;line-height:1.2;}
</style>
</head>
<body>
  <div class="fhdr">
    <div class="fcode">MUT/F/ASAA/015</div>
    <div class="uni">MURANG'A UNIVERSITY OF TECHNOLOGY</div>
    <div class="office">OFFICE OF REGISTRAR (Academic and Student Affairs)</div>
    <div class="ftitle">SPECIAL/RESIT/RETAKE EXAM REGISTRATION FORM</div>
  </div>

  <div style="margin-bottom:12px;">
    <strong>1. For which Examination do you wish to register for?</strong>
    <table class="ft" style="margin-top:7px;">
      <tr>
        <td style="width:28px;text-align:center;"><input type="checkbox" {$chk_s} disabled></td><td>Special Exam</td>
        <td style="width:28px;text-align:center;"><input type="checkbox" {$chk_r} disabled></td><td>Resit Exam</td>
        <td style="width:28px;text-align:center;"><input type="checkbox" {$chk_t} disabled></td><td>Retake Exam</td>
      </tr>
    </table>
  </div>

  <div style="margin-bottom:12px;">
    <strong>2. Examination Period</strong>
    <table class="ft" style="margin-top:7px;">
      <tr><th>Month</th><th>Year</th></tr>
      <tr><td style="text-align:center;">{$exam_month}</td><td style="text-align:center;">{$exam_year}</td></tr>
    </table>
  </div>

  <div style="margin-bottom:12px;">
    <strong>3. Personal Details</strong>
    <table class="ft" style="margin-top:7px;">
      <tr><td style="width:40%;">Student Name</td><td>{$name}</td></tr>
      <tr><td>Student Registration Number</td><td>{$reg}</td></tr>
      <tr><td>Cell phone</td><td>{$phone}</td></tr>
      <tr><td>Email</td><td>{$email_addr}</td></tr>
      <tr><td>Course</td><td>{$course}</td></tr>
      <tr><td>Department</td><td>{$dept}</td></tr>
    </table>
  </div>

  <div style="margin-bottom:12px;">
    <strong>4. Units to be written</strong>
    <table class="ft" style="margin-top:7px;">
      <thead><tr><th style="width:46px;">S/No</th><th>Unit Code</th><th>Unit Title</th></tr></thead>
      <tbody>{$units_html}</tbody>
    </table>
  </div>

  <div style="margin-bottom:14px;">
    <p class="decl"><strong>DECLARATION BY STUDENT:</strong> I agree to abide by the rules and procedures governing Murang'a University of Technology examinations. I understand that I must take my identity document with me to write my examination and that I have 14 consecutive days from the Examination Registration Closing Date to follow up on my examination registration status. I also declare that I have successfully completed the compulsory assignments for the above subject(s).</p>
    <table style="border:none;width:100%;">
      <tr>
        <td style="border:none;width:60%;vertical-align:bottom;">
          <div style="font-weight:bold;margin-bottom:5px;">Student Signature</div>
          <span class="sig">{$name}</span>
          <div style="font-size:8pt;color:#666;font-style:italic;margin-top:3px;">Signature auto-generated from login credentials</div>
        </td>
        <td style="border:none;vertical-align:bottom;text-align:right;">
          <div style="font-weight:bold;margin-bottom:5px;">Date</div>
          <div style="border-bottom:1px solid #000;padding:3px 10px;">{$sig_date}</div>
        </td>
      </tr>
    </table>
  </div>

  <div style="margin-top:16px;">
    <div style="font-weight:bold;margin-bottom:7px;">Recommended By:</div>
    <div class="arow">
      <span class="albl">CoD (Name)</span>
      <span class="afld">{$cod_name}</span>
      <span style="margin:0 6px;font-size:10pt;">Date:</span>
      <span class="adt">{$cod_date}</span>
    </div>
    <div style="font-weight:bold;margin-bottom:7px;margin-top:10px;">Approved By:</div>
    <div class="arow">
      <span class="albl">Dean (Name)</span>
      <span class="afld">{$dean_name}</span>
      <span style="margin:0 6px;font-size:10pt;">Date:</span>
      <span class="adt">{$dean_date}</span>
    </div>
  </div>

  <div class="pay">
    <div class="pay-title">Confirmation of Payment:</div>
    <div class="arow" style="align-items:center;">
      <span class="albl">Amount Paid:</span>
      <span class="afld" style="flex:0.5;font-style:normal;font-size:11pt;">{$amount_display}</span>
      <span style="margin:0 16px;"></span>
      <span class="albl">Signature &amp; Stamp:</span>
      <span style="flex:0.5;">
        <span class="stmp">
          <span class="stmp-in"></span>
          <span class="sfo">FINANCE OFFICE</span>
          <span class="smut">MURANG'A UNIVERSITY<br>OF TECHNOLOGY</span>
          <span class="sdt">{$stamp_date}</span>
          <span class="spo">P.O. Box 75-10200, Murang'a<br>Tel: 0711463 515</span>
          <span class="siso">MUT IS<br>ISO 9001:<br>2015 CERT</span>
        </span>
      </span>
    </div>
  </div>

</body>
</html>
FORMHTML;

    // ── Save form as a temporary HTML file to attach ──
    $safe_name  = preg_replace('/[^A-Za-z0-9_\-]/', '_', $student['full_name']);
    $attach_dir = sys_get_temp_dir();
    $attach_path = $attach_dir . '/MUT_Form_' . $safe_name . '_' . time() . '.html';
    file_put_contents($attach_path, $form_html);

    // ── Simple email body — just notify and point to attachment ──
    $html = <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8">
<style>
  body{font-family:Arial,sans-serif;background:#f1f5f9;margin:0;padding:20px;}
  .wrap{max-width:600px;margin:0 auto;background:#fff;border-radius:10px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,.1);}
  .hdr{background:linear-gradient(135deg,#0f172a,#1a3a6b);color:#fff;padding:22px 30px;text-align:center;}
  .hdr h1{font-size:15pt;margin:0 0 3px;}
  .hdr p{opacity:.8;font-size:9pt;margin:0;}
  .strip{background:#d1fae5;color:#065f46;font-weight:bold;text-align:center;padding:9px;font-size:10pt;border-bottom:2px solid #6ee7b7;}
  .body{padding:24px 30px;font-size:10.5pt;color:#334155;line-height:1.7;}
  .note{background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;padding:12px 18px;margin:14px 0;font-size:10.5pt;color:#0369a1;font-weight:600;text-align:center;}
  .footer{background:#f8fafc;padding:12px 30px;text-align:center;font-size:8.5pt;color:#94a3b8;border-top:1px solid #e2e8f0;}
</style>
</head>
<body>
<div class="wrap">
  <div class="hdr">
    <h1>MUT Document System</h1>
    <p>Application Finalised &nbsp;&bull;&nbsp; {$date}</p>
  </div>
  <div class="strip">&#10003; &nbsp;YOUR APPLICATION HAS BEEN FINALISED</div>
  <div class="body">
    <p>Dear <strong>{$name}</strong>,</p>
    <p>Your <strong>{$type_label}</strong> application has been fully processed and finalised by the Finance Office.</p>
    <div class="note">&#128438; &nbsp;Your completed form is attached. Open it, then print or save it to your device.</div>
    <p>Please present the printed form together with your student ID at the Examinations room for the specific exam.</p>
  </div>
  <div class="footer">
    Murang'a University of Technology &nbsp;|&nbsp; finance@mut.ac.ke &nbsp;|&nbsp; +254 60 2026000<br>
    This is an automated notification — please do not reply.
  </div>
</div>
</body>
</html>
HTML;

    $attachments = [['path' => $attach_path, 'name' => 'MUT_Exam_Form_' . $safe_name . '.html']];
    $result = sendEmail($student['email'], $student['full_name'], $subject, $html, $attachments);

    // Clean up temp file
    if (file_exists($attach_path)) @unlink($attach_path);

    return $result;
}

/**
 * ═══════════════════════════════════════════════════════
 *  FINANCE OFFICE — APPROVAL / REJECTION LETTER
 *  Sends formal letter to student for Bursary or Fee Adjustment.
 * ═══════════════════════════════════════════════════════
 */
function sendFinanceApprovalEmail(
    array  $student,
    string $module_type,
    string $doc_title,
    string $decision,
    string $reason,
    string $finance_name
): array {
    $date       = date('F j, Y');
    $ref        = 'MUT/FIN/' . date('Y') . '/' . strtoupper(substr(md5($student['reg_number'] . time()), 0, 6));
    $name       = htmlspecialchars($student['full_name']);
    $reg        = htmlspecialchars($student['reg_number']);
    $course     = htmlspecialchars($student['course'] ?? 'N/A');
    $title_esc  = htmlspecialchars($doc_title);
    $mod        = htmlspecialchars($module_type);
    $fin        = htmlspecialchars($finance_name);
    $is_ok      = ($decision === 'approved');
    $subject    = $is_ok
        ? "{$mod} Application Approved – MUT Finance Office"
        : "{$mod} Application – Not Approved – MUT Finance Office";
    $badge      = $is_ok
        ? '<div style="text-align:center;margin:12px 0;"><span style="background:#d1fae5;color:#065f46;padding:7px 28px;border-radius:20px;font-weight:bold;font-size:11pt;border:1px solid #6ee7b7;">&#10003; &nbsp;APPROVED</span></div>'
        : '<div style="text-align:center;margin:12px 0;"><span style="background:#fee2e2;color:#7f1d1d;padding:7px 28px;border-radius:20px;font-weight:bold;font-size:11pt;border:1px solid #fca5a5;">&#10007; &nbsp;NOT APPROVED</span></div>';
    $body_p     = $is_ok
        ? "Following a review of your <strong>{$mod}</strong> application, the Finance Office is pleased to inform you that your application has been <strong>approved</strong>. Your student portal will be updated within 3 days."
        : "Following a review of your <strong>{$mod}</strong> application, the Finance Office regrets to inform you that your application has <strong>not been approved</strong> at this time.Visit Finance office for further information";
    $reason_blk = '';
    if (!$is_ok && !empty($reason)) {
        $rsn = nl2br(htmlspecialchars($reason));
        $reason_blk = '<div style="background:#fef2f2;border:1px solid #fca5a5;border-radius:8px;padding:14px 18px;margin:16px 0;font-size:11pt;color:#7f1d1d;line-height:1.6;"><strong style="display:block;margin-bottom:6px;">Reason:</strong>' . $rsn . '</div><p style="font-size:11pt;line-height:1.75;text-align:justify;">If you believe this decision can be reconsidered, please visit the Finance Office in person or contact the Registrar for guidance.</p>';
    }
    $html = <<<HTML
<!DOCTYPE html><html><head><meta charset="UTF-8"><style>
body{font-family:'Times New Roman',Times,serif;background:#f0f0f0;margin:0;padding:24px;}
.letter{max-width:720px;margin:0 auto;background:#fff;padding:50px 56px;box-shadow:0 2px 16px rgba(0,0,0,.12);}
.lhdr{text-align:center;border-bottom:3px double #0369a1;padding-bottom:18px;margin-bottom:22px;}
.uni{font-size:14.5pt;font-weight:bold;text-transform:uppercase;color:#0369a1;margin-bottom:4px;}
.off{font-size:10pt;font-weight:bold;text-transform:uppercase;color:#0369a1;}
.ct{font-size:8.5pt;color:#555;margin-top:4px;}
.rd{display:flex;justify-content:space-between;font-size:10.5pt;margin-bottom:18px;}
.addr{font-size:11pt;margin-bottom:18px;line-height:1.6;}
.ltitle{text-align:center;font-size:12pt;font-weight:bold;text-decoration:underline;text-transform:uppercase;margin:16px 0 18px;color:#0369a1;}
.bt{font-size:11pt;line-height:1.75;margin-bottom:14px;text-align:justify;}
.itbl{width:100%;border-collapse:collapse;margin:14px 0;font-size:10.5pt;}
.itbl td{padding:7px 12px;border:1px solid #ccc;}
.itbl td:first-child{font-weight:bold;width:36%;background:#f5f5f5;}
.sig-sec{margin-top:34px;}
.stmpw{display:inline-flex;flex-direction:column;align-items:center;justify-content:center;border:2.5px solid #0369a1;border-radius:3px;padding:10px 14px;min-width:120px;background:#fff;position:relative;margin:0 0 14px;}
.stmpi{position:absolute;inset:2px;border:1px solid #0369a1;border-radius:1px;}
.st{font-weight:900;text-transform:uppercase;letter-spacing:1.5px;color:#0369a1;font-size:.65rem;}
.ss{font-weight:700;text-transform:uppercase;color:#0369a1;font-size:.52rem;text-align:center;line-height:1.3;margin-top:2px;}
.sd{font-weight:900;color:#c0392b;margin:4px 0;font-size:.78rem;letter-spacing:1.5px;}
.sc{color:#0369a1;font-size:.44rem;text-align:center;line-height:1.4;}
.sline{border-bottom:1.5px solid #000;height:28px;margin-bottom:5px;max-width:300px;}
.sname{font-weight:bold;font-size:11pt;}
.slbl{font-size:10pt;color:#444;line-height:1.5;}
.fn{margin-top:28px;border-top:1px solid #ddd;padding-top:12px;font-size:8.5pt;color:#777;text-align:center;}
</style></head><body><div class="letter">
<div class="lhdr"><div class="uni">MURANG'A UNIVERSITY OF TECHNOLOGY</div><div class="off">FINANCE OFFICE</div><div class="ct">P.O. Box 75-10200, Murang'a &nbsp;|&nbsp; Tel: +254 60 2026000 &nbsp;|&nbsp; finance@mut.ac.ke</div></div>
<div class="rd"><span><strong>Ref:</strong> {$ref}</span><span><strong>Date:</strong> {$date}</span></div>
<div class="addr"><strong>{$name}</strong><br>Reg. No: <strong>{$reg}</strong><br>{$course}<br>Murang'a University of Technology</div>
<div class="ltitle">RE: {$mod} APPLICATION – {$title_esc}</div>
{$badge}
<p class="bt">{$body_p}</p>
<table class="itbl"><tr><td>Application Type</td><td>{$mod}</td></tr><tr><td>Document Title</td><td>{$title_esc}</td></tr><tr><td>Student Name</td><td>{$name}</td></tr><tr><td>Registration No.</td><td>{$reg}</td></tr><tr><td>Decision Date</td><td>{$date}</td></tr><tr><td>Processed By</td><td>Finance Office</td></tr></table>
{$reason_blk}
<p class="bt">Yours sincerely,</p>
<div class="sig-sec">
<div class="stmpw"><div class="stmpi"></div><div class="st">FINANCE OFFICE</div><div class="ss">MURANG'A UNIVERSITY<br>OF TECHNOLOGY</div><div class="sd">{$date}</div><div class="sc">finance@mut.ac.ke</div></div><br>
<div class="sline"></div><div class="sname">{$fin}</div><div class="slbl">Finance Office</div><div class="slbl">Murang'a University of Technology</div>
</div>
<div class="fn">This is an official notification from the MUT Document Management System.<br>For queries: finance@mut.ac.ke &nbsp;|&nbsp; +254 60 2026000</div>
</div></body></html>
HTML;
    return sendEmail($student['email'], $student['full_name'], $subject, $html);
}


/**
 * ═══════════════════════════════════════════════════════
 *  FINANCE OFFICE — CUSTOM EMAIL TO STUDENT
 *  Called when Finance uses "Write to Student" compose.
 * ═══════════════════════════════════════════════════════
 */
function sendFinanceCustomEmail(
    string $to_email,
    string $to_name,
    string $subject,
    string $body_text,
    string $finance_name
): array {
    $date  = date('F j, Y');
    $nameE = htmlspecialchars($to_name);
    $fin   = htmlspecialchars($finance_name);
    $bodyE = nl2br(htmlspecialchars($body_text));
    $html  = <<<HTML
<!DOCTYPE html><html><head><meta charset="UTF-8"><style>
body{font-family:Arial,sans-serif;background:#f0f9ff;margin:0;padding:24px;}
.wrap{max-width:640px;margin:0 auto;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,.1);}
.hdr{background:linear-gradient(135deg,#0369a1,#0284c7);color:#fff;padding:24px 32px;}
.hdr h1{font-size:14pt;margin:0 0 4px;}.hdr p{opacity:.8;font-size:9pt;margin:0;}
.strip{background:#e0f2fe;color:#0369a1;font-weight:bold;text-align:center;padding:9px;font-size:9.5pt;}
.body{padding:28px 32px;font-size:11pt;color:#1e293b;line-height:1.8;}
.msg{background:#f8fafc;border-left:4px solid #0ea5e9;border-radius:0 8px 8px 0;padding:14px 18px;margin:16px 0;font-size:11pt;color:#1e293b;line-height:1.8;}
.sig{margin-top:24px;border-top:1px solid #e2e8f0;padding-top:16px;font-size:10.5pt;color:#334155;}
.sig strong{display:block;font-size:11pt;color:#0369a1;}
.footer{background:#f8fafc;padding:12px 32px;text-align:center;font-size:8.5pt;color:#94a3b8;border-top:1px solid #e2e8f0;}
</style></head><body><div class="wrap">
<div class="hdr"><h1>MUT Finance Office</h1><p>Message from Finance &nbsp;&bull;&nbsp; {$date}</p></div>
<div class="strip">&#128394; &nbsp;Message from the Finance Office</div>
<div class="body"><p>Dear <strong>{$nameE}</strong>,</p><div class="msg">{$bodyE}</div>
<div class="sig"><strong>{$fin}</strong>Finance Office<br>Murang'a University of Technology<br>finance@mut.ac.ke &nbsp;|&nbsp; +254 60 2026000</div></div>
<div class="footer">MUT Document Management System &nbsp;|&nbsp; This is an official communication &mdash; please do not reply directly.</div>
</div></body></html>
HTML;
    return sendEmail($to_email, $to_name, $subject, $html);
}
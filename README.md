# MUT Document Management System (MUT-DMS)

A web-based document management and approval workflow system built for **Murang'a University of Technology (MUT)**. Developed as a final year capstone project for the Bachelor of Business and Information Technology (BBIT) programme.

---

## 📌 About the Project

MUT-DMS automates the university's multi-level document submission and approval process. Students submit documents online, which are then routed through the relevant approval chain depending on the document type — eliminating manual paperwork and reducing processing delays.

---

## ✅ Features

- **Multi-role authentication** — Student, COD, Dean, Registrar, Finance Officer, DVC ARSA, Super Administrator
- **5 document types** — Resit, Retake, Special Exam, Bursary, Fee Adjustment
- **Automated approval workflows** — each document type follows its own routing chain
- **Email notifications** — PHPMailer via Gmail SMTP notifies users at every approval stage
- **Role-based dashboards** — each role sees only what is relevant to them
- **Audit logging** — all system actions are tracked and logged
- **Document upload & management** — file attachments supported per submission
- **Reports & analytics** — dashboards with charts for document status tracking
- **Password security** — bcrypt hashing for all user passwords
- **Forgot password / reset** — secure password recovery flow
- **Campus news carousel** — homepage slideshow with university images

---

## 🔄 Approval Workflows

| Document Type     | Approval Chain                          |
|-------------------|-----------------------------------------|
| Resit / Retake    | Student → COD → Dean → Finance          |
| Special Exam (P1) | Student → Dean → Registrar → DVC ARSA  |
| Special Exam (P2) | Student → COD → Dean → Finance          |
| Bursary           | Student → Finance                       |
| Fee Adjustment    | Student → Registrar → Finance           |

---

## 🛠️ Technologies Used

| Layer       | Technology                        |
|-------------|-----------------------------------|
| Backend     | PHP 8.2                           |
| Database    | MySQL                             |
| Frontend    | HTML5, CSS3, JavaScript           |
| Email       | PHPMailer 7.0.2 (Gmail SMTP)      |
| Server      | XAMPP (Apache + MySQL)            |
| Icons       | Font Awesome                      |
| Charts      | Chart.js                          |

---

## ⚙️ Installation & Setup

### Requirements
- XAMPP (or any Apache + PHP 8.2 + MySQL stack)
- PHP 8.2+
- A Gmail account with an App Password enabled

### Steps

1. **Clone the repository**
   ```bash
   git clone https://github.com/YOUR_USERNAME/mut-document-management-system.git
   ```

2. **Move to your XAMPP htdocs folder**
   ```
   C:/xampp/htdocs/mut_dms/
   ```

3. **Set up the database**
   - Open phpMyAdmin (http://localhost/phpmyadmin)
   - Create a database called `mut_dms`
   - Import the file: `mut_dms.sql.`

4. **Configure the database connection**
   - Rename `db_config.example.php` to `db_config.php`
   - Update with your XAMPP credentials:
     ```php
     define('DB_HOST', 'localhost');
     define('DB_USERNAME', 'root');
     define('DB_PASSWORD', '');
     define('DB_NAME', 'mut_dms');
     ```

5. **Configure email (PHPMailer)**
   - Rename `mailer.example.php` to `mailer.php`
   - Update with your Gmail address and App Password:
     ```php
     define('MAIL_FROM_EMAIL',    'your_email@gmail.com');
     define('MAIL_SMTP_USERNAME', 'your_email@gmail.com');
     define('MAIL_SMTP_PASSWORD', 'your_gmail_app_password');
     ```
   - To generate a Gmail App Password: Google Account → Security → 2-Step Verification → App Passwords

6. **Run the project**
   - Start Apache and MySQL in XAMPP
   - Visit: http://localhost/mut_dms/

---

## 👤 Default Login
Create a Super Administrator account after importing the schema.

---

## 📁 Project Structure

```
mut_dms/
├── index.php                  # Homepage with carousel and login redirect
├── login.php                  # Login page
├── register.php               # Student registration
├── db_config.example.php      # Database config template (rename to db_config.php)
├── mailer.example.php         # Email config template (rename to mailer.php)
├── student_dashboard.php      # Student dashboard
├── cod_dashboard.php          # COD dashboard
├── dean_dashboard.php         # Dean dashboard
├── registrar_dashboard.php    # Registrar dashboard
├── finance_dashboard.php      # Finance dashboard
├── admin_dashboard.php        # Super Admin dashboard
├── process_approval.php       # Handles approval/rejection logic
├── upload.php                 # Document upload handler
├── mailer.php                 # Email sending functions (not in repo)
├── db_config.php              # Database credentials (not in repo)
├── PHPMailer/                 # PHPMailer library
├── assets/                    # Images and static assets
├── uploads/                   # Uploaded student documents
└── mut_dms (11).sql           # Database schema and seed data
```

---

## 🔒 Security Notes

- `db_config.php` and `mailer.php` are excluded from this repository via `.gitignore`
- All passwords are hashed using PHP's `password_hash()` with bcrypt
- Input sanitisation applied across all form submissions
- Role-based access control prevents unauthorised page access

---

## 👩‍💻 Author

**Rahab Mercy Muthoni Mukuria**  
Bachelor of Business and Information Technology (BBIT)  
Murang'a University of Technology  
Graduation: August 2025

---

## 📄 License

This project was developed for academic purposes at Murang'a University of Technology.
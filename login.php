<?php
session_start();

if (isset($_SESSION['reg_number'])) {
    if ($_SESSION['role'] === 'admin' || $_SESSION['role'] === 'super_admin') {
        header("Location: admin_dashboard.php");
    } else {
        header("Location: index.php");
    }
    exit();
}

$error = $_GET['error'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MUT Portal | Login</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Playfair+Display:wght@600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --mut-green: #22c55e;
            --mut-green-dark: #16a34a;
            --mut-green-light: #86efac;
            --text-dark: #0f172a;
            --text-medium: #475569;
            --text-light: #94a3b8;
            --bg-light: #f8fafc;
            --white: #ffffff;
            --border: #e2e8f0;
            --shadow-xl: 0 20px 25px -5px rgb(0 0 0 / 0.1);
            --gradient-primary: linear-gradient(135deg, #22c55e 0%, #16a34a 100%);
            --gradient-hero: linear-gradient(135deg, rgba(15, 23, 42, 0.95) 0%, rgba(30, 41, 59, 0.9) 100%);
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }
        body, html { height: 100%; font-family: 'Inter', sans-serif; overflow: hidden; }

        .login-container { display: flex; height: 100vh; width: 100%; }

        
        .brand-side {
            flex: 1.3; position: relative;
            background: url('assets/images/mut_building.jpg') no-repeat center center;
            background-size: cover; display: flex; align-items: center; justify-content: center; padding: 60px;
        }
        .brand-side::before {
            content: ''; position: absolute; top: 0; left: 0; right: 0; bottom: 0;
            background: var(--gradient-hero);
        }
        .brand-content { position: relative; z-index: 2; color: var(--white); max-width: 550px; }
        
        .brand-logo {
            width: 100px; height: 100px; background: var(--white); border-radius: 50%;
            display: flex; align-items: center; justify-content: center; margin-bottom: 40px;
            box-shadow: var(--shadow-xl); animation: float 3s ease-in-out infinite;
        }
        .brand-logo img { width: 70px; height: 70px; object-fit: contain; }

        @keyframes float { 0%, 100% { transform: translateY(0); } 50% { transform: translateY(-10px); } }

        .brand-content h1 {
            font-family: 'Playfair Display', serif; font-size: 3rem; margin-bottom: 24px;
            background: linear-gradient(135deg, #fff 0%, var(--mut-green-light) 100%);
            -webkit-background-clip: text; -webkit-text-fill-color: transparent;
        }

        .features-list { display: flex; flex-direction: column; gap: 16px; }
        .feature-item { display: flex; align-items: center; gap: 12px; font-size: 0.95rem; }
        .feature-item i {
            width: 32px; height: 32px; background: rgba(34, 197, 94, 0.2);
            border-radius: 8px; display: flex; align-items: center; justify-content: center; color: var(--mut-green);
        }

        .form-side {
            flex: 1; background: var(--white); display: flex; flex-direction: column;
            align-items: center; justify-content: center; padding: 48px; position: relative;
        }
        .form-wrapper { width: 100%; max-width: 420px; z-index: 1; }
        .form-header { text-align: center; margin-bottom: 35px; }
        .form-header h2 { font-size: 2rem; color: var(--text-dark); margin-bottom: 8px; }

        .error-msg {
            background: #fef2f2; border: 1px solid #fecaca; color: #dc2626;
            padding: 14px; border-radius: 12px; margin-bottom: 24px; font-size: 0.875rem;
            display: flex; align-items: center; gap: 10px; animation: shake 0.5s ease;
        }

        @keyframes shake { 0%, 100% { transform: translateX(0); } 25% { transform: translateX(-5px); } 75% { transform: translateX(5px); } }

        .input-group { margin-bottom: 20px; }
        .input-group label { display: block; font-weight: 600; font-size: 0.875rem; color: var(--text-dark); margin-bottom: 8px; }
        .input-field { position: relative; transition: transform 0.3s ease; }
        .input-field input {
            width: 100%; padding: 14px 16px; padding-right: 45px; border: 2px solid var(--border);
            border-radius: 12px; font-size: 0.95rem; transition: all 0.3s ease;
        }
        .input-field input:focus { border-color: var(--mut-green); outline: none; box-shadow: 0 0 0 4px rgba(34, 197, 94, 0.1); }
        .input-field i { position: absolute; right: 16px; top: 50%; transform: translateY(-50%); color: var(--text-light); cursor: pointer; }


        .forgot-password-wrapper {
            text-align: right;
            margin-top: -12px;
            margin-bottom: 24px;
        }
        .forgot-password-wrapper a {
            color: var(--mut-green);
            font-size: 0.875rem;
            font-weight: 600;
            text-decoration: none;
            transition: color 0.3s ease;
        }
        .forgot-password-wrapper a:hover { color: var(--mut-green-dark); text-decoration: underline; }

        .btn-signin {
            width: 100%; background: var(--gradient-primary); color: var(--white); padding: 16px;
            border: none; border-radius: 12px; font-size: 1rem; font-weight: 600; cursor: pointer;
            display: flex; align-items: center; justify-content: center; gap: 10px;
            box-shadow: 0 4px 14px rgba(34, 197, 94, 0.4); transition: all 0.3s ease;
        }
        .btn-signin:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(34, 197, 94, 0.5); }

        .divider { display: flex; align-items: center; margin: 28px 0; color: var(--text-light); font-size: 0.875rem; }
        .divider::before, .divider::after { content: ''; flex: 1; height: 1px; background: var(--border); }
        .divider span { padding: 0 16px; }

        .register-link { text-align: center; font-size: 0.95rem; color: var(--text-medium); }
        .register-link a { color: var(--mut-green); font-weight: 600; text-decoration: none; }

        .help-links { display: flex; justify-content: center; gap: 24px; margin-top: 32px; font-size: 0.875rem; }
        .help-links a { color: var(--text-light); text-decoration: none; }

        .decoration-circle { position: absolute; border-radius: 50%; background: linear-gradient(135deg, rgba(34, 197, 94, 0.1) 0%, rgba(34, 197, 94, 0.05) 100%); pointer-events: none; }
        .circle-1 { width: 300px; height: 300px; top: -150px; right: -150px; }
        .circle-2 { width: 200px; height: 200px; bottom: -100px; left: -100px; }

        @media (max-width: 1024px) { .brand-side { display: none; } }

        .btn-signin.loading { pointer-events: none; opacity: 0.8; }
        .btn-signin.loading::after {
            content: ''; width: 20px; height: 20px; border: 2px solid transparent;
            border-top-color: var(--white); border-radius: 50%; animation: spin 0.8s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="brand-side">
            <div class="brand-content">
                <div class="brand-logo">
                    <img src="assets/images/mut_logo.png" alt="MUT Logo">
                </div>
                <h1>Welcome to MUT Document Portal</h1>
                <p>Streamline your academic document submissions with our digital platform. Submit, track, and manage all your university documents in one place.</p>
                
                <div class="features-list">
                    <div class="feature-item"><i class="fa-solid fa-file-arrow-up"></i> <span>Easy document upload & submission</span></div>
                    <div class="feature-item"><i class="fa-solid fa-clock-rotate-left"></i> <span>Real-time status tracking</span></div>
                    <div class="feature-item"><i class="fa-solid fa-bell"></i> <span>Instant notifications on updates</span></div>
                    <div class="feature-item"><i class="fa-solid fa-shield-halved"></i> <span>Secure & confidential processing</span></div>
                </div>
            </div>
        </div>

        <div class="form-side">
            <div class="decoration-circle circle-1"></div>
            <div class="decoration-circle circle-2"></div>
            
            <div class="form-wrapper">
                <div class="form-header">
                    <h2>Sign In</h2>
                    <p>Enter your credentials to access your account</p>
                </div>

                <?php if ($error): ?>
                    <div class="error-msg">
                        <i class="fa-solid fa-circle-exclamation"></i>
                        <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>

                <form action="login_logic.php" method="POST" id="loginForm">
                    <div class="input-group">
                        <label for="username">Registration / Staff Number</label>
                        <div class="input-field">
                            <input type="text" id="username" name="username" placeholder="e.g., SC232/1261/2021" required autocomplete="username">
                        </div>
                    </div>

                    <div class="input-group">
                        <label for="password">Password</label>
                        <div class="input-field">
                            <input type="password" id="password" name="password" placeholder="Enter your password" required autocomplete="current-password">
                            <i class="fa-solid fa-eye-slash" id="togglePassword"></i>
                        </div>
                    </div>

                    <div class="forgot-password-wrapper">
                        <a href="forgot_password.php">Forgot Password?</a>
                    </div>

                    <button type="submit" class="btn-signin" id="submitBtn">
                        <span>Sign In to Portal</span>
                        <i class="fa-solid fa-arrow-right"></i>
                    </button>
                </form>

                <div class="divider"><span>or</span></div>

                <div class="register-link">
                    Don't have an account? <a href="register.php">Create account</a>
                </div>

                <div class="help-links">
                    <a href="#"><i class="fa-solid fa-circle-question"></i> Help Center</a>
                    <a href="#"><i class="fa-solid fa-lock"></i> Privacy Policy</a>
                </div>
            </div>
        </div>
    </div>

    <script>
    
        const togglePassword = document.querySelector('#togglePassword');
        const passwordInput = document.querySelector('#password');

        togglePassword.addEventListener('click', function () {
            const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
            passwordInput.setAttribute('type', type);
            this.classList.toggle('fa-eye');
            this.classList.toggle('fa-eye-slash');
        });

        const loginForm = document.querySelector('#loginForm');
        const submitBtn = document.querySelector('#submitBtn');

        loginForm.addEventListener('submit', function() {
            submitBtn.classList.add('loading');
            submitBtn.querySelector('span').textContent = 'Signing in...';
        });

        const inputs = document.querySelectorAll('input');
        inputs.forEach(input => {
            input.addEventListener('focus', function() {
                this.parentElement.style.transform = 'scale(1.02)';
            });
            input.addEventListener('blur', function() {
                this.parentElement.style.transform = 'scale(1)';
            });
        });
    </script>
</body>
</html>
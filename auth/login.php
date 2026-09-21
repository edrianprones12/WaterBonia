<?php
/**
 * WaterBonia - Login
 */
require_once __DIR__ . '/../includes/functions.php';

if (logged_in()) {
    redirect('/');
}

$error = '';

if (is_post()) {
    if (!verify_csrf()) {
        $error = 'Invalid request. Please try again.';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($username === '' || $password === '') {
            $error = 'Please enter both username and password.';
        } else {
            $user = db_fetch("SELECT * FROM users WHERE username = ?", [$username]);
            if ($user && password_verify($password, $user['password_hash'])) {
                if ($user['status'] !== 'active') {
                    $error = 'Your account is currently inactive. Contact an administrator.';
                } else {
                    session_regenerate_id(true);
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['user_role'] = $user['role'];
                    $_SESSION['login_time'] = time();

                    db_update('users', ['last_login_at' => date('Y-m-d H:i:s')], 'id = ?', [$user['id']]);
                    log_event(null, $user['id'], 'user_login', 'info', 'User logged in: ' . $user['username']);
                    redirect('/');
                }
            } else {
                $error = 'Invalid username or password.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WaterBonia - Login</title>
    <link rel="stylesheet" href="<?= base_url('/assets/css/style.css') ?>">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            background: linear-gradient(135deg, #0c1929 0%, #0f2847 40%, #0a3d6b 70%, #065a8a 100%);
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            overflow: hidden;
            position: relative;
        }

        .bg-bubbles {
            position: fixed;
            inset: 0;
            overflow: hidden;
            pointer-events: none;
            z-index: 0;
        }
        .bg-bubbles span {
            position: absolute;
            display: block;
            width: 20px;
            height: 20px;
            background: rgba(56, 189, 248, 0.06);
            border-radius: 50%;
            animation: bubble-rise linear infinite;
        }
        .bg-bubbles span:nth-child(1)  { left: 10%; width: 80px;  height: 80px;  animation-duration: 22s; animation-delay: 0s; }
        .bg-bubbles span:nth-child(2)  { left: 25%; width: 40px;  height: 40px;  animation-duration: 18s; animation-delay: 2s; }
        .bg-bubbles span:nth-child(3)  { left: 40%; width: 60px;  height: 60px;  animation-duration: 25s; animation-delay: 4s; }
        .bg-bubbles span:nth-child(4)  { left: 55%; width: 30px;  height: 30px;  animation-duration: 20s; animation-delay: 1s; }
        .bg-bubbles span:nth-child(5)  { left: 70%; width: 90px;  height: 90px;  animation-duration: 28s; animation-delay: 3s; }
        .bg-bubbles span:nth-child(6)  { left: 85%; width: 50px;  height: 50px;  animation-duration: 16s; animation-delay: 5s; }
        .bg-bubbles span:nth-child(7)  { left: 15%; width: 35px;  height: 35px;  animation-duration: 24s; animation-delay: 6s; }
        .bg-bubbles span:nth-child(8)  { left: 60%; width: 70px;  height: 70px;  animation-duration: 19s; animation-delay: 0.5s; }

        @keyframes bubble-rise {
            0%   { transform: translateY(100vh) scale(0); opacity: 0; }
            10%  { opacity: 1; }
            90%  { opacity: 0.6; }
            100% { transform: translateY(-10vh) scale(1.2); opacity: 0; }
        }

        .login-wrapper {
            position: relative;
            z-index: 1;
            width: 100%;
            max-width: 420px;
            padding: 1.5rem;
        }

        .login-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border-radius: 16px;
            padding: 2.5rem 2rem 2rem;
            box-shadow: 0 25px 60px rgba(0, 0, 0, 0.3), 0 0 0 1px rgba(255, 255, 255, 0.1);
            animation: card-enter 0.5s ease-out;
        }

        @keyframes card-enter {
            from { opacity: 0; transform: translateY(20px) scale(0.97); }
            to   { opacity: 1; transform: translateY(0) scale(1); }
        }

        .login-logo {
            text-align: center;
            margin-bottom: 2rem;
        }

        .logo-mark {
            width: 56px;
            height: 56px;
            margin: 0 auto 1rem;
            background: linear-gradient(135deg, #0284c7 0%, #0ea5e9 50%, #38bdf8 100%);
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.8rem;
            box-shadow: 0 8px 20px rgba(2, 132, 199, 0.35);
        }

        .login-logo h1 {
            font-size: 1.45rem;
            font-weight: 800;
            color: #0f172a;
            letter-spacing: 0.12em;
            margin-bottom: 0.25rem;
        }

        .login-logo p {
            font-size: 0.82rem;
            color: #64748b;
            font-weight: 400;
        }

        .login-welcome {
            text-align: center;
            margin-bottom: 1.5rem;
        }

        .login-welcome h2 {
            font-size: 1.1rem;
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 0.2rem;
        }

        .login-welcome p {
            font-size: 0.82rem;
            color: #94a3b8;
        }

        .alert-error {
            display: flex;
            align-items: flex-start;
            gap: 0.6rem;
            background: #fef2f2;
            color: #991b1b;
            border: 1px solid #fecaca;
            padding: 0.75rem 1rem;
            border-radius: 10px;
            margin-bottom: 1.25rem;
            font-size: 0.82rem;
            line-height: 1.4;
        }

        .alert-error svg {
            flex-shrink: 0;
            margin-top: 1px;
        }

        .form-group {
            margin-bottom: 1.15rem;
        }

        .form-group label {
            display: block;
            font-weight: 600;
            margin-bottom: 0.35rem;
            color: #334155;
            font-size: 0.82rem;
        }

        .input-wrap {
            position: relative;
        }

        .form-group input[type="text"],
        .form-group input[type="password"] {
            width: 100%;
            padding: 0.7rem 0.9rem;
            border: 1.5px solid #e2e8f0;
            border-radius: 10px;
            font-size: 0.9rem;
            background: #f8fafc;
            color: #0f172a;
            box-sizing: border-box;
            transition: border-color 0.2s, box-shadow 0.2s, background 0.2s;
        }

        .form-group input:focus {
            outline: none;
            border-color: #0284c7;
            background: #fff;
            box-shadow: 0 0 0 3px rgba(2, 132, 199, 0.1);
        }

        .form-group input::placeholder {
            color: #94a3b8;
        }

        .pw-toggle {
            position: absolute;
            right: 0.65rem;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            cursor: pointer;
            padding: 0.3rem;
            color: #94a3b8;
            display: flex;
            align-items: center;
            transition: color 0.2s;
        }

        .pw-toggle:hover {
            color: #475569;
        }

        .btn-login {
            width: 100%;
            padding: 0.75rem;
            background: linear-gradient(135deg, #0284c7, #0ea5e9);
            color: #fff;
            border: none;
            border-radius: 10px;
            font-size: 0.95rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            margin-top: 0.25rem;
            letter-spacing: 0.02em;
        }

        .btn-login:hover {
            background: linear-gradient(135deg, #0369a1, #0284c7);
            box-shadow: 0 4px 12px rgba(2, 132, 199, 0.4);
            transform: translateY(-1px);
        }

        .btn-login:active {
            transform: translateY(0);
            box-shadow: 0 2px 6px rgba(2, 132, 199, 0.3);
        }

        .btn-login:focus-visible {
            outline: 2px solid #0284c7;
            outline-offset: 2px;
        }

        .login-footer {
            text-align: center;
            margin-top: 1.5rem;
            font-size: 0.72rem;
            color: rgba(148, 163, 184, 0.8);
        }

        @media (max-width: 480px) {
            .login-card {
                padding: 2rem 1.5rem 1.5rem;
                border-radius: 12px;
            }
            .login-wrapper {
                padding: 1rem;
            }
        }
    </style>
</head>
<body>
    <div class="bg-bubbles">
        <span></span><span></span><span></span><span></span>
        <span></span><span></span><span></span><span></span>
    </div>

    <div class="login-wrapper">
        <div class="login-card">
            <div class="login-logo">
                <div class="logo-mark">&#x1F4A7;</div>
                <h1>WATERBONIA</h1>
                <p>Smart Water Dispenser Monitoring</p>
            </div>

            <div class="login-welcome">
                <h2>Welcome back</h2>
                <p>Sign in to continue to WaterBonia</p>
            </div>

            <?php if ($error): ?>
                <div class="alert-error">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                    <span><?= sanitize($error) ?></span>
                </div>
            <?php endif; ?>

            <form method="POST" action="<?= base_url('/auth/login.php') ?>">
                <?= csrf_field() ?>
                <div class="form-group">
                    <label for="username">Username</label>
                    <div class="input-wrap">
                        <input type="text" id="username" name="username" required autofocus
                               placeholder="Enter your username"
                               value="<?= sanitize($_POST['username'] ?? '') ?>">
                    </div>
                </div>
                <div class="form-group">
                    <label for="password">Password</label>
                    <div class="input-wrap">
                        <input type="password" id="password" name="password" required
                               placeholder="Enter your password">
                        <button type="button" class="pw-toggle" id="pwToggle" aria-label="Toggle password visibility">
                            <svg class="pw-eye-open" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                            <svg class="pw-eye-closed" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="display:none"><path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19m-6.72-1.07a3 3 0 11-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                        </button>
                    </div>
                </div>
                <button type="submit" class="btn-login">Log In</button>
            </form>

            <div class="login-footer">
                WaterBonia IoT Water Dispenser System &copy; <?= date('Y') ?>
            </div>
        </div>
    </div>

    <script>
    (function() {
        var toggle = document.getElementById('pwToggle');
        var pw = document.getElementById('password');
        var eyeOpen = toggle.querySelector('.pw-eye-open');
        var eyeClosed = toggle.querySelector('.pw-eye-closed');
        toggle.addEventListener('click', function() {
            if (pw.type === 'password') {
                pw.type = 'text';
                eyeOpen.style.display = 'none';
                eyeClosed.style.display = 'block';
            } else {
                pw.type = 'password';
                eyeOpen.style.display = 'block';
                eyeClosed.style.display = 'none';
            }
        });
    })();
    </script>
</body>
</html>

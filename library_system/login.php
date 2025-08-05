<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>เข้าสู่ระบบ - ห้องสมุดวิทยาลัยเทคนิคหาดใหญ่</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .login-container {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
            padding: 40px;
            width: 100%;
            max-width: 450px;
            position: relative;
            overflow: hidden;
        }

        .login-container::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #667eea, #764ba2);
        }

        .logo {
            text-align: center;
            margin-bottom: 30px;
        }

        .logo i {
            font-size: 4rem;
            color: #667eea;
            margin-bottom: 10px;
        }

        .logo h1 {
            color: #333;
            font-size: 1.8rem;
            font-weight: 600;
            margin-bottom: 5px;
        }

        .logo p {
            color: #666;
            font-size: 1rem;
        }

        .tab-container {
            display: flex;
            background: #f8f9fa;
            border-radius: 12px;
            margin-bottom: 30px;
            padding: 4px;
        }

        .tab-button {
            flex: 1;
            padding: 12px;
            text-align: center;
            border: none;
            background: transparent;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 500;
            transition: all 0.3s ease;
            color: #666;
        }

        .tab-button.active {
            background: #667eea;
            color: white;
            box-shadow: 0 2px 10px rgba(102, 126, 234, 0.3);
        }

        .form-group {
            margin-bottom: 20px;
            position: relative;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #333;
            font-weight: 500;
            font-size: 0.9rem;
        }

        .input-container {
            position: relative;
        }

        .input-container i {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #666;
            font-size: 1.1rem;
        }

        .form-control {
            width: 100%;
            padding: 15px 15px 15px 45px;
            border: 2px solid #e1e5e9;
            border-radius: 12px;
            font-size: 1rem;
            transition: all 0.3s ease;
            background: #fff;
        }

        .form-control:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .password-toggle {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: #666;
            font-size: 1.1rem;
        }
        .password-toggle i {
            pointer-events: none;
        }

        .btn-login {
            width: 100%;
            padding: 15px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 1.1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(102, 126, 234, 0.3);
        }

        .btn-login:active {
            transform: translateY(0);
        }

        .forgot-password {
            text-align: center;
            margin-top: 20px;
        }

        .forgot-password a {
            color: #667eea;
            text-decoration: none;
            font-size: 0.9rem;
            transition: color 0.3s ease;
        }

        .forgot-password a:hover {
            color: #764ba2;
        }

        .register-link {
            text-align: center;
            margin-top: 25px;
            padding-top: 25px;
            border-top: 1px solid #e1e5e9;
        }

        .register-link p {
            color: #666;
            margin-bottom: 10px;
        }

        .btn-register {
            color: #667eea;
            text-decoration: none;
            font-weight: 600;
            padding: 8px 20px;
            border: 2px solid #667eea;
            border-radius: 8px;
            transition: all 0.3s ease;
            display: inline-block;
        }

        .btn-register:hover {
            background: #667eea;
            color: white;
        }

        .alert {
            padding: 12px 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 0.9rem;
        }

        .alert-error {
            background: #fee;
            color: #c33;
            border: 1px solid #fcc;
        }

        .alert-success {
            background: #efe;
            color: #363;
            border: 1px solid #cfc;
        }

        @media (max-width: 480px) {
            .login-container {
                padding: 25px;
                margin: 10px;
            }
            
            .logo h1 {
                font-size: 1.5rem;
            }
            
            .logo i {
                font-size: 3rem;
            }
        }

        .loading {
            display: none;
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
        }

        .spinner {
            width: 20px;
            height: 20px;
            border: 2px solid rgba(255,255,255,0.3);
            border-radius: 50%;
            border-top: 2px solid white;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="logo">
            <i class="fas fa-book-open"></i>
            <h1>ห้องสมุดดิจิทัล</h1>
            <p>วิทยาลัยเทคนิคหาดใหญ่</p>
        </div>

        <div class="tab-container">
            <button class="tab-button active" onclick="switchTab('user')">
                <i class="fas fa-user"></i> สมาชิก
            </button>
            <button class="tab-button" onclick="switchTab('admin')">
                <i class="fas fa-user-shield"></i> ผู้ดูแล
            </button>
        </div>

        <div id="alert-container"></div>

        <form id="loginForm" method="POST" action="login_process.php">
            <input type="hidden" name="user_type" id="user_type" value="user">
            
            <div class="form-group">
                <label for="username" id="username-label">รหัสนักเรียน</label>
                <div class="input-container">
                    <i class="fas fa-user"></i>
                    <input type="text" id="username" name="username" class="form-control" 
                           placeholder="กรอกรหัสนักเรียน" required>
                </div>
            </div>
            
        <div class="form-group">
            <label for="password">รหัสผ่าน</label>
            <div class="input-container">
                <i class="fas fa-lock"></i>
                <input type="password" id="password" name="password" class="form-control" 
                    placeholder="กรอกรหัสผ่าน" required>
            </div>
        </div>

            
            <button type="submit" class="btn-login">
                <span id="btn-text">เข้าสู่ระบบ</span>
                <div class="loading" id="loading">
                    <div class="spinner"></div>
                </div>
            </button>
        </form>

        <div class="forgot-password">
            <a href="forgot_password.php">ลืมรหัสผ่าน?</a>
        </div>

        <div class="register-link" id="register-section">
            <p>ยังไม่เป็นสมาชิก?</p>
            <a href="register.php" class="btn-register">สมัครสมาชิก</a>
        </div>
    </div>

    <script>
        function switchTab(type) {
            const tabs = document.querySelectorAll('.tab-button');
            const userType = document.getElementById('user_type');
            const usernameLabel = document.getElementById('username-label');
            const usernameInput = document.getElementById('username');
            const registerSection = document.getElementById('register-section');
            
            tabs.forEach(tab => tab.classList.remove('active'));
            event.target.closest('.tab-button').classList.add('active');
            
            userType.value = type;
            
            if (type === 'admin') {
                usernameLabel.textContent = 'ชื่อผู้ใช้';
                usernameInput.placeholder = 'กรอกชื่อผู้ใช้';
                registerSection.style.display = 'none';
            } else {
                usernameLabel.textContent = 'รหัสนักเรียน';
                usernameInput.placeholder = 'กรอกรหัสนักเรียน';
                registerSection.style.display = 'block';
            }
        }

        function togglePassword() {
            const password = document.getElementById('password');
            const toggleIcon = document.getElementById('toggleIcon');
            
            if (password.type === 'password') {
                password.type = 'text';
                toggleIcon.classList.remove('fa-eye');
                toggleIcon.classList.add('fa-eye-slash');
            } else {
                password.type = 'password';
                toggleIcon.classList.remove('fa-eye-slash');
                toggleIcon.classList.add('fa-eye');
            }
        }


        function showAlert(message, type = 'error') {
            const alertContainer = document.getElementById('alert-container');
            alertContainer.innerHTML = `
                <div class="alert alert-${type}">
                    <i class="fas fa-${type === 'error' ? 'exclamation-circle' : 'check-circle'}"></i>
                    ${message}
                </div>
            `;
            
            setTimeout(() => {
                alertContainer.innerHTML = '';
            }, 5000);
        }

        document.getElementById('loginForm').addEventListener('submit', function(e) {
            const btnText = document.getElementById('btn-text');
            const loading = document.getElementById('loading');
            const btn = document.querySelector('.btn-login');
            
            btnText.style.display = 'none';
            loading.style.display = 'block';
            btn.disabled = true;
            
            // Reset after 3 seconds if no response
            setTimeout(() => {
                btnText.style.display = 'block';
                loading.style.display = 'none';
                btn.disabled = false;
            }, 3000);
        });

    // Check for URL parameters to show messages
        const urlParams = new URLSearchParams(window.location.search);
        const error = urlParams.get('error');
        const success = urlParams.get('success');
        
        if (error) {
            let message = 'เกิดข้อผิดพลาด';
            switch(error) {
                case 'invalid':
                    message = 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง';
                    break;
                case 'inactive':
                    message = 'บัญชีของคุณถูกระงับการใช้งาน';
                    break;
                case 'required':
                    message = 'กรุณากรอกข้อมูลให้ครบถ้วน';
                    break;
            }
            showAlert(message, 'error');
        }
        
        if (success) {
            let message = '';
            switch(success) {
                case 'logout':
                    message = 'ออกจากระบบเรียบร้อยแล้ว';
                    break;
                case 'registered':
                    message = 'สมัครสมาชิกสำเร็จ! กรุณาเข้าสู่ระบบ';
                    break;
            }
            if (message) {
                showAlert(message, 'success');
            }
        }
    </script>
</body>
</html>
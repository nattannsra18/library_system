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
        }

        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(102, 126, 234, 0.3);
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
            cursor: pointer;
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

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(5px);
        }

        .modal.show {
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .modal-content {
            background-color: #fff;
            margin: 20px;
            padding: 0;
            border-radius: 20px;
            width: 100%;
            max-width: 500px;
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.2);
            position: relative;
            overflow: hidden;
        }

        .modal-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 25px;
            position: relative;
        }

        .modal-header h2 {
            margin: 0;
            font-size: 1.5rem;
            font-weight: 600;
        }

        .modal-header p {
            margin: 10px 0 0 0;
            opacity: 0.9;
            font-size: 0.9rem;
        }

        .close {
            position: absolute;
            right: 20px;
            top: 50%;
            transform: translateY(-50%);
            color: white;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: all 0.3s ease;
        }

        .close:hover {
            background: rgba(255, 255, 255, 0.2);
        }

        .modal-body {
            padding: 30px;
        }

        .contact-info {
            padding: 10px 0;
        }

        .contact-intro {
            display: flex;
            align-items: flex-start;
            margin-bottom: 25px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 12px;
            border-left: 4px solid #667eea;
        }

        .contact-intro i {
            color: #667eea;
            font-size: 1.2rem;
            margin-right: 12px;
            margin-top: 2px;
        }

        .contact-intro p {
            margin: 0;
            color: #333;
            line-height: 1.5;
        }

        .contact-methods {
            margin-bottom: 25px;
        }

        .contact-item {
            display: flex;
            align-items: center;
            padding: 15px;
            margin-bottom: 10px;
            background: #fff;
            border: 2px solid #e1e5e9;
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .contact-item:hover:not(.counter-visit) {
            border-color: #667eea;
            background: #f8f9ff;
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.1);
        }

        .contact-item.counter-visit {
            cursor: default;
            background: #f8f9fa;
            border-color: #dee2e6;
        }

        .contact-item i {
            width: 24px;
            text-align: center;
            color: #667eea;
            font-size: 1.1rem;
            margin-right: 15px;
        }

        .contact-item span {
            color: #333;
            font-weight: 500;
            flex: 1;
        }

        .counter-visit i {
            color: #28a745;
        }

        .contact-note {
            background: linear-gradient(135deg, rgba(102, 126, 234, 0.05) 0%, rgba(118, 75, 162, 0.05) 100%);
            border-radius: 12px;
            padding: 20px;
            border: 1px solid rgba(102, 126, 234, 0.1);
        }

        .note-content {
            display: flex;
            align-items: flex-start;
        }

        .note-content i {
            color: #ffc107;
            font-size: 1.2rem;
            margin-right: 15px;
            margin-top: 2px;
        }

        .note-content h4 {
            margin: 0 0 8px 0;
            color: #333;
            font-size: 1rem;
            font-weight: 600;
        }

        .note-content ul {
            margin: 0;
            padding-left: 16px;
            color: #666;
        }

        .note-content li {
            margin-bottom: 4px;
            line-height: 1.4;
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

            .modal-content {
                margin: 10px;
            }

            .modal-header, .modal-body {
                padding: 20px;
            }

            .contact-intro {
                flex-direction: column;
            }
            
            .contact-intro i {
                margin-bottom: 8px;
            }
            
            .contact-item {
                padding: 12px;
            }
            
            .note-content {
                flex-direction: column;
            }
            
            .note-content i {
                margin-bottom: 10px;
            }
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
            
            <button type="submit" class="btn-login">เข้าสู่ระบบ</button>
        </form>

        <div class="forgot-password">
            <a onclick="openForgotPasswordModal()">ลืมรหัสผ่าน?</a>
        </div>

        <div class="register-link" id="register-section">
            <p>ยังไม่เป็นสมาชิก?</p>
            <a href="register.php" class="btn-register">สมัครสมาชิก</a>
        </div>
    </div>

    <!-- Forgot Password Modal -->
    <div id="forgotPasswordModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>
                    <i class="fas fa-question-circle" style="margin-right: 10px;"></i>
                    ลืมรหัสผ่าน?
                </h2>
                <p>ติดต่อผู้ดูแลระบบเพื่อขอรีเซ็ตรหัสผ่าน</p>
                <span class="close" onclick="closeForgotPasswordModal()">&times;</span>
            </div>
            <div class="modal-body">
                <div id="contact-content">
                    <div class="contact-info">
                        <div class="contact-intro">
                            <i class="fas fa-info-circle"></i>
                            <p>หากคุณลืมรหัสผ่าน กรุณาติดต่อผู้ดูแลระบบผ่านช่องทางดังต่อไปนี้:</p>
                        </div>
                        
                        <div class="contact-methods">
                            <div class="contact-item" id="contact-phone">
                                <i class="fas fa-phone"></i>
                                <span>กำลังโหลด...</span>
                            </div>
                            
                            <div class="contact-item" id="contact-email">
                                <i class="fas fa-envelope"></i>
                                <span>กำลังโหลด...</span>
                            </div>
                            
                            <div class="contact-item counter-visit">
                                <i class="fas fa-map-marker-alt"></i>
                                <span>เคาน์เตอร์ห้องสมุด วิทยาลัยเทคนิคหาดใหญ่</span>
                            </div>
                        </div>
                        
                        <div class="contact-note">
                            <div class="note-content">
                                <i class="fas fa-lightbulb"></i>
                                <div>
                                    <h4>ข้อมูลที่ต้องเตรียม:</h4>
                                    <ul>
                                        <li>รหัสนักเรียน หรือ บัตรประจำตัวนักเรียน</li>
                                        <li>ข้อมูลส่วนตัวสำหรับการยืนยันตัวตน</li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
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

        function openForgotPasswordModal() {
            const modal = document.getElementById('forgotPasswordModal');
            modal.classList.add('show');
            loadContactInfo();
        }
        
        function loadContactInfo() {
            // เรียก API เพื่อดึงข้อมูลการติดต่อ
            fetch('api/get_contact_info.php')
                .then(response => response.json())
                .then(result => {
                    if (result.success) {
                        displayContactInfo(result.data);
                    } else {
                        // ใช้ข้อมูล default หากไม่สามารถดึงจาก API ได้
                        displayContactInfo({
                            phone: '074-123456',
                            email: 'library@techhathai.ac.th'
                        });
                    }
                })
                .catch(error => {
                    console.error('Error loading contact info:', error);
                    // ใช้ข้อมูล default หากเกิดข้อผิดพลาด
                    displayContactInfo({
                        phone: '074-123456',
                        email: 'library@techhathai.ac.th'
                    });
                });
        }
        
        function displayContactInfo(data) {
            const phoneElement = document.getElementById('contact-phone');
            const emailElement = document.getElementById('contact-email');
            
            if (data.phone) {
                phoneElement.innerHTML = `<i class="fas fa-phone"></i><span>${data.phone}</span>`;
                phoneElement.onclick = () => window.open(`tel:${data.phone}`);
            }
            
            if (data.email) {
                emailElement.innerHTML = `<i class="fas fa-envelope"></i><span>${data.email}</span>`;
                emailElement.onclick = () => window.open(`mailto:${data.email}`);
            }
        }

        function closeForgotPasswordModal() {
            const modal = document.getElementById('forgotPasswordModal');
            modal.classList.remove('show');
        }

        // ปิด Modal เมื่อคลิกที่พื้นหลัง
        document.addEventListener('DOMContentLoaded', function() {
            const modal = document.getElementById('forgotPasswordModal');
            
            modal.addEventListener('click', function(e) {
                if (e.target === modal) {
                    closeForgotPasswordModal();
                }
            });
            
            // ปิด Modal เมื่อกด ESC
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape' && modal.classList.contains('show')) {
                    closeForgotPasswordModal();
                }
            });
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
                    message = 'กรุณากรอกข้อมูลให้ครับถ้วน';
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
                case 'password_reset':
                    message = 'รีเซ็ตรหัสผ่านสำเร็จ! กรุณาเข้าสู่ระบบด้วยรหัสผ่านใหม่';
                    break;
            }
            if (message) {
                showAlert(message, 'success');
            }
        }
    </script>
</body>
</html>
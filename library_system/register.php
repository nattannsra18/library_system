<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>สมัครสมาชิก - ห้องสมุดวิทยาลัยเทคนิคหาดใหญ่</title>
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

        .register-container {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
            padding: 40px;
            width: 100%;
            max-width: 600px;
            position: relative;
            overflow: hidden;
        }

        .register-container::before {
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
            font-size: 3.5rem;
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

        .form-row {
            display: flex;
            gap: 20px;
            margin-bottom: 20px;
        }

        .form-group {
            flex: 1;
            margin-bottom: 20px;
            position: relative;
        }

        .form-group.full-width {
            flex: 100%;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #333;
            font-weight: 500;
            font-size: 0.9rem;
        }

        .required {
            color: #e74c3c;
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
            z-index: 1;
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

        .form-control.error {
            border-color: #e74c3c;
        }

        .form-control.success {
            border-color: #27ae60;
        }

        select.form-control {
            padding-left: 45px;
            cursor: pointer;
        }

        textarea.form-control {
            min-height: 100px;
            resize: vertical;
            padding-top: 15px;
        }

        .password-toggle {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: #666;
            font-size: 1.1rem;
            z-index: 2;
        }

        .error-message {
            color: #e74c3c;
            font-size: 0.8rem;
            margin-top: 5px;
            display: none;
        }

        .success-message {
            color: #27ae60;
            font-size: 0.8rem;
            margin-top: 5px;
            display: none;
        }

        .btn-register {
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
            margin-top: 10px;
        }

        .btn-register:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(102, 126, 234, 0.3);
        }

        .btn-register:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }

        .login-link {
            text-align: center;
            margin-top: 25px;
            padding-top: 25px;
            border-top: 1px solid #e1e5e9;
        }

        .login-link p {
            color: #666;
            margin-bottom: 10px;
        }

        .btn-login {
            color: #667eea;
            text-decoration: none;
            font-weight: 600;
            padding: 8px 20px;
            border: 2px solid #667eea;
            border-radius: 8px;
            transition: all 0.3s ease;
            display: inline-block;
        }

        .btn-login:hover {
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

        .password-strength {
            margin-top: 8px;
        }

        .strength-bar {
            height: 4px;
            background: #e1e5e9;
            border-radius: 2px;
            overflow: hidden;
        }

        .strength-fill {
            height: 100%;
            width: 0%;
            transition: all 0.3s ease;
            border-radius: 2px;
        }

        .strength-weak { background: #e74c3c; }
        .strength-fair { background: #f39c12; }
        .strength-good { background: #f1c40f; }
        .strength-strong { background: #27ae60; }

        .strength-text {
            font-size: 0.8rem;
            margin-top: 4px;
            color: #666;
        }

        @media (max-width: 768px) {
            .form-row {
                flex-direction: column;
                gap: 0;
            }
            
            .register-container {
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
    </style>
</head>
<body>
    <div class="register-container">
        <div class="logo">
            <i class="fas fa-user-plus"></i>
            <h1>สมัครสมาชิก</h1>
            <p>ห้องสมุดวิทยาลัยเทคนิคหาดใหญ่</p>
        </div>

        <div id="alert-container"></div>

        <form id="registerForm" method="POST" action="register_process.php" enctype="multipart/form-data">
            <div class="form-row">
                <div class="form-group">
                    <label for="student_id">รหัสนักเรียน <span class="required">*</span></label>
                    <div class="input-container">
                        <i class="fas fa-id-card"></i>
                        <input type="text" id="student_id" name="student_id" class="form-control" 
                               placeholder="กรอกรหัสนักเรียน" required maxlength="20">
                    </div>
                    <div class="error-message" id="student_id_error"></div>
                </div>
                
                <div class="form-group">
                    <label for="email">อีเมล <span class="required">*</span></label>
                    <div class="input-container">
                        <i class="fas fa-envelope"></i>
                        <input type="email" id="email" name="email" class="form-control" 
                               placeholder="กรอกอีเมล" required maxlength="100">
                    </div>
                    <div class="error-message" id="email_error"></div>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="first_name">ชื่อ <span class="required">*</span></label>
                    <div class="input-container">
                        <i class="fas fa-user"></i>
                        <input type="text" id="first_name" name="first_name" class="form-control" 
                               placeholder="กรอกชื่อ" required maxlength="100">
                    </div>
                    <div class="error-message" id="first_name_error"></div>
                </div>
                
                <div class="form-group">
                    <label for="last_name">นามสกุล <span class="required">*</span></label>
                    <div class="input-container">
                        <i class="fas fa-user"></i>
                        <input type="text" id="last_name" name="last_name" class="form-control" 
                               placeholder="กรอกนามสกุล" required maxlength="100">
                    </div>
                    <div class="error-message" id="last_name_error"></div>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="phone">เบอร์โทรศัพท์</label>
                    <div class="input-container">
                        <i class="fas fa-phone"></i>
                        <input type="tel" id="phone" name="phone" class="form-control" 
                               placeholder="กรอกเบอร์โทรศัพท์" maxlength="15">
                    </div>
                    <div class="error-message" id="phone_error"></div>
                </div>
                
                <div class="form-group">
                    <label for="user_type">ประเภทผู้ใช้ <span class="required">*</span></label>
                    <div class="input-container">
                        <i class="fas fa-users"></i>
                        <select id="user_type" name="user_type" class="form-control" required>
                            <option value="student" selected>นักเรียน</option>
                        </select>
                    </div>
                    <div class="error-message" id="user_type_error"></div>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="department">แผนก/ฝ่าย</label>
                    <div class="input-container">
                        <i class="fas fa-building"></i>
                        <input type="text" id="department" name="department" class="form-control" 
                               placeholder="กรอกแผนก/ฝ่าย" maxlength="100">
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="class_level">ระดับชั้น/ตำแหน่ง</label>
                    <div class="input-container">
                        <i class="fas fa-graduation-cap"></i>
                        <input type="text" id="class_level" name="class_level" class="form-control" 
                               placeholder="เช่น ปวช.1, ปวส.2" maxlength="50">
                    </div>
                </div>
            </div>

            <div class="form-group full-width">
                <label for="address">ที่อยู่</label>
                <div class="input-container">
                    <i class="fas fa-map-marker-alt"></i>
                    <textarea id="address" name="address" class="form-control" 
                              placeholder="กรอกที่อยู่" rows="3"></textarea>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="password">รหัสผ่าน <span class="required">*</span></label>
                    <div class="input-container">
                        <i class="fas fa-lock"></i>
                        <input type="password" id="password" name="password" class="form-control" 
                               placeholder="กรอกรหัสผ่าน" required minlength="6">
                        <span class="password-toggle" onclick="togglePassword('password')">
                        </span>
                    </div>
                    <div class="password-strength">
                        <div class="strength-bar">
                            <div class="strength-fill" id="strength-fill"></div>
                        </div>
                        <div class="strength-text" id="strength-text">ความแข็งแกร่งของรหัสผ่าน</div>
                    </div>
                    <div class="error-message" id="password_error"></div>
                </div>
                
                <div class="form-group">
                    <label for="confirm_password">ยืนยันรหัสผ่าน <span class="required">*</span></label>
                    <div class="input-container">
                        <i class="fas fa-lock"></i>
                        <input type="password" id="confirm_password" name="confirm_password" class="form-control" 
                               placeholder="ยืนยันรหัสผ่าน" required>
                        <span class="password-toggle" onclick="togglePassword('confirm_password')">
                        </span>
                    </div>
                    <div class="error-message" id="confirm_password_error"></div>
                </div>
            </div>

            <div class="form-group full-width">
                <label for="profile_image">รูปโปรไฟล์</label>
                <div class="input-container">
                    <i class="fas fa-image"></i>
                    <input type="file" id="profile_image" name="profile_image" class="form-control" 
                           accept=".jpg,.jpeg,.png,.gif" onchange="previewImage(this)">
                </div>
                <div class="error-message" id="profile_image_error"></div>
            </div>

            <button type="submit" class="btn-register" id="submitBtn">
                <span id="btn-text">สมัครสมาชิก</span>
                <div class="loading" id="loading">
                    <div class="spinner"></div>
                </div>
            </button>
        </form>

        <div class="login-link">
            <p>มีบัญชีอยู่แล้ว?</p>
            <a href="login.php" class="btn-login">เข้าสู่ระบบ</a>
        </div>
    </div>

    <script>
        // ฟังก์ชันแสดง/ซ่อนรหัสผ่าน
        function togglePassword(fieldId) {
            const field = document.getElementById(fieldId);
            const icon = document.getElementById(fieldId + '-icon');
            
            if (field.type === 'password') {
                field.type = 'text';
            } else {
                field.type = 'password';
            }
        }

        // ฟังก์ชันตรวจสอบความแข็งแกร่งของรหัสผ่าน
        function checkPasswordStrength(password) {
            let strength = 0;
            let feedback = '';
            
            if (password.length >= 6) strength++;
            if (password.length >= 8) strength++;
            if (/[a-z]/.test(password)) strength++;
            if (/[A-Z]/.test(password)) strength++;
            if (/[0-9]/.test(password)) strength++;
            if (/[^A-Za-z0-9]/.test(password)) strength++;
            
            const strengthFill = document.getElementById('strength-fill');
            const strengthText = document.getElementById('strength-text');
            
            if (strength <= 2) {
                strengthFill.className = 'strength-fill strength-weak';
                strengthFill.style.width = '25%';
                feedback = 'อ่อนแอ';
            } else if (strength <= 3) {
                strengthFill.className = 'strength-fill strength-fair';
                strengthFill.style.width = '50%';
                feedback = 'พอใช้';
            } else if (strength <= 4) {
                strengthFill.className = 'strength-fill strength-good';
                strengthFill.style.width = '75%';
                feedback = 'ดี';
            } else {
                strengthFill.className = 'strength-fill strength-strong';
                strengthFill.style.width = '100%';
                feedback = 'แข็งแกร่ง';
            }
            
            strengthText.textContent = `ความแข็งแกร่งของรหัสผ่าน: ${feedback}`;
        }

        // ฟังก์ชันแสดงข้อความแจ้งเตือน
        function showAlert(message, type = 'error') {
            const alertContainer = document.getElementById('alert-container');
            alertContainer.innerHTML = 
                `<div class="alert alert-${type}">
                    <i class="fas fa-${type === 'error' ? 'exclamation-circle' : 'check-circle'}"></i>
                    ${message}
                </div>`;
            
            setTimeout(() => {
                alertContainer.innerHTML = '';
            }, 5000);
        }

        // ฟังก์ชันแสดงข้อผิดพลาดของฟิลด์
        function showFieldError(fieldId, message) {
            const field = document.getElementById(fieldId);
            const errorElement = document.getElementById(fieldId + '_error');
            
            field.classList.add('error');
            field.classList.remove('success');
            errorElement.textContent = message;
            errorElement.style.display = 'block';
        }

        // ฟังก์ชันแสดงสถานะสำเร็จของฟิลด์
        function showFieldSuccess(fieldId) {
            const field = document.getElementById(fieldId);
            const errorElement = document.getElementById(fieldId + '_error');
            
            field.classList.add('success');
            field.classList.remove('error');
            errorElement.style.display = 'none';
        }

        // ฟังก์ชันล้างข้อผิดพลาดของฟิลด์
        function clearFieldError(fieldId) {
            const field = document.getElementById(fieldId);
            const errorElement = document.getElementById(fieldId + '_error');
            
            field.classList.remove('error', 'success');
            errorElement.style.display = 'none';
        }

        // ฟังก์ชันตรวจสอบข้อมูล
        function validateForm() {
            let isValid = true;
            
            // ตรวจสอบรหัสนักเรียน
            const studentId = document.getElementById('student_id').value.trim();
            if (!studentId) {
                showFieldError('student_id', 'กรุณากรอกรหัสนักเรียน');
                isValid = false;
            } else if (!/^[a-zA-Z0-9]+$/.test(studentId)) {
                showFieldError('student_id', 'รหัสนักเรียนต้องเป็นตัวอักษรและตัวเลขเท่านั้น');
                isValid = false;
            } else {
                showFieldSuccess('student_id');
            }
            
            // ตรวจสอบอีเมล
            const email = document.getElementById('email').value.trim();
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!email) {
                showFieldError('email', 'กรุณากรอกอีเมล');
                isValid = false;
            } else if (!emailRegex.test(email)) {
                showFieldError('email', 'รูปแบบอีเมลไม่ถูกต้อง');
                isValid = false;
            } else {
                showFieldSuccess('email');
            }
            
            // ตรวจสอบชื่อ
            const firstName = document.getElementById('first_name').value.trim();
            if (!firstName) {
                showFieldError('first_name', 'กรุณากรอกชื่อ');
                isValid = false;
            } else {
                showFieldSuccess('first_name');
            }
            
            // ตรวจสอบนามสกุล
            const lastName = document.getElementById('last_name').value.trim();
            if (!lastName) {
                showFieldError('last_name', 'กรุณากรอกนามสกุล');
                isValid = false;
            } else {
                showFieldSuccess('last_name');
            }
            
            // ตรวจสอบประเภทผู้ใช้
            const userType = document.getElementById('user_type').value;
            if (!userType) {
                showFieldError('user_type', 'กรุณาเลือกประเภทผู้ใช้');
                isValid = false;
            } else {
                showFieldSuccess('user_type');
            }
            
            // ตรวจสอบเบอร์โทรศัพท์ (ถ้ามี)
            const phone = document.getElementById('phone').value.trim();
            if (phone && !/^[0-9-+().\s]+$/.test(phone)) {
                showFieldError('phone', 'รูปแบบเบอร์โทรศัพท์ไม่ถูกต้อง');
                isValid = false;
            } else if (phone) {
                showFieldSuccess('phone');
            }
            
            // ตรวจสอบรหัสผ่าน
            const password = document.getElementById('password').value;
            if (!password) {
                showFieldError('password', 'กรุณากรอกรหัสผ่าน');
                isValid = false;
            } else if (password.length < 6) {
                showFieldError('password', 'รหัสผ่านต้องมีอย่างน้อย 6 ตัวอักษร');
                isValid = false;
            } else {
                showFieldSuccess('password');
            }
            
            // ตรวจสอบยืนยันรหัสผ่าน
            const confirmPassword = document.getElementById('confirm_password').value;
            if (!confirmPassword) {
                showFieldError('confirm_password', 'กรุณายืนยันรหัสผ่าน');
                isValid = false;
            } else if (password !== confirmPassword) {
                showFieldError('confirm_password', 'รหัสผ่านไม่ตรงกัน');
                isValid = false;
            } else {
                showFieldSuccess('confirm_password');
            }
            
            return isValid;
        }

        // Event Listeners
        document.addEventListener('DOMContentLoaded', function() {
            // ตรวจสอบความแข็งแกร่งของรหัสผ่าน
            document.getElementById('password').addEventListener('input', function() {
                checkPasswordStrength(this.value);
            });

            // ตรวจสอบการยืนยันรหัสผ่าน
            document.getElementById('confirm_password').addEventListener('input', function() {
                const password = document.getElementById('password').value;
                if (this.value && this.value !== password) {
                    showFieldError('confirm_password', 'รหัสผ่านไม่ตรงกัน');
                } else if (this.value === password && password) {
                    showFieldSuccess('confirm_password');
                }
            });

            // ล้างข้อผิดพลาดเมื่อผู้ใช้เริ่มพิมพ์
            const fields = ['student_id', 'email', 'first_name', 'last_name', 'phone'];
            fields.forEach(fieldId => {
                document.getElementById(fieldId).addEventListener('input', function() {
                    clearFieldError(fieldId);
                });
            });

            // ส่งฟอร์ม
            document.getElementById('registerForm').addEventListener('submit', function(e) {
                e.preventDefault();
                
                if (validateForm()) {
                    const btnText = document.getElementById('btn-text');
                    const loading = document.getElementById('loading');
                    const submitBtn = document.getElementById('submitBtn');
                    
                    btnText.style.display = 'none';
                    loading.style.display = 'block';
                    submitBtn.disabled = true;
                    
                    // ส่งฟอร์ม
                    this.submit();
                }
            });
        });

        // ตรวจสอบ URL parameters สำหรับแสดงข้อความ
        const urlParams = new URLSearchParams(window.location.search);
        const error = urlParams.get('error');
        const success = urlParams.get('success');
        
        if (error) {
            let message = 'เกิดข้อผิดพลาด';
            switch(error) {
                case 'student_exists':
                    message = 'รหัสนักเรียนนี้มีอยู่ในระบบแล้ว';
                    break;
                case 'email_exists':
                    message = 'อีเมลนี้มีอยู่ในระบบแล้ว';
                    break;
                case 'invalid_data':
                    message = 'ข้อมูลที่กรอกไม่ถูกต้อง';
                    break;
                case 'upload_error':
                    message = 'เกิดข้อผิดพลาดในการอัปโหลดรูปภาพ';
                    break;
                case 'database_error':
                    message = 'เกิดข้อผิดพลาดในการบันทึกข้อมูล';
                    break;
            }
            showAlert(message, 'error');
        }
        
        if (success === 'registered') {
            showAlert('สมัครสมาชิกเรียบร้อยแล้ว กรุณาเข้าสู่ระบบ', 'success');
        }
    </script>
</body>
</html>
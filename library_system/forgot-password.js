// forgot-password.js - จัดการ Modal ลืมรหัสผ่าน

let contactData = null;

// เปิด Modal ลืมรหัสผ่าน
function openForgotPasswordModal() {
    const modal = document.getElementById('forgotPasswordModal');
    modal.classList.add('show');
    
    // โหลดข้อมูลการติดต่อจากฐานข้อมูล
    loadContactInfo();
}

// ปิด Modal ลืมรหัสผ่าน
function closeForgotPasswordModal() {
    const modal = document.getElementById('forgotPasswordModal');
    modal.classList.remove('show');
}

// โหลดข้อมูลการติดต่อจากฐานข้อมูล
async function loadContactInfo() {
    const loadingElement = document.getElementById('contact-loading');
    const contentElement = document.getElementById('contact-content');
    
    try {
        loadingElement.style.display = 'flex';
        contentElement.style.display = 'none';
        
        const response = await fetch('api/get_contact_info.php');
        const result = await response.json();
        
        if (result.success) {
            contactData = result.data;
            displayContactInfo(result.data);
        } else {
            showContactError('ไม่สามารถโหลดข้อมูลการติดต่อได้');
        }
    } catch (error) {
        console.error('Error loading contact info:', error);
        showContactError('เกิดข้อผิดพลาดในการโหลดข้อมูล');
    } finally {
        loadingElement.style.display = 'none';
        contentElement.style.display = 'block';
    }
}

// แสดงข้อมูลการติดต่อ
function displayContactInfo(data) {
    const phoneElement = document.getElementById('contact-phone');
    const emailElement = document.getElementById('contact-email');
    
    if (data.phone) {
        phoneElement.innerHTML = `
            <i class="fas fa-phone"></i>
            <span>${data.phone}</span>
        `;
        phoneElement.onclick = () => window.open(`tel:${data.phone}`);
    } else {
        phoneElement.style.display = 'none';
    }
    
    if (data.email) {
        emailElement.innerHTML = `
            <i class="fas fa-envelope"></i>
            <span>${data.email}</span>
        `;
        emailElement.onclick = () => window.open(`mailto:${data.email}`);
    } else {
        emailElement.style.display = 'none';
    }
}

// แสดงข้อความผิดพลาด
function showContactError(message) {
    const contentElement = document.getElementById('contact-content');
    contentElement.innerHTML = `
        <div class="contact-error">
            <i class="fas fa-exclamation-triangle"></i>
            <p>${message}</p>
            <button class="btn-retry" onclick="loadContactInfo()">ลองใหม่</button>
        </div>
    `;
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
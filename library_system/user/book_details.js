/**
 * Enhanced Book Details Modal System with Real-time Status Updates
 * Fixed modal sync issues after book reservation
 */

// Global variables
let currentBookData = null;
let currentModalBookId = null;

/**
 * Show book details modal with real-time status checking
 * @param {number} bookId - The ID of the book to display
 */
async function showBookDetails(bookId) {
    const modal = document.getElementById('bookDetailsModal');
    const content = document.getElementById('bookDetailsContent');
    
    if (!modal || !content) {
        console.error('Book details modal elements not found');
        showNotification('เกิดข้อผิดพลาดในการแสดงรายละเอียด', 'error');
        return;
    }
    
    // Store current modal book ID for status updates
    currentModalBookId = bookId;
    
    // Show modal
    modal.style.display = 'block';
    document.body.style.overflow = 'hidden';
    
    // Simple loading state
    content.innerHTML = `
        <div class="loading-minimal">
            <div class="spinner-minimal"></div>
            <p>กำลังโหลดข้อมูล...</p>
        </div>
    `;
    
    try {
        // Force fresh data by adding timestamp to prevent caching
        const timestamp = new Date().getTime();
        const response = await fetch(`get_book_details.php?book_id=${bookId}&_=${timestamp}`);
        
        if (!response.ok) {
            throw new Error(`HTTP ${response.status}: ${response.statusText}`);
        }
        
        const result = await response.json();
        
        if (!result.success) {
            throw new Error(result.message || 'ไม่สามารถดึงข้อมูลหนังสือได้');
        }
        
        currentBookData = result.data;
        renderMinimalBookDetails(result.data);
        
    } catch (error) {
        console.error('Error loading book details:', error);
        content.innerHTML = `
            <div class="error-minimal">
                <div class="error-icon">⚠</div>
                <h4>ไม่สามารถโหลดข้อมูลได้</h4>
                <p>${error.message}</p>
                <button class="btn-minimal btn-retry" onclick="showBookDetails(${bookId})">
                    ลองอีกครั้ง
                </button>
            </div>
        `;
    }
}

/**
 * Refresh current modal with fresh data from server
 */
async function refreshModalData() {
    if (!currentModalBookId) return;
    
    try {
        const timestamp = new Date().getTime();
        const response = await fetch(`get_book_details.php?book_id=${currentModalBookId}&_=${timestamp}`);
        const result = await response.json();
        
        if (result.success) {
            currentBookData = result.data;
            renderMinimalBookDetails(result.data);
            console.log('✅ Modal data refreshed successfully');
        }
    } catch (error) {
        console.error('Error refreshing modal data:', error);
    }
}

/**
 * Enhanced render function with accurate status display
 * @param {Object} data - Book data from API
 */
function renderMinimalBookDetails(data) {
    const { book, authors } = data;
    const content = document.getElementById('bookDetailsContent');
    
    // Get real-time user status for this book
    const userStatus = getUserBookStatus(book.book_id);
    
    // Generate authors section
    const authorsHtml = generateAuthorsSection(authors);
    
    // Generate ACCURATE status display with user context
    const statusDisplay = getAccurateStatusDisplay(book, userStatus);
    
    // Generate basic book information
    content.innerHTML = `
        <div class="book-header-minimal">
            <div class="book-cover-minimal">
                ${book.cover_image && book.cover_image.trim() ? `
                    <img src="../${escapeHtml(book.cover_image)}" 
                         alt="${escapeHtml(book.title)}"
                         onerror="handleBookImageError(this)">
                ` : `
                    <div class="book-placeholder-minimal">
                        📚
                    </div>
                `}
            </div>
            
            <div class="book-info-minimal">
                <h2 class="book-title-minimal">${escapeHtml(book.title)}</h2>
                ${book.subtitle ? `<p class="book-subtitle-minimal">${escapeHtml(book.subtitle)}</p>` : ''}
                
                <div class="book-meta-minimal">
                    <div class="meta-row">
                        <span class="meta-label">รหัสหนังสือ:</span>
                        <span class="meta-value">${book.book_id}</span>
                    </div>
                    
                    ${book.isbn ? `
                        <div class="meta-row">
                            <span class="meta-label">ISBN:</span>
                            <span class="meta-value">${escapeHtml(book.isbn)}</span>
                        </div>
                    ` : ''}
                    
                    ${book.category_name ? `
                        <div class="meta-row">
                            <span class="meta-label">หมวดหมู่:</span>
                            <span class="meta-value">${escapeHtml(book.category_name)}</span>
                        </div>
                    ` : ''}
                    
                    <div class="meta-row">
                        <span class="meta-label">สถานะ:</span>
                        <span class="meta-value">${statusDisplay}</span>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Basic Information Section -->
        <div class="info-section">
            <h3 class="section-title-minimal">ข้อมูลทั่วไป</h3>
            <div class="info-grid">
                ${book.publication_year ? `
                    <div class="info-item">
                        <span class="info-label">ปีที่พิมพ์:</span>
                        <span class="info-value">${book.publication_year}</span>
                    </div>
                ` : ''}
                
                ${book.edition ? `
                    <div class="info-item">
                        <span class="info-label">ครั้งที่พิมพ์:</span>
                        <span class="info-value">${escapeHtml(book.edition)}</span>
                    </div>
                ` : ''}
                
                ${book.pages ? `
                    <div class="info-item">
                        <span class="info-label">จำนวนหน้า:</span>
                        <span class="info-value">${book.pages} หน้า</span>
                    </div>
                ` : ''}
                
                ${book.language ? `
                    <div class="info-item">
                        <span class="info-label">ภาษา:</span>
                        <span class="info-value">${escapeHtml(book.language)}</span>
                    </div>
                ` : ''}
                
                ${book.location ? `
                    <div class="info-item">
                        <span class="info-label">ตำแหน่ง:</span>
                        <span class="info-value">${escapeHtml(book.location)}</span>
                    </div>
                ` : ''}
                
                <div class="info-item">
                    <span class="info-label">จำนวนสำเนา:</span>
                    <span class="info-value">${book.total_copies || 0} เล่ม</span>
                </div>
                
                <div class="info-item ${book.available_copies > 0 ? 'available' : 'unavailable'}">
                    <span class="info-label">คงเหลือ:</span>
                    <span class="info-value">
                        ${book.available_copies || 0} เล่ม
                        ${book.available_copies <= 0 ? ' (ไม่มีเล่มว่าง)' : ' (มีให้ยืม)'}
                    </span>
                </div>
            </div>
        </div>
        
        <!-- Authors Section -->
        ${authorsHtml}
        
        <!-- Publisher Section -->
        ${book.publisher_name ? `
            <div class="info-section">
                <h3 class="section-title-minimal">สำนักพิมพ์</h3>
                <div class="publisher-info">
                    <div class="info-item">
                        <span class="info-label">ชื่อสำนักพิมพ์:</span>
                        <span class="info-value">${escapeHtml(book.publisher_name)}</span>
                    </div>
                    ${book.publisher_address ? `
                        <div class="info-item">
                            <span class="info-label">ที่อยู่:</span>
                            <span class="info-value">${escapeHtml(book.publisher_address)}</span>
                        </div>
                    ` : ''}
                    ${book.publisher_phone ? `
                        <div class="info-item">
                            <span class="info-label">โทรศัพท์:</span>
                            <span class="info-value">${escapeHtml(book.publisher_phone)}</span>
                        </div>
                    ` : ''}
                    ${book.publisher_email ? `
                        <div class="info-item">
                            <span class="info-label">อีเมล:</span>
                            <span class="info-value">${escapeHtml(book.publisher_email)}</span>
                        </div>
                    ` : ''}
                </div>
            </div>
        ` : ''}
        
        <!-- Description Section -->
        ${book.description ? `
            <div class="info-section">
                <h3 class="section-title-minimal">รายละเอียด</h3>
                <div class="description-content">
                    ${formatDescription(book.description)}
                </div>
            </div>
        ` : ''}
        
        <!-- Additional Information -->
        <div class="info-section">
            <h3 class="section-title-minimal">ข้อมูลเพิ่มเติม</h3>
            <div class="info-grid">
                ${book.price ? `
                    <div class="info-item">
                        <span class="info-label">ราคา:</span>
                        <span class="info-value">${parseFloat(book.price).toLocaleString('th-TH', { minimumFractionDigits: 2 })} บาท</span>
                    </div>
                ` : ''}
                
                ${book.acquisition_date ? `
                    <div class="info-item">
                        <span class="info-label">วันที่ได้มา:</span>
                        <span class="info-value">${formatDate(book.acquisition_date)}</span>
                    </div>
                ` : ''}
                
                <div class="info-item">
                    <span class="info-label">เพิ่มเมื่อ:</span>
                    <span class="info-value">${formatDate(book.created_at)}</span>
                </div>
                
                ${book.updated_at !== book.created_at ? `
                    <div class="info-item">
                        <span class="info-label">แก้ไขล่าสุด:</span>
                        <span class="info-value">${formatDate(book.updated_at)}</span>
                    </div>
                ` : ''}
            </div>
        </div>
        
        <!-- Close Button -->
        <div class="modal-footer-minimal">
            <button class="btn-minimal btn-close" onclick="closeBookDetailsModal()">
                ปิด
            </button>
        </div>
    `;
}

/**
 * Get accurate user-specific book status by checking main page data
 */
function getUserBookStatus(bookId) {
    // Check if this book has been reserved by user in main page
    const bookCard = document.querySelector(`[data-book-id="${bookId}"]`);
    
    if (!bookCard) {
        return { message: null, class: '' };
    }
    
    // Check for reserved status
    const reservedSpan = bookCard.querySelector('.due-date.reserved-status');
    if (reservedSpan) {
        return {
            message: 'คุณได้จองหนังสือเล่มนี้แล้ว',
            class: 'user-reserved'
        };
    }
    
    // Check for already borrowed
    const borrowedSpan = bookCard.querySelector('.due-date.overdue');
    const borrowedText = borrowedSpan ? borrowedSpan.textContent.trim() : '';
    if (borrowedText === 'ไม่ว่าง') {
        return {
            message: 'คุณกำลังยืมหนังสือเล่มนี้อยู่',
            class: 'user-borrowed'
        };
    }
    
    // Check if user can borrow (not at limit)
    const disabledBtn = bookCard.querySelector('.btn-return:disabled');
    if (disabledBtn && disabledBtn.textContent.includes('ยืมครบแล้ว')) {
        return {
            message: 'คุณยืมหนังสือครบจำนวนแล้ว ไม่สามารถจองเพิ่มได้',
            class: 'user-limit'
        };
    }
    
    return { message: null, class: '' };
}

/**
 * Generate user status alert box
 */
function generateUserStatusAlert(userStatus) {
    if (!userStatus.message) return '';
    
    let alertClass = 'info';
    let alertIcon = 'ℹ️';
    
    if (userStatus.class === 'user-reserved') {
        alertClass = 'success';
        alertIcon = '✅';
    } else if (userStatus.class === 'user-borrowed') {
        alertClass = 'warning';  
        alertIcon = '📖';
    } else if (userStatus.class === 'user-limit') {
        alertClass = 'warning';
        alertIcon = '⚠️';
    }
    
    return `
        <div class="user-alert ${alertClass}">
            <span class="alert-icon">${alertIcon}</span>
            <span class="alert-message">${userStatus.message}</span>
        </div>
    `;
}

/**
 * Get enhanced status display with more accurate information
 */
function getAccurateStatusDisplay(book, userStatus) {
    // If user has specific status, prioritize that
    if (userStatus.class === 'user-reserved') {
        return `<span class="status-badge status-user-reserved">คุณได้จองแล้ว</span>`;
    }
    
    if (userStatus.class === 'user-borrowed') {
        return `<span class="status-badge status-user-borrowed">คุณกำลังยืมอยู่</span>`;
    }
    
    // Otherwise show general status
    const statusMap = {
        'available': { 
            text: book.available_copies > 0 ? 'มีให้ยืม' : 'ไม่มีเล่มว่าง', 
            class: book.available_copies > 0 ? 'status-available' : 'status-unavailable' 
        },
        'unavailable': { text: 'ไม่ว่าง', class: 'status-unavailable' },
        'reserved': { text: 'ถูกจอง', class: 'status-reserved' },
        'damaged': { text: 'ชำรุด', class: 'status-damaged' },
        'lost': { text: 'สูญหาย', class: 'status-lost' }
    };
    
    const status = statusMap[book.status] || { text: book.status, class: 'status-unknown' };
    
    // Add availability info for available books
    if (book.status === 'available') {
        const availabilityText = book.available_copies > 0 
            ? `(เหลือ ${book.available_copies} เล่ม)` 
            : '(ไม่มีเล่มว่าง)';
        status.text += ` ${availabilityText}`;
    }
    
    return `<span class="status-badge ${status.class}">${status.text}</span>`;
}

/**
 * Generate authors section
 * @param {Array} authors - Array of author objects
 * @returns {string} HTML string for authors section
 */
function generateAuthorsSection(authors) {
    if (!authors || authors.length === 0) {
        return '';
    }
    
    return `
        <div class="info-section">
            <h3 class="section-title-minimal">ผู้เขียน</h3>
            <div class="authors-list-minimal">
                ${authors.map(author => `
                    <div class="author-item">
                        <span class="author-name">${escapeHtml(author.first_name)} ${escapeHtml(author.last_name)}</span>
                        <span class="author-role">(${getRoleDisplayName(author.role)})</span>
                    </div>
                `).join('')}
            </div>
        </div>
    `;
}

/**
 * Close modal function with cleanup
 */
function closeBookDetailsModal() {
    const modal = document.getElementById('bookDetailsModal');
    if (modal) {
        modal.style.display = 'none';
        document.body.style.overflow = 'auto';
        currentBookData = null;
        currentModalBookId = null;
    }
}

/**
 * Handle image error
 */
function handleBookImageError(img) {
    img.style.display = 'none';
    const placeholder = img.parentElement.querySelector('.book-placeholder-minimal') || 
                      document.createElement('div');
    
    if (!img.parentElement.querySelector('.book-placeholder-minimal')) {
        placeholder.className = 'book-placeholder-minimal';
        placeholder.textContent = '📚';
        img.parentElement.appendChild(placeholder);
    }
    
    placeholder.style.display = 'flex';
}

/**
 * Enhanced update function - called after successful reservation
 */
function updateModalAfterReservation(bookId) {
    if (currentModalBookId === bookId) {
        console.log('🔄 Updating modal after reservation');
        // Wait a moment for main page to update, then refresh modal
        setTimeout(() => {
            refreshModalData();
        }, 500);
    }
}

/**
 * Utility functions
 */
function escapeHtml(unsafe) {
    if (!unsafe) return '';
    return unsafe
        .toString()
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
}

function formatDescription(description) {
    if (!description) return '';
    return escapeHtml(description).replace(/\n/g, '<br>');
}

function formatDate(dateString) {
    if (!dateString) return 'ไม่ระบุ';
    
    const date = new Date(dateString);
    const options = { 
        year: 'numeric', 
        month: 'long', 
        day: 'numeric',
        timeZone: 'Asia/Bangkok'
    };
    
    return date.toLocaleDateString('th-TH', options);
}

function getRoleDisplayName(role) {
    const roleNames = {
        'author': 'ผู้เขียน',
        'co-author': 'ผู้เขียนร่วม',
        'editor': 'บรรณาธิการ',
        'translator': 'ผู้แปล'
    };
    
    return roleNames[role] || role;
}

// Event listeners
document.addEventListener('DOMContentLoaded', function() {
    // Add click listeners to book items
    document.addEventListener('click', function(event) {
        const bookItem = event.target.closest('.book-item');
        
        if (!bookItem) return;
        
        // Don't open modal if clicking on interactive elements
        if (event.target.closest('button') || 
            event.target.closest('a') ||
            event.target.closest('input') ||
            event.target.closest('select')) {
            return;
        }
        
        const bookId = bookItem.getAttribute('data-book-id');
        if (bookId) {
            showBookDetails(parseInt(bookId));
        }
    });
    
    // Modal background click handler
    window.addEventListener('click', function(event) {
        const modal = document.getElementById('bookDetailsModal');
        if (event.target === modal) {
            closeBookDetailsModal();
        }
    });
    
    // Keyboard navigation
    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape') {
            closeBookDetailsModal();
        }
        
        // F5 or Ctrl+R in modal to refresh data
        if (currentModalBookId && (event.key === 'F5' || (event.ctrlKey && event.key === 'r'))) {
            event.preventDefault();
            refreshModalData();
        }
    });
});

// Make functions globally available
window.showBookDetails = showBookDetails;
window.closeBookDetailsModal = closeBookDetailsModal;
window.handleBookImageError = handleBookImageError;
window.refreshModalData = refreshModalData;
window.updateModalAfterReservation = updateModalAfterReservation;
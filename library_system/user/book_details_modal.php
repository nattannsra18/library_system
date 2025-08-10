<?php
// book_details_modal.php - Minimal Modal component for displaying book details
?>

<!-- Book Details Modal -->
<div id="bookDetailsModal" class="modal-minimal">
    <div class="modal-content-minimal">
        <div class="modal-header-minimal">
            <h3>รายละเอียดหนังสือ</h3>
            <button class="close-minimal" onclick="closeBookDetailsModal()">&times;</button>
        </div>
        <div class="modal-body-minimal" id="bookDetailsContent">
            <div class="loading-minimal">
                <div class="spinner-minimal"></div>
                <p>กำลังโหลดข้อมูล...</p>
            </div>
        </div>
    </div>
</div>

<style>
/* Minimal Modal Base Styles */
.modal-minimal {
    display: none;
    position: fixed;
    z-index: 1000;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0, 0, 0, 0.5);
    backdrop-filter: blur(5px);
}

.modal-content-minimal {
    background: white;
    margin: 5% auto;
    border-radius: 12px;
    width: 90%;
    max-width: 800px;
    max-height: 90vh;
    overflow: hidden;
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
    animation: modalFadeIn 0.3s ease-out;
}

@keyframes modalFadeIn {
    from {
        opacity: 0;
        transform: translateY(-20px) scale(0.95);
    }
    to {
        opacity: 1;
        transform: translateY(0) scale(1);
    }
}

/* Header Styles */
.modal-header-minimal {
    background: #f8f9fa;
    padding: 1.5rem 2rem;
    border-bottom: 1px solid #e9ecef;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.modal-header-minimal h3 {
    margin: 0;
    font-size: 1.25rem;
    font-weight: 600;
    color: #343a40;
}

.close-minimal {
    background: none;
    border: none;
    font-size: 24px;
    color: #6c757d;
    cursor: pointer;
    padding: 0;
    width: 32px;
    height: 32px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s ease;
}

.close-minimal:hover {
    background-color: #e9ecef;
    color: #495057;
}

/* Body Styles */
.modal-body-minimal {
    padding: 2rem;
    overflow-y: auto;
    max-height: calc(90vh - 140px);
}

/* Book Header Section */
.book-header-minimal {
    display: flex;
    gap: 2rem;
    margin-bottom: 2rem;
    padding-bottom: 2rem;
    border-bottom: 1px solid #e9ecef;
}

.book-cover-minimal {
    flex: 0 0 150px;
    height: 200px;
    border-radius: 8px;
    overflow: hidden;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
    background-color: #f8f9fa;
    display: flex;
    align-items: center;
    justify-content: center;
}

.book-cover-minimal img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.book-placeholder-minimal {
    font-size: 3rem;
    color: #6c757d;
    display: flex;
    align-items: center;
    justify-content: center;
    width: 100%;
    height: 100%;
    background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
}

.book-info-minimal {
    flex: 1;
    min-width: 0;
}

.book-title-minimal {
    font-size: 1.75rem;
    font-weight: 700;
    color: #212529;
    margin-bottom: 0.5rem;
    line-height: 1.3;
}

.book-subtitle-minimal {
    font-size: 1.1rem;
    color: #6c757d;
    margin-bottom: 1.5rem;
    font-style: italic;
    line-height: 1.4;
}

.book-meta-minimal {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
}

.meta-row {
    display: flex;
    align-items: center;
    gap: 1rem;
}

.meta-label {
    font-weight: 600;
    color: #495057;
    min-width: 120px;
    font-size: 0.9rem;
}

.meta-value {
    color: #212529;
    font-size: 0.9rem;
}

/* Status Badge */
.status-badge {
    display: inline-block;
    padding: 0.25rem 0.75rem;
    border-radius: 20px;
    font-size: 0.8rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.status-available {
    background-color: #d4edda;
    color: #155724;
}

.status-unavailable {
    background-color: #f8d7da;
    color: #721c24;
}

.status-reserved {
    background-color: #fff3cd;
    color: #856404;
}

.status-damaged {
    background-color: #f5c6cb;
    color: #721c24;
}

.status-lost {
    background-color: #d6d8db;
    color: #383d41;
}

/* Information Sections */
.info-section {
    margin-bottom: 2rem;
    padding-bottom: 1.5rem;
    border-bottom: 1px solid #e9ecef;
}

.info-section:last-of-type {
    border-bottom: none;
    margin-bottom: 0;
}

.section-title-minimal {
    font-size: 1.1rem;
    font-weight: 600;
    color: #495057;
    margin-bottom: 1rem;
    padding-bottom: 0.5rem;
    border-bottom: 2px solid #e9ecef;
}

.info-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 1rem;
}

.info-item {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    padding: 0.75rem;
    background-color: #f8f9fa;
    border-radius: 6px;
    border-left: 3px solid #dee2e6;
}

.info-label {
    font-weight: 600;
    color: #495057;
    font-size: 0.9rem;
    margin-right: 1rem;
}

.info-value {
    color: #212529;
    font-size: 0.9rem;
    text-align: right;
    word-break: break-word;
}

/* Authors Section */
.authors-list-minimal {
    display: flex;
    flex-wrap: wrap;
    gap: 1rem;
}

.author-item {
    background-color: #f8f9fa;
    padding: 0.75rem 1rem;
    border-radius: 6px;
    border-left: 3px solid #6c757d;
}

.author-name {
    font-weight: 600;
    color: #212529;
    margin-right: 0.5rem;
}

.author-role {
    color: #6c757d;
    font-size: 0.9rem;
}

/* Publisher Info */
.publisher-info {
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

/* Description */
.description-content {
    background-color: #f8f9fa;
    padding: 1.5rem;
    border-radius: 8px;
    border-left: 4px solid #dee2e6;
    line-height: 1.6;
    color: #495057;
}

/* Loading States */
.loading-minimal {
    text-align: center;
    padding: 3rem 2rem;
}

.spinner-minimal {
    width: 40px;
    height: 40px;
    border: 3px solid #f3f3f3;
    border-top: 3px solid #6c757d;
    border-radius: 50%;
    margin: 0 auto 1rem;
    animation: spin 1s linear infinite;
}

@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}

.error-minimal {
    text-align: center;
    padding: 3rem 2rem;
    color: #721c24;
}

.error-icon {
    font-size: 3rem;
    margin-bottom: 1rem;
    display: block;
}

/* Modal Footer */
.modal-footer-minimal {
    padding-top: 2rem;
    text-align: center;
    border-top: 1px solid #e9ecef;
    margin-top: 2rem;
}

/* Buttons */
.btn-minimal {
    padding: 0.75rem 2rem;
    border: none;
    border-radius: 6px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s ease;
    font-size: 0.9rem;
}

.btn-close {
    background-color: #6c757d;
    color: white;
}

.btn-close:hover {
    background-color: #5a6268;
}

.btn-retry {
    background-color: #007bff;
    color: white;
    margin-top: 1rem;
}

.btn-retry:hover {
    background-color: #0056b3;
}

/* Responsive Design */
@media (max-width: 768px) {
    .modal-content-minimal {
        margin: 2% auto;
        width: 95%;
        max-height: 96vh;
    }
    
    .modal-body-minimal {
        padding: 1.5rem;
        max-height: calc(96vh - 120px);
    }
    
    .book-header-minimal {
        flex-direction: column;
        text-align: center;
        gap: 1.5rem;
    }
    
    .book-cover-minimal {
        flex: none;
        width: 150px;
        height: 200px;
        margin: 0 auto;
    }
    
    .info-grid {
        grid-template-columns: 1fr;
    }
    
    .authors-list-minimal {
        flex-direction: column;
    }
    
    .modal-header-minimal {
        padding: 1rem 1.5rem;
    }
    
    .book-title-minimal {
        font-size: 1.5rem;
    }
    
    .meta-row {
        flex-direction: column;
        align-items: flex-start;
        gap: 0.25rem;
    }
    
    .meta-label {
        min-width: auto;
    }
    
    .info-item {
        flex-direction: column;
        gap: 0.5rem;
    }
    
    .info-value {
        text-align: left;
    }
}

@media (max-width: 480px) {
    .modal-content-minimal {
        width: 98%;
        margin: 1% auto;
    }
    
    .modal-body-minimal {
        padding: 1rem;
    }
    
    .modal-header-minimal {
        padding: 1rem;
    }
    
    .book-title-minimal {
        font-size: 1.3rem;
    }
    
    .section-title-minimal {
        font-size: 1rem;
    }
}

/* Enhanced scrollbar */
.modal-body-minimal::-webkit-scrollbar {
    width: 6px;
}

.modal-body-minimal::-webkit-scrollbar-track {
    background: #f1f1f1;
    border-radius: 10px;
}

.modal-body-minimal::-webkit-scrollbar-thumb {
    background: #c1c1c1;
    border-radius: 10px;
}

.modal-body-minimal::-webkit-scrollbar-thumb:hover {
    background: #a8a8a8;
}
</style>
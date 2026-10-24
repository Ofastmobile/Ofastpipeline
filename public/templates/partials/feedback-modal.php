<?php
/**
 * Partial: Client Portal Feedback & Bug Report Modal
 * Accessible from topbar, sidebar, and profile dropdown across all portal pages.
 *
 * @package OFast_Pipeline
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>

<!-- ── Client Feedback & Bug Report Modal ─────────────────────────────────── -->
<div class="ofp-client-modal-backdrop" id="ofp-client-feedback-modal" style="display: none;" aria-hidden="true" role="dialog" aria-modal="true">
    <div class="ofp-client-modal-card">
        
        <!-- Header -->
        <div class="ofp-cfb-header">
            <div class="ofp-cfb-header-text">
                <h3 id="ofp-cfb-modal-title">Share Your Feedback</h3>
                <p>Help us improve your CRM experience with feature suggestions or issue reports.</p>
            </div>
            <button type="button" class="ofp-cfb-close-btn" id="ofp-cfb-close-btn" aria-label="Close dialog">&times;</button>
        </div>

        <!-- Segmented Tab Switcher -->
        <div class="ofp-cfb-tabs-segmented">
            <button type="button" class="ofp-cfb-tab-btn active" id="ofp-cfb-tab-feature" data-target="feature">
                <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z" /></svg>
                <span>Request a Feature</span>
            </button>
            <button type="button" class="ofp-cfb-tab-btn" id="ofp-cfb-tab-bug" data-target="bug">
                <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" /></svg>
                <span>Report a Bug</span>
            </button>
        </div>

        <!-- Feedback Form -->
        <form id="ofp-cfb-form" class="ofp-cfb-form">
            <input type="hidden" id="ofp-cfb-type" name="type" value="feature_request">
            <input type="hidden" id="ofp-cfb-page-url" name="page_url" value="">
            <input type="hidden" id="ofp-cfb-device-info" name="device_info" value="">

            <div class="ofp-cfb-alert" id="ofp-cfb-error-alert" style="display: none;"></div>

            <!-- Title Field -->
            <div class="ofp-cfb-form-group">
                <label for="ofp-cfb-title" id="ofp-cfb-title-label">Feature Title <span class="req">*</span></label>
                <input type="text" id="ofp-cfb-title" name="title" class="ofp-cfb-input" placeholder="e.g. Bulk WhatsApp rent reminder broadcast" required>
            </div>

            <!-- Category & Priority Row -->
            <div class="ofp-cfb-grid-2">
                <div class="ofp-cfb-form-group">
                    <label for="ofp-cfb-category">Category</label>
                    <div class="ofp-cfb-custom-select-wrap" id="ofp-cfb-category-wrap">
                        <select id="ofp-cfb-category" name="category" class="ofp-cfb-native-select">
                            <option value="Properties & Listings">Properties & Listings</option>
                            <option value="Sales & Installments">Sales & Installments</option>
                            <option value="Rent & Tenants">Rent & Tenants</option>
                            <option value="CRM Leads & Contacts">CRM Leads & Contacts</option>
                            <option value="Billing & Subscriptions">Billing & Subscriptions</option>
                            <option value="Messaging & Automation">Messaging & Automation</option>
                            <option value="Dashboard & General UI">Dashboard & General UI</option>
                            <option value="Other">Other</option>
                        </select>
                        <button type="button" class="ofp-cfb-select-trigger" id="ofp-cfb-category-trigger" aria-haspopup="listbox" aria-expanded="false">
                            <span class="ofp-cfb-select-value">Properties & Listings</span>
                            <svg class="ofp-cfb-select-arrow" width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.2" d="M19 9l-7 7-7-7" /></svg>
                        </button>
                        <div class="ofp-cfb-select-menu" id="ofp-cfb-category-menu" role="listbox"></div>
                    </div>
                </div>

                <div class="ofp-cfb-form-group">
                    <label for="ofp-cfb-priority" id="ofp-cfb-priority-label">Business Impact / Priority</label>
                    <div class="ofp-cfb-custom-select-wrap" id="ofp-cfb-priority-wrap">
                        <select id="ofp-cfb-priority" name="priority" class="ofp-cfb-native-select">
                            <option value="low">Low (Nice to have)</option>
                            <option value="medium" selected>Medium (Helpful for workflow)</option>
                            <option value="high">High (Major value / Important)</option>
                            <option value="critical">Critical (Blocks core business)</option>
                        </select>
                        <button type="button" class="ofp-cfb-select-trigger" id="ofp-cfb-priority-trigger" aria-haspopup="listbox" aria-expanded="false">
                            <span class="ofp-cfb-select-value">Medium (Helpful for workflow)</span>
                            <svg class="ofp-cfb-select-arrow" width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.2" d="M19 9l-7 7-7-7" /></svg>
                        </button>
                        <div class="ofp-cfb-select-menu" id="ofp-cfb-priority-menu" role="listbox"></div>
                    </div>
                </div>
            </div>

            <!-- Detailed Description Field -->
            <div class="ofp-cfb-form-group">
                <label for="ofp-cfb-description" id="ofp-cfb-desc-label">Detailed Explanation <span class="req">*</span></label>
                <textarea id="ofp-cfb-description" name="description" rows="4" class="ofp-cfb-input" placeholder="Please describe what you want to achieve, how it should work, and why it's valuable..." required></textarea>
            </div>

            <!-- Video Explanation URL Field -->
            <div class="ofp-cfb-form-group">
                <div class="ofp-cfb-label-row">
                    <label for="ofp-cfb-video-url" id="ofp-cfb-video-label">Video Explanation URL (Optional)</label>
                    <span class="ofp-cfb-badge-hint">YouTube or Loom</span>
                </div>
                <div class="ofp-cfb-video-input-wrap">
                    <svg class="ofp-cfb-video-input-icon" width="16" height="16" fill="currentColor" viewBox="0 0 24 24"><path d="M19.615 3.184c-3.604-.246-11.631-.245-15.23 0-3.897.266-4.356 2.62-4.385 8.816.029 6.185.484 8.549 4.385 8.816 3.6.245 11.626.246 15.23 0 3.897-.266 4.356-2.62 4.385-8.816-.029-6.185-.484-8.549-4.385-8.816zm-10.615 12.816v-8l8 3.993-8 4.007z"/></svg>
                    <input type="url" id="ofp-cfb-video-url" name="video_url" class="ofp-cfb-input" placeholder="Paste YouTube link (e.g. https://youtu.be/... or loom.com/share/...)">
                </div>
                <div class="ofp-cfb-helper-text" id="ofp-cfb-video-hint">
                    Have a YouTube or Loom video explaining your idea or showing the glitch? Paste it here so we can watch it!
                </div>
                <!-- Dynamic Video Detection Badge & Live Preview -->
                <div class="ofp-cfb-video-preview-pill" id="ofp-cfb-video-pill" style="display: none;">
                    <span class="ofp-cfb-video-pill-dot"></span>
                    <span id="ofp-cfb-video-pill-text">Valid video detected</span>
                </div>
            </div>

            <!-- Auto-captured Diagnostic Notice (Bug Report Mode) -->
            <div class="ofp-cfb-diag-box" id="ofp-cfb-diag-box" style="display: none;">
                <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                <span>Current page URL and device environment will be auto-attached to help our engineers diagnose faster.</span>
            </div>

            <!-- Footer Action Buttons -->
            <div class="ofp-cfb-footer">
                <button type="button" class="ofp-cfb-btn-secondary" id="ofp-cfb-cancel-btn">Cancel</button>
                <button type="submit" class="ofp-cfb-btn-primary" id="ofp-cfb-submit-btn">
                    <span class="btn-text" id="ofp-cfb-btn-text">Submit Request</span>
                    <span class="btn-spinner" id="ofp-cfb-btn-spinner" style="display: none;"></span>
                </button>
            </div>
        </form>

        <!-- Success Screen -->
        <div class="ofp-cfb-success-screen" id="ofp-cfb-success-screen" style="display: none;">
            <div class="ofp-cfb-success-icon">
                <svg width="36" height="36" fill="none" stroke="#10b981" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7" /></svg>
            </div>
            <h3 id="ofp-cfb-success-title">Submission Received!</h3>
            <p id="ofp-cfb-success-message">Thank you for sharing your feedback. Our product and engineering team reviews all submissions.</p>
            <button type="button" class="ofp-cfb-btn-primary" id="ofp-cfb-done-btn">Done</button>
        </div>

    </div>
</div>

<style>
/* ── Client Feedback Modal Styles (Platform Frosted Glass) ── */
.ofp-client-modal-backdrop {
    position: fixed;
    inset: 0;
    background: rgba(10, 15, 26, 0.72);
    backdrop-filter: blur(16px) saturate(180%);
    -webkit-backdrop-filter: blur(16px) saturate(180%);
    z-index: 999999;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px 16px;
    box-sizing: border-box;
    animation: ofpFadeIn 0.22s cubic-bezier(0.16, 1, 0.3, 1);
}

@keyframes ofpFadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}

.ofp-client-modal-card {
    width: 100%;
    max-width: 600px;
    background: rgba(255, 255, 255, 0.88);
    backdrop-filter: blur(28px) saturate(200%);
    -webkit-backdrop-filter: blur(28px) saturate(200%);
    border: 1px solid rgba(255, 255, 255, 0.85);
    border-radius: 20px;
    box-shadow: 0 30px 70px -15px rgba(15, 23, 42, 0.2), inset 0 1px 1px 0 #ffffff;
    max-height: 92vh;
    overflow-y: auto;
    position: relative;
    box-sizing: border-box;
    font-family: inherit;
    animation: ofpSlideUp 0.25s cubic-bezier(0.16, 1, 0.3, 1);
}

@keyframes ofpSlideUp {
    from { transform: translateY(16px) scale(0.97); opacity: 0; }
    to { transform: translateY(0) scale(1); opacity: 1; }
}

body.ofp-theme-dark .ofp-client-modal-card,
[data-theme="dark"] .ofp-client-modal-card,
html.dark .ofp-client-modal-card {
    background: rgba(18, 24, 38, 0.85);
    backdrop-filter: blur(28px) saturate(200%);
    -webkit-backdrop-filter: blur(28px) saturate(200%);
    color: #f3f4f6;
    border-color: rgba(255, 255, 255, 0.15);
    box-shadow: 0 30px 70px -15px rgba(0, 0, 0, 0.75), inset 0 1px 0 0 rgba(255, 255, 255, 0.2);
}

/* Header */
.ofp-cfb-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    padding: 22px 24px 16px;
    border-bottom: 1px solid rgba(226, 232, 240, 0.8);
}

body.ofp-theme-dark .ofp-cfb-header,
[data-theme="dark"] .ofp-cfb-header,
html.dark .ofp-cfb-header {
    border-bottom-color: rgba(255, 255, 255, 0.08);
}

.ofp-cfb-header-text h3 {
    margin: 0 0 4px 0;
    font-size: 19px;
    font-weight: 700;
    color: #0f172a;
    letter-spacing: -0.01em;
}

body.ofp-theme-dark .ofp-cfb-header-text h3,
[data-theme="dark"] .ofp-cfb-header-text h3,
html.dark .ofp-cfb-header-text h3 {
    color: #ffffff;
}

.ofp-cfb-header-text p {
    margin: 0;
    font-size: 13px;
    color: #64748b;
    line-height: 1.45;
}

body.ofp-theme-dark .ofp-cfb-header-text p,
[data-theme="dark"] .ofp-cfb-header-text p,
html.dark .ofp-cfb-header-text p {
    color: #94a3b8;
}

.ofp-cfb-close-btn {
    background: rgba(0, 0, 0, 0.04);
    border: 1px solid rgba(0, 0, 0, 0.06);
    font-size: 20px;
    line-height: 1;
    color: #64748b;
    cursor: pointer;
    width: 32px;
    height: 32px;
    border-radius: 9px;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.15s ease;
}

.ofp-cfb-close-btn:hover {
    background: #ef4444;
    border-color: #ef4444;
    color: #ffffff;
    transform: scale(1.05);
}

body.ofp-theme-dark .ofp-cfb-close-btn,
[data-theme="dark"] .ofp-cfb-close-btn,
html.dark .ofp-cfb-close-btn {
    background: rgba(255, 255, 255, 0.08);
    border-color: rgba(255, 255, 255, 0.12);
    color: #cbd5e1;
}

body.ofp-theme-dark .ofp-cfb-close-btn:hover,
[data-theme="dark"] .ofp-cfb-close-btn:hover,
html.dark .ofp-cfb-close-btn:hover {
    background: #ef4444;
    border-color: #ef4444;
    color: #ffffff;
}

/* Tabs */
.ofp-cfb-tabs-segmented {
    display: flex;
    margin: 16px 24px 8px;
    padding: 4px;
    background: rgba(0, 0, 0, 0.035);
    border: 1px solid rgba(0, 0, 0, 0.06);
    border-radius: 12px;
    gap: 4px;
    backdrop-filter: blur(12px);
    -webkit-backdrop-filter: blur(12px);
}

body.ofp-theme-dark .ofp-cfb-tabs-segmented,
[data-theme="dark"] .ofp-cfb-tabs-segmented,
html.dark .ofp-cfb-tabs-segmented {
    background: rgba(0, 0, 0, 0.28);
    border-color: rgba(255, 255, 255, 0.08);
}

.ofp-cfb-tab-btn {
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 9px 14px;
    border: 1px solid transparent;
    background: transparent;
    color: #64748b;
    font-size: 13px;
    font-weight: 600;
    border-radius: 9px;
    cursor: pointer;
    transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
}

body.ofp-theme-dark .ofp-cfb-tab-btn,
[data-theme="dark"] .ofp-cfb-tab-btn,
html.dark .ofp-cfb-tab-btn {
    color: #94a3af;
}

.ofp-cfb-tab-btn:hover {
    color: #0f172a;
    background: rgba(255, 255, 255, 0.4);
}

body.ofp-theme-dark .ofp-cfb-tab-btn:hover,
[data-theme="dark"] .ofp-cfb-tab-btn:hover,
html.dark .ofp-cfb-tab-btn:hover {
    color: #ffffff;
    background: rgba(255, 255, 255, 0.08);
}

.ofp-cfb-tab-btn.active {
    background: #ffffff;
    color: #0f172a;
    border-color: rgba(0, 0, 0, 0.06);
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08), inset 0 1px 1px #ffffff;
}

body.ofp-theme-dark .ofp-cfb-tab-btn.active,
[data-theme="dark"] .ofp-cfb-tab-btn.active,
html.dark .ofp-cfb-tab-btn.active {
    background: rgba(255, 255, 255, 0.12);
    border-color: rgba(255, 255, 255, 0.16);
    color: #ffffff;
    box-shadow: 0 4px 14px rgba(0, 0, 0, 0.28), inset 0 1px 0 0 rgba(255, 255, 255, 0.2);
}

/* Form Container */
.ofp-cfb-form {
    padding: 16px 24px 24px;
}

.ofp-cfb-form-group {
    margin-bottom: 16px;
    position: relative;
}

.ofp-cfb-form-group label {
    display: block;
    margin-bottom: 6px;
    font-size: 13px;
    font-weight: 600;
    color: #334155;
}

body.ofp-theme-dark .ofp-cfb-form-group label,
[data-theme="dark"] .ofp-cfb-form-group label,
html.dark .ofp-cfb-form-group label {
    color: #e2e8f0;
}

.ofp-cfb-form-group label .req {
    color: #ef4444;
}

.ofp-cfb-label-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 6px;
}

.ofp-cfb-label-row label {
    margin-bottom: 0;
}

.ofp-cfb-badge-hint {
    font-size: 11px;
    font-weight: 600;
    background: rgba(239, 68, 68, 0.1);
    color: #dc2626;
    padding: 2px 8px;
    border-radius: 6px;
    border: 1px solid rgba(239, 68, 68, 0.2);
}

body.ofp-theme-dark .ofp-cfb-badge-hint,
[data-theme="dark"] .ofp-cfb-badge-hint,
html.dark .ofp-cfb-badge-hint {
    background: rgba(239, 68, 68, 0.18);
    border-color: rgba(239, 68, 68, 0.3);
    color: #f87171;
}

/* Glass Form Inputs */
.ofp-cfb-input {
    width: 100%;
    box-sizing: border-box;
    padding: 11px 15px;
    border: 1px solid rgba(203, 213, 225, 0.7);
    border-radius: 12px;
    font-size: 14px;
    background: rgba(255, 255, 255, 0.65);
    backdrop-filter: blur(12px);
    -webkit-backdrop-filter: blur(12px);
    color: #0f172a;
    outline: none;
    transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
    font-family: inherit;
}

.ofp-cfb-input::placeholder {
    color: #94a3b8;
}

.ofp-cfb-input:focus {
    border-color: #10b981;
    background: rgba(255, 255, 255, 0.95);
    box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.18), 0 8px 20px -4px rgba(16, 185, 129, 0.15);
}

body.ofp-theme-dark .ofp-cfb-input,
[data-theme="dark"] .ofp-cfb-input,
html.dark .ofp-cfb-input {
    background: rgba(255, 255, 255, 0.05);
    border-color: rgba(255, 255, 255, 0.12);
    color: #f8fafc;
}

body.ofp-theme-dark .ofp-cfb-input::placeholder,
[data-theme="dark"] .ofp-cfb-input::placeholder,
html.dark .ofp-cfb-input::placeholder {
    color: #64748b;
}

body.ofp-theme-dark .ofp-cfb-input:focus,
[data-theme="dark"] .ofp-cfb-input:focus,
html.dark .ofp-cfb-input:focus {
    background: rgba(255, 255, 255, 0.09);
    border-color: #10b981;
    box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.25), 0 8px 24px -4px rgba(16, 185, 129, 0.2);
}

textarea.ofp-cfb-input {
    resize: vertical;
    min-height: 96px;
    line-height: 1.5;
}

.ofp-cfb-grid-2 {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 14px;
}

@media (max-width: 520px) {
    .ofp-cfb-grid-2 { grid-template-columns: 1fr; }
}

/* ── Custom Platform Glass Dropdown Component ── */
.ofp-cfb-custom-select-wrap {
    position: relative;
    width: 100%;
}

.ofp-cfb-native-select {
    position: absolute !important;
    width: 1px !important;
    height: 1px !important;
    padding: 0 !important;
    margin: -1px !important;
    overflow: hidden !important;
    clip: rect(0,0,0,0) !important;
    border: 0 !important;
    opacity: 0 !important;
    pointer-events: none !important;
}

.ofp-cfb-select-trigger {
    width: 100%;
    box-sizing: border-box;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    padding: 11px 14px;
    border-radius: 12px;
    font-size: 13.5px;
    font-weight: 500;
    cursor: pointer;
    text-align: left;
    background: rgba(255, 255, 255, 0.65);
    backdrop-filter: blur(12px);
    -webkit-backdrop-filter: blur(12px);
    border: 1px solid rgba(203, 213, 225, 0.7);
    color: #0f172a;
    transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
    font-family: inherit;
}

.ofp-cfb-select-trigger:hover {
    border-color: #10b981;
    background: rgba(255, 255, 255, 0.85);
}

.ofp-cfb-select-trigger:focus,
.ofp-cfb-custom-select-wrap.open .ofp-cfb-select-trigger {
    border-color: #10b981;
    background: rgba(255, 255, 255, 0.95);
    box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.18), 0 8px 20px -4px rgba(16, 185, 129, 0.15);
    outline: none;
}

body.ofp-theme-dark .ofp-cfb-select-trigger,
[data-theme="dark"] .ofp-cfb-select-trigger,
html.dark .ofp-cfb-select-trigger {
    background: rgba(255, 255, 255, 0.05);
    border-color: rgba(255, 255, 255, 0.12);
    color: #f8fafc;
}

body.ofp-theme-dark .ofp-cfb-select-trigger:hover,
[data-theme="dark"] .ofp-cfb-select-trigger:hover,
html.dark .ofp-cfb-select-trigger:hover {
    background: rgba(255, 255, 255, 0.08);
    border-color: rgba(255, 255, 255, 0.2);
}

body.ofp-theme-dark .ofp-cfb-select-trigger:focus,
body.ofp-theme-dark .ofp-cfb-custom-select-wrap.open .ofp-cfb-select-trigger,
[data-theme="dark"] .ofp-cfb-select-trigger:focus,
[data-theme="dark"] .ofp-cfb-custom-select-wrap.open .ofp-cfb-select-trigger,
html.dark .ofp-cfb-select-trigger:focus,
html.dark .ofp-cfb-custom-select-wrap.open .ofp-cfb-select-trigger {
    background: rgba(255, 255, 255, 0.09);
    border-color: #10b981;
    box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.25), 0 8px 24px -4px rgba(16, 185, 129, 0.2);
}

.ofp-cfb-select-value {
    flex: 1;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.ofp-cfb-select-arrow {
    color: #94a3b8;
    flex-shrink: 0;
    transition: transform 0.22s cubic-bezier(0.16, 1, 0.3, 1), color 0.15s ease;
}

.ofp-cfb-custom-select-wrap.open .ofp-cfb-select-arrow {
    transform: rotate(180deg);
    color: #10b981;
}

/* Floating Frosted Glass Menu */
.ofp-cfb-select-menu {
    position: absolute;
    top: calc(100% + 6px);
    left: 0;
    right: 0;
    z-index: 99999;
    max-height: 230px;
    overflow-y: auto;
    padding: 6px;
    border-radius: 14px;
    background: rgba(255, 255, 255, 0.94);
    backdrop-filter: blur(28px) saturate(200%);
    -webkit-backdrop-filter: blur(28px) saturate(200%);
    border: 1px solid rgba(226, 232, 240, 0.9);
    box-shadow: 0 20px 48px 0 rgba(15, 23, 42, 0.16), inset 0 1px 1px 0 #ffffff;
    display: none;
    flex-direction: column;
    gap: 2px;
    box-sizing: border-box;
}

.ofp-cfb-custom-select-wrap.open .ofp-cfb-select-menu {
    display: flex;
    animation: ofpDropdownFade 0.18s cubic-bezier(0.16, 1, 0.3, 1);
}

@keyframes ofpDropdownFade {
    from { opacity: 0; transform: translateY(-6px) scale(0.98); }
    to { opacity: 1; transform: translateY(0) scale(1); }
}

body.ofp-theme-dark .ofp-cfb-select-menu,
[data-theme="dark"] .ofp-cfb-select-menu,
html.dark .ofp-cfb-select-menu {
    background: rgba(18, 24, 38, 0.95);
    backdrop-filter: blur(28px) saturate(200%);
    -webkit-backdrop-filter: blur(28px) saturate(200%);
    border-color: rgba(255, 255, 255, 0.16);
    box-shadow: 0 20px 48px 0 rgba(0, 0, 0, 0.65), inset 0 1px 0 0 rgba(255, 255, 255, 0.2);
}

.ofp-cfb-select-item {
    padding: 8px 12px;
    border-radius: 8px;
    font-size: 13.5px;
    font-weight: 500;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: space-between;
    color: #334155;
    transition: all 0.15s ease;
    user-select: none;
}

.ofp-cfb-select-item:hover {
    background: rgba(16, 185, 129, 0.1);
    color: #059669;
}

.ofp-cfb-select-item.selected {
    background: rgba(16, 185, 129, 0.15);
    color: #059669;
    font-weight: 600;
}

body.ofp-theme-dark .ofp-cfb-select-item,
[data-theme="dark"] .ofp-cfb-select-item,
html.dark .ofp-cfb-select-item {
    color: #cbd5e1;
}

body.ofp-theme-dark .ofp-cfb-select-item:hover,
[data-theme="dark"] .ofp-cfb-select-item:hover,
html.dark .ofp-cfb-select-item:hover {
    background: rgba(16, 185, 129, 0.15);
    color: #34d399;
}

body.ofp-theme-dark .ofp-cfb-select-item.selected,
[data-theme="dark"] .ofp-cfb-select-item.selected,
html.dark .ofp-cfb-select-item.selected {
    background: rgba(16, 185, 129, 0.22);
    color: #10b981;
    font-weight: 600;
}

.ofp-cfb-select-item-check {
    width: 14px;
    height: 14px;
    color: #10b981;
    opacity: 0;
    transition: opacity 0.15s ease;
}

.ofp-cfb-select-item.selected .ofp-cfb-select-item-check {
    opacity: 1;
}

/* Video input */
.ofp-cfb-video-input-wrap {
    position: relative;
    display: flex;
    align-items: center;
}

.ofp-cfb-video-input-icon {
    position: absolute;
    left: 14px;
    color: #ef4444;
    pointer-events: none;
}

.ofp-cfb-video-input-wrap .ofp-cfb-input {
    padding-left: 38px;
}

.ofp-cfb-helper-text {
    font-size: 12px;
    color: #64748b;
    margin-top: 5px;
    line-height: 1.4;
}

body.ofp-theme-dark .ofp-cfb-helper-text,
[data-theme="dark"] .ofp-cfb-helper-text,
html.dark .ofp-cfb-helper-text {
    color: #94a3af;
}

.ofp-cfb-video-preview-pill {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    margin-top: 6px;
    background: rgba(16, 185, 129, 0.12);
    color: #059669;
    border: 1px solid rgba(16, 185, 129, 0.25);
    padding: 3px 10px;
    border-radius: 9999px;
    font-size: 11px;
    font-weight: 600;
    backdrop-filter: blur(8px);
}

body.ofp-theme-dark .ofp-cfb-video-preview-pill,
[data-theme="dark"] .ofp-cfb-video-preview-pill,
html.dark .ofp-cfb-video-preview-pill {
    background: rgba(16, 185, 129, 0.18);
    border-color: rgba(16, 185, 129, 0.35);
    color: #6ee7b7;
}

.ofp-cfb-video-pill-dot {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: #10b981;
    box-shadow: 0 0 6px #10b981;
}

.ofp-cfb-diag-box {
    display: flex;
    align-items: center;
    gap: 10px;
    background: rgba(255, 255, 255, 0.5);
    backdrop-filter: blur(8px);
    -webkit-backdrop-filter: blur(8px);
    border: 1px dashed rgba(203, 213, 225, 0.9);
    border-radius: 12px;
    padding: 12px 14px;
    font-size: 12.5px;
    color: #64748b;
    margin-bottom: 16px;
    line-height: 1.45;
}

body.ofp-theme-dark .ofp-cfb-diag-box,
[data-theme="dark"] .ofp-cfb-diag-box,
html.dark .ofp-cfb-diag-box {
    background: rgba(255, 255, 255, 0.04);
    border-color: rgba(255, 255, 255, 0.14);
    color: #94a3b8;
}

.ofp-cfb-alert {
    padding: 11px 15px;
    border-radius: 10px;
    font-size: 13px;
    margin-bottom: 14px;
    background: rgba(239, 68, 68, 0.1);
    color: #b91c1c;
    border: 1px solid rgba(239, 68, 68, 0.25);
    backdrop-filter: blur(8px);
}

body.ofp-theme-dark .ofp-cfb-alert,
[data-theme="dark"] .ofp-cfb-alert,
html.dark .ofp-cfb-alert {
    background: rgba(239, 68, 68, 0.16);
    border-color: rgba(239, 68, 68, 0.35);
    color: #fca5a5;
}

/* Footer buttons */
.ofp-cfb-footer {
    display: flex;
    justify-content: flex-end;
    align-items: center;
    gap: 10px;
    margin-top: 20px;
    padding-top: 18px;
    border-top: 1px solid rgba(226, 232, 240, 0.8);
}

body.ofp-theme-dark .ofp-cfb-footer,
[data-theme="dark"] .ofp-cfb-footer,
html.dark .ofp-cfb-footer {
    border-top-color: rgba(255, 255, 255, 0.08);
}

.ofp-cfb-btn-secondary {
    padding: 10px 18px;
    border-radius: 11px;
    border: 1px solid rgba(203, 213, 225, 0.8);
    background: rgba(255, 255, 255, 0.6);
    backdrop-filter: blur(12px);
    -webkit-backdrop-filter: blur(12px);
    color: #475569;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s ease;
}

.ofp-cfb-btn-secondary:hover {
    background: rgba(255, 255, 255, 0.95);
    border-color: rgba(148, 163, 184, 0.9);
}

body.ofp-theme-dark .ofp-cfb-btn-secondary,
[data-theme="dark"] .ofp-cfb-btn-secondary,
html.dark .ofp-cfb-btn-secondary {
    background: rgba(255, 255, 255, 0.06);
    border-color: rgba(255, 255, 255, 0.14);
    color: #cbd5e1;
}

body.ofp-theme-dark .ofp-cfb-btn-secondary:hover,
[data-theme="dark"] .ofp-cfb-btn-secondary:hover,
html.dark .ofp-cfb-btn-secondary:hover {
    background: rgba(255, 255, 255, 0.12);
    border-color: rgba(255, 255, 255, 0.22);
    color: #ffffff;
}

.ofp-cfb-btn-primary {
    padding: 10px 22px;
    border-radius: 11px;
    border: 1px solid rgba(255, 255, 255, 0.25);
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
    color: #ffffff;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    box-shadow: 0 4px 16px rgba(16, 185, 129, 0.35), inset 0 1px 0 0 rgba(255, 255, 255, 0.3);
    transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
}

.ofp-cfb-btn-primary:hover {
    background: linear-gradient(135deg, #059669 0%, #047857 100%);
    box-shadow: 0 8px 24px rgba(16, 185, 129, 0.45), inset 0 1px 0 0 rgba(255, 255, 255, 0.4);
    transform: translateY(-1px);
}

.ofp-cfb-btn-primary:active {
    transform: scale(0.98);
}

.ofp-cfb-btn-primary:disabled {
    opacity: 0.65;
    cursor: not-allowed;
    transform: none;
}

.btn-spinner {
    width: 14px;
    height: 14px;
    border: 2px solid rgba(255,255,255,0.3);
    border-top-color: #ffffff;
    border-radius: 50%;
    animation: ofpSpin 0.7s linear infinite;
}

@keyframes ofpSpin { to { transform: rotate(360deg); } }

/* Success Screen */
.ofp-cfb-success-screen {
    padding: 44px 24px;
    text-align: center;
}

.ofp-cfb-success-icon {
    width: 68px;
    height: 68px;
    border-radius: 50%;
    background: rgba(16, 185, 129, 0.14);
    border: 1px solid rgba(16, 185, 129, 0.3);
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 18px;
    box-shadow: 0 8px 24px -4px rgba(16, 185, 129, 0.3);
}

body.ofp-theme-dark .ofp-cfb-success-icon,
[data-theme="dark"] .ofp-cfb-success-icon,
html.dark .ofp-cfb-success-icon {
    background: rgba(16, 185, 129, 0.2);
    border-color: rgba(16, 185, 129, 0.4);
}

.ofp-cfb-success-screen h3 {
    margin: 0 0 8px 0;
    font-size: 21px;
    color: #0f172a;
    font-weight: 700;
}

body.ofp-theme-dark .ofp-cfb-success-screen h3,
[data-theme="dark"] .ofp-cfb-success-screen h3,
html.dark .ofp-cfb-success-screen h3 {
    color: #ffffff;
}

.ofp-cfb-success-screen p {
    margin: 0 0 24px 0;
    font-size: 14px;
    color: #64748b;
    line-height: 1.5;
    max-width: 420px;
    margin-left: auto;
    margin-right: auto;
}

body.ofp-theme-dark .ofp-cfb-success-screen p,
[data-theme="dark"] .ofp-cfb-success-screen p,
html.dark .ofp-cfb-success-screen p {
    color: #94a3af;
}
</style>

<script>
(function() {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var modal        = document.getElementById('ofp-client-feedback-modal');
        var closeBtn     = document.getElementById('ofp-cfb-close-btn');
        var cancelBtn    = document.getElementById('ofp-cfb-cancel-btn');
        var doneBtn      = document.getElementById('ofp-cfb-done-btn');
        var form         = document.getElementById('ofp-cfb-form');
        var successScr   = document.getElementById('ofp-cfb-success-screen');
        var tabFeature   = document.getElementById('ofp-cfb-tab-feature');
        var tabBug       = document.getElementById('ofp-cfb-tab-bug');
        var typeInput    = document.getElementById('ofp-cfb-type');
        var pageUrlInput = document.getElementById('ofp-cfb-page-url');
        var deviceInput  = document.getElementById('ofp-cfb-device-info');
        var titleLabel   = document.getElementById('ofp-cfb-title-label');
        var titleInput   = document.getElementById('ofp-cfb-title');
        var descLabel    = document.getElementById('ofp-cfb-desc-label');
        var descInput    = document.getElementById('ofp-cfb-description');
        var videoInput   = document.getElementById('ofp-cfb-video-url');
        var videoHint    = document.getElementById('ofp-cfb-video-hint');
        var videoPill    = document.getElementById('ofp-cfb-video-pill');
        var videoPillText= document.getElementById('ofp-cfb-video-pill-text');
        var diagBox      = document.getElementById('ofp-cfb-diag-box');
        var submitBtn    = document.getElementById('ofp-cfb-submit-btn');
        var btnText      = document.getElementById('ofp-cfb-btn-text');
        var btnSpinner   = document.getElementById('ofp-cfb-btn-spinner');
        var errorAlert   = document.getElementById('ofp-cfb-error-alert');
        var modalTitle   = document.getElementById('ofp-cfb-modal-title');
        var prioLabel    = document.getElementById('ofp-cfb-priority-label');

        if ( ! modal ) return;

        // ── Custom Platform Glass Dropdown Handler ──
        function initCfbCustomSelects() {
            var wraps = modal.querySelectorAll('.ofp-cfb-custom-select-wrap');
            wraps.forEach(function(wrap) {
                var select  = wrap.querySelector('.ofp-cfb-native-select');
                var trigger = wrap.querySelector('.ofp-cfb-select-trigger');
                var valSpan = wrap.querySelector('.ofp-cfb-select-value');
                var menu    = wrap.querySelector('.ofp-cfb-select-menu');
                if ( ! select || ! trigger || ! valSpan || ! menu ) return;

                menu.innerHTML = '';
                Array.from(select.options).forEach(function(opt) {
                    var item = document.createElement('div');
                    item.className = 'ofp-cfb-select-item' + (opt.selected ? ' selected' : '');
                    item.setAttribute('role', 'option');
                    item.setAttribute('aria-selected', opt.selected ? 'true' : 'false');
                    item.dataset.value = opt.value;

                    var labelSpan = document.createElement('span');
                    labelSpan.textContent = opt.text;
                    item.appendChild(labelSpan);

                    var checkSvg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
                    checkSvg.setAttribute('class', 'ofp-cfb-select-item-check');
                    checkSvg.setAttribute('viewBox', '0 0 24 24');
                    checkSvg.setAttribute('fill', 'none');
                    checkSvg.setAttribute('stroke', 'currentColor');
                    var path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
                    path.setAttribute('stroke-linecap', 'round');
                    path.setAttribute('stroke-linejoin', 'round');
                    path.setAttribute('stroke-width', '2.5');
                    path.setAttribute('d', 'M5 13l4 4L19 7');
                    checkSvg.appendChild(path);
                    item.appendChild(checkSvg);

                    item.addEventListener('click', function(e) {
                        e.stopPropagation();
                        select.value = opt.value;
                        valSpan.textContent = opt.text;
                        menu.querySelectorAll('.ofp-cfb-select-item').forEach(function(el) {
                            el.classList.remove('selected');
                            el.setAttribute('aria-selected', 'false');
                        });
                        item.classList.add('selected');
                        item.setAttribute('aria-selected', 'true');
                        wrap.classList.remove('open');
                        trigger.setAttribute('aria-expanded', 'false');
                        select.dispatchEvent(new Event('change', { bubbles: true }));
                    });

                    menu.appendChild(item);
                });

                var currentOpt = select.options[select.selectedIndex];
                if ( currentOpt ) {
                    valSpan.textContent = currentOpt.text;
                }

                trigger.addEventListener('click', function(e) {
                    e.stopPropagation();
                    var isOpen = wrap.classList.contains('open');
                    modal.querySelectorAll('.ofp-cfb-custom-select-wrap.open').forEach(function(otherWrap) {
                        if ( otherWrap !== wrap ) {
                            otherWrap.classList.remove('open');
                            var otherTrg = otherWrap.querySelector('.ofp-cfb-select-trigger');
                            if ( otherTrg ) otherTrg.setAttribute('aria-expanded', 'false');
                        }
                    });
                    if ( isOpen ) {
                        wrap.classList.remove('open');
                        trigger.setAttribute('aria-expanded', 'false');
                    } else {
                        wrap.classList.add('open');
                        trigger.setAttribute('aria-expanded', 'true');
                    }
                });

                select.addEventListener('change', function() {
                    var selectedOpt = select.options[select.selectedIndex];
                    if ( selectedOpt ) {
                        valSpan.textContent = selectedOpt.text;
                        menu.querySelectorAll('.ofp-cfb-select-item').forEach(function(el) {
                            var isSelected = el.dataset.value === selectedOpt.value;
                            el.classList.toggle('selected', isSelected);
                            el.setAttribute('aria-selected', isSelected ? 'true' : 'false');
                        });
                    }
                });
            });
        }

        initCfbCustomSelects();

        // Close custom select dropdowns on outside click
        document.addEventListener('click', function(e) {
            if ( ! e.target.closest('.ofp-cfb-custom-select-wrap') ) {
                modal.querySelectorAll('.ofp-cfb-custom-select-wrap.open').forEach(function(wrap) {
                    wrap.classList.remove('open');
                    var trg = wrap.querySelector('.ofp-cfb-select-trigger');
                    if ( trg ) trg.setAttribute('aria-expanded', 'false');
                });
            }
        });

        // Sync dropdown labels when form is reset
        form.addEventListener('reset', function() {
            setTimeout(function() {
                var wraps = modal.querySelectorAll('.ofp-cfb-custom-select-wrap');
                wraps.forEach(function(wrap) {
                    var select = wrap.querySelector('.ofp-cfb-native-select');
                    var valSpan = wrap.querySelector('.ofp-cfb-select-value');
                    var menu    = wrap.querySelector('.ofp-cfb-select-menu');
                    if ( select && valSpan ) {
                        var opt = select.options[select.selectedIndex];
                        if ( opt ) {
                            valSpan.textContent = opt.text;
                            if ( menu ) {
                                menu.querySelectorAll('.ofp-cfb-select-item').forEach(function(el) {
                                    var isSel = el.dataset.value === opt.value;
                                    el.classList.toggle('selected', isSel);
                                    el.setAttribute('aria-selected', isSel ? 'true' : 'false');
                                });
                            }
                        }
                    }
                });
            }, 20);
        });

        // Auto-capture device diagnostics
        function getDeviceInfo() {
            return JSON.stringify({
                userAgent: navigator.userAgent,
                screenWidth: window.innerWidth,
                screenHeight: window.innerHeight,
                language: navigator.language,
                platform: navigator.platform || 'Unknown'
            });
        }

        function openModal(defaultType) {
            setType(defaultType || 'feature_request');
            form.style.display = 'block';
            successScr.style.display = 'none';
            errorAlert.style.display = 'none';
            pageUrlInput.value = window.location.href;
            deviceInput.value  = getDeviceInfo();
            modal.style.display = 'flex';
            modal.setAttribute('aria-hidden', 'false');
            setTimeout(function() { titleInput.focus(); }, 100);
        }

        function closeModal() {
            modal.style.display = 'none';
            modal.setAttribute('aria-hidden', 'true');
        }

        function setType(type) {
            typeInput.value = type;
            errorAlert.style.display = 'none';

            if ( type === 'bug_report' ) {
                tabBug.classList.add('active');
                tabFeature.classList.remove('active');
                modalTitle.textContent = 'Report a Bug or Issue';
                titleLabel.innerHTML = 'What went wrong? <span class="req">*</span>';
                titleInput.placeholder = 'e.g. Save button did not respond on property page';
                descLabel.innerHTML = 'Steps to Reproduce & Expected Behavior <span class="req">*</span>';
                descInput.placeholder = 'What were you doing when the bug occurred? What did you expect to happen versus what actually happened?';
                videoHint.textContent = 'Optional: Have a video (YouTube / Loom) or screenshot showing the glitch? Paste the link here.';
                prioLabel.textContent = 'Issue Severity';
                diagBox.style.display = 'flex';
                btnText.textContent = 'Submit Bug Report';
            } else {
                tabFeature.classList.add('active');
                tabBug.classList.remove('active');
                modalTitle.textContent = 'Request a Feature';
                titleLabel.innerHTML = 'Feature Title <span class="req">*</span>';
                titleInput.placeholder = 'e.g. Bulk WhatsApp rent reminder broadcast';
                descLabel.innerHTML = 'Detailed Explanation <span class="req">*</span>';
                descInput.placeholder = 'Please describe what you want to achieve, how it should work, and why it is valuable to your business...';
                videoHint.textContent = 'Optional: Have a YouTube or Loom video explaining your idea? Paste the link here so we can watch it!';
                prioLabel.textContent = 'Business Impact / Priority';
                diagBox.style.display = 'none';
                btnText.textContent = 'Submit Feature Request';
            }
        }

        // Attach Click Handlers to all triggers across the app
        document.querySelectorAll('.ofp-open-feedback-trigger').forEach(function(el) {
            el.addEventListener('click', function(e) {
                e.preventDefault();
                var reqType = el.getAttribute('data-type') || 'feature_request';
                openModal(reqType);
            });
        });

        // Tab Switching
        tabFeature.addEventListener('click', function() { setType('feature_request'); });
        tabBug.addEventListener('click', function()     { setType('bug_report'); });

        // Close triggers
        if ( closeBtn )  closeBtn.addEventListener('click', closeModal);
        if ( cancelBtn ) cancelBtn.addEventListener('click', closeModal);
        if ( doneBtn )   doneBtn.addEventListener('click', closeModal);

        modal.addEventListener('click', function(e) {
            if ( e.target === modal ) closeModal();
        });

        document.addEventListener('keydown', function(e) {
            if ( e.key === 'Escape' && modal.style.display === 'flex' ) {
                closeModal();
            }
        });

        // Live Video URL detection
        videoInput.addEventListener('input', function() {
            var val = videoInput.value.trim();
            if ( ! val ) {
                videoPill.style.display = 'none';
                return;
            }

            if ( val.indexOf('youtube.com') !== -1 || val.indexOf('youtu.be') !== -1 ) {
                videoPill.style.display = 'inline-flex';
                videoPillText.textContent = '✓ YouTube video recognized';
            } else if ( val.indexOf('loom.com') !== -1 ) {
                videoPill.style.display = 'inline-flex';
                videoPillText.textContent = '✓ Loom video recognized';
            } else if ( val.indexOf('vimeo.com') !== -1 ) {
                videoPill.style.display = 'inline-flex';
                videoPillText.textContent = '✓ Vimeo video recognized';
            } else if ( val.startsWith('http://') || val.startsWith('https://') ) {
                videoPill.style.display = 'inline-flex';
                videoPillText.textContent = '✓ Link detected';
            } else {
                videoPill.style.display = 'none';
            }
        });

        // Form Submit Handler
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            errorAlert.style.display = 'none';

            var title = titleInput.value.trim();
            var desc  = descInput.value.trim();

            if ( ! title || ! desc ) {
                errorAlert.textContent = 'Please fill in both the title and description.';
                errorAlert.style.display = 'block';
                return;
            }

            submitBtn.disabled = true;
            btnText.style.display = 'none';
            btnSpinner.style.display = 'inline-block';

            var fd = new FormData(form);
            fd.append('action', 'ofp_submit_feedback');
            fd.append('nonce', typeof ofpClientData !== 'undefined' ? ofpClientData.nonce : '');

            var ajaxUrl = typeof ofpClientData !== 'undefined' ? ofpClientData.ajaxurl : '/wp-admin/admin-ajax.php';

            fetch(ajaxUrl, {
                method: 'POST',
                body: fd
            })
            .then(function(res) { return res.json(); })
            .then(function(data) {
                submitBtn.disabled = false;
                btnText.style.display = 'inline';
                btnSpinner.style.display = 'none';

                if ( data.success ) {
                    form.reset();
                    videoPill.style.display = 'none';
                    form.style.display = 'none';
                    successScr.style.display = 'block';
                    
                    var successTitle = document.getElementById('ofp-cfb-success-title');
                    var successMsg   = document.getElementById('ofp-cfb-success-message');
                    if ( typeInput.value === 'bug_report' ) {
                        successTitle.textContent = 'Bug Report Logged!';
                        successMsg.textContent = 'Thank you for helping us catch this! Our engineering team will investigate and address it.';
                    } else {
                        successTitle.textContent = 'Feature Request Received!';
                        successMsg.textContent = 'Thank you for your suggestion! We review all client requests when planning upcoming releases.';
                    }
                } else {
                    errorAlert.textContent = (data.data && data.data.message) ? data.data.message : 'Unable to submit feedback. Please try again.';
                    errorAlert.style.display = 'block';
                }
            })
            .catch(function(err) {
                submitBtn.disabled = false;
                btnText.style.display = 'inline';
                btnSpinner.style.display = 'none';
                errorAlert.textContent = 'A connection error occurred. Please try again.';
                errorAlert.style.display = 'block';
            });
        });
    });
})();
</script>

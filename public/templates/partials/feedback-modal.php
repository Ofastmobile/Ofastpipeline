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
<link rel="stylesheet" href="<?php echo esc_url( OFP_URL . 'assets/css/feedback-modal.css?v=' . OFP_VERSION ); ?>">
<script src="<?php echo esc_url( OFP_URL . 'assets/js/feedback-modal.js?v=' . OFP_VERSION ); ?>"></script>

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

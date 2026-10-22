function handleStatusSubmit(e, inquiryId) {
        // Modern async update with fallback to standard form submit
        if (!window.fetch) return true;
        e.preventDefault();

        const form = e.target;
        const select = document.getElementById('status-select-' + inquiryId);
        const newStatus = select.value;
        const pill = document.getElementById('status-pill-' + inquiryId);
        const nonce = form.querySelector('[name="ofp_inspection_nonce"]').value;
        const button = form.querySelector('button[type="submit"]');

        const originalBtnText = button.textContent;
        button.textContent = 'Saving...';
        button.disabled = true;

        const bodyData = new URLSearchParams();
        bodyData.append('action', 'ofp_update_inspection_status');
        bodyData.append('nonce', nonce);
        bodyData.append('inquiry_id', inquiryId);
        bodyData.append('status', newStatus);

        fetch((window.ofpClientData && window.ofpClientData.ajaxurl) ? window.ofpClientData.ajaxurl : '/wp-admin/admin-ajax.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: bodyData.toString()
        })
        .then(res => res.json())
        .then(data => {
            button.textContent = originalBtnText;
            button.disabled = false;
            if (data.success) {
                // Update pill style and text instantly
                const statusLabels = {
                    'pending': 'Pending Confirmation',
                    'confirmed': 'Confirmed',
                    'completed': 'Completed',
                    'cancelled': 'Cancelled'
                };
                pill.className = 'ofp-status-pill ofp-status-' + newStatus;
                pill.textContent = statusLabels[newStatus] || newStatus;
                
                // Show subtle feedback
                button.textContent = '✓ Saved';
                setTimeout(() => { button.textContent = originalBtnText; }, 2000);
            } else {
                alert(data.data ? data.data.message : 'Failed to update status.');
            }
        })
        .catch(err => {
            button.textContent = originalBtnText;
            button.disabled = false;
            // Fallback to normal form submit on error
            form.submit();
        });

        return false;
    }

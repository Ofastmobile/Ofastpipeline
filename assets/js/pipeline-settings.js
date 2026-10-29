// Live character counter for instant SMS.
var smsField = document.querySelector('[name="instant_sms_message"]');
var counter  = document.getElementById('ofp-sms-count-0');
if (smsField && counter) {
    function updateCount() { counter.textContent = smsField.value.length; }
    smsField.addEventListener('input', updateCount);
    updateCount();
}

// Timeline Scroll Progress
var timeline = document.getElementById('ofpPipelineTimeline');
var progressLine = document.getElementById('ofpTimelineProgress');

var isTimelineScheduled = false;
if (timeline && progressLine) {
    function updateTimelineProgress() {
        isTimelineScheduled = false;
        if (!timeline || timeline.offsetParent === null) return;
        var rect = timeline.getBoundingClientRect();
        var triggerPoint = window.innerHeight * 0.6; // Trigger when 60% down the screen
        
        var scrolled = triggerPoint - rect.top;
        var progress = 0;
        
        if (scrolled > 0) {
            progress = scrolled / rect.height;
            
            // Force 100% if we are at the absolute bottom of the page
            var scrollPos = window.scrollY || document.documentElement.scrollTop;
            var maxScroll = document.documentElement.scrollHeight - window.innerHeight;
            if (scrollPos >= maxScroll - 5) {
                progress = 1;
            }
            
            if (progress > 1) progress = 1;
        }
        
        progressLine.style.transform = 'scaleY(' + progress + ')';
        
        var steps = timeline.querySelectorAll('.ofp-timeline-step');
        steps.forEach(function(step) {
            var stepRect = step.getBoundingClientRect();
            // Activate badge if it crosses the trigger point
            if (stepRect.top + 20 < triggerPoint) {
                step.classList.add('active');
            } else {
                step.classList.remove('active');
            }
        });
    }
    
    function onTimelineScroll() {
        if (!isTimelineScheduled) {
            isTimelineScheduled = true;
            requestAnimationFrame(updateTimelineProgress);
        }
    }
    window.addEventListener('scroll', onTimelineScroll, { passive: true });
    // Initial check on load
    updateTimelineProgress();
}

// Live Preview Sync
var previewData = {
    'instant_sms_message': 'preview_instant_sms_message',
    'followup_1_message': 'preview_followup_1_message',
    'followup_2_message': 'preview_followup_2_message',
    'followup_3_message': 'preview_followup_3_message',
    'call_interest_message': 'preview_call_interest_message',
    'call_installment_message': 'preview_call_installment_message',
    'call_rent_message': 'preview_call_rent_message'
};

var timelineEl = document.getElementById("ofpPipelineTimeline"); var businessName = (timelineEl && timelineEl.getAttribute("data-business-name")) ? timelineEl.getAttribute("data-business-name") : "My Business";

function formatPreviewText(text) {
    if (!text) return '...';
    // Replace placeholders with dummy data
    var formatted = text.replace(/\{\{name\}\}/gi, 'John')
                        .replace(/\{\{phone\}\}/gi, '07123456789')
                        .replace(/\{\{email\}\}/gi, 'john@example.com')
                        .replace(/\{\{business_name\}\}/gi, businessName)
                        .replace(/\{\{property\}\}/gi, 'Sunset Villa')
                        .replace(/\{\{amount\}\}/gi, '250,000')
                        .replace(/\{\{due_date\}\}/gi, 'today');
    
    // Convert newlines to <br> for HTML display
    return formatted.replace(/\n/g, '<br>');
}

Object.keys(previewData).forEach(function(inputName) {
    var input = document.querySelector('[name="' + inputName + '"]');
    var preview = document.getElementById(previewData[inputName]);
    var phoneBody = document.getElementById('ofpPhoneBody');
    
    if (input && preview) {
        // Sync on input
        input.addEventListener('input', function() {
            var rawText = this.value;
            var isVoice = inputName.indexOf('call_') === 0;
            
            if (isVoice) {
                var title = 'Voice Call';
                if (inputName === 'call_interest_message') title = 'Instant Call: New Lead';
                if (inputName === 'call_installment_message') title = 'Reminder Call: Installment Due';
                if (inputName === 'call_rent_message') title = 'Reminder Call: Rent Due';
                preview.innerHTML = '<strong style="color:#be185d !important;">' + title + '</strong><p style="margin:0; color:#0f172a !important;">' + formatPreviewText(rawText) + '</p>';
            } else {
                preview.innerHTML = formatPreviewText(rawText);
            }
        });
        
        // Highlight bubble on focus
        input.addEventListener('focus', function() {
            document.querySelectorAll('.ofp-chat-bubble').forEach(function(b) {
                b.classList.remove('active');
            });
            preview.classList.add('active');
            // Scroll preview to view
            preview.scrollIntoView({ behavior: 'smooth', block: 'center' });
        });
        
        // Remove highlight on blur
        input.addEventListener('blur', function() {
            preview.classList.remove('active');
        });
        
        // Trigger initial formatting
        input.dispatchEvent(new Event('input'));
    }
});

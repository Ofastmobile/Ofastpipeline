# OFast Pipeline — Landing Page Integration Guide

For any client landing page (WordPress + Elementor, on its own subdomain
or domain, no plugin installed there). This form posts straight to the
OFast Pipeline REST API on your main site.

## 1. Find the client's own values

The client can see their own values at **/api-settings** once logged in:
- **Client ID** — required, identifies which client a lead belongs to.
- **Lead Capture Endpoint** — the full URL to post to.

Or as the admin, pull the Client ID from wp-admin → OFast Pipeline → Clients → (client) → Client ID shown in the detail view.

## 2. Copy-paste snippet (Elementor "Custom HTML" widget)

Replace `CLIENT_ID_HERE` and `YOUR_TURNSTILE_SITE_KEY` below. The site key
is in wp-admin → OFast Pipeline → Settings → Cloudflare Turnstile.

```html
<form id="ofp-lead-form">
    <input type="text" name="name" placeholder="Your Name" required>
    <input type="tel" name="phone" placeholder="Phone Number" required>
    <input type="email" name="email" placeholder="Email (optional)">
    <input type="hidden" name="client_id" value="CLIENT_ID_HERE">

    <!-- Honeypot — must stay hidden and empty. Do not remove. -->
    <input type="text" name="website" style="display:none !important" tabindex="-1" autocomplete="off">

    <div class="cf-turnstile" data-sitekey="YOUR_TURNSTILE_SITE_KEY"></div>

    <button type="submit">Get Started</button>
</form>

<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
<script>
document.getElementById('ofp-lead-form').addEventListener('submit', async function (e) {
    e.preventDefault();
    const formData = new FormData(this);
    const submitBtn = this.querySelector('button[type="submit"]');
    submitBtn.disabled = true;

    try {
        const res = await fetch('https://YOUR-MAIN-DOMAIN.com/wp-json/ofp/v1/capture-lead', {
            method: 'POST',
            body: formData
        });
        const data = await res.json();
        if (data.success) {
            this.innerHTML = '<p>' + data.message + '</p>';
        } else {
            submitBtn.disabled = false;
            alert(data.message || 'Something went wrong. Please try again.');
        }
    } catch (err) {
        submitBtn.disabled = false;
        alert('Network error. Please try again.');
    }
});
</script>
```

Replace `https://YOUR-MAIN-DOMAIN.com` with your actual OFast Pipeline install
URL (the one running the plugin, not the client's own domain).

## 3. Property listing pages (only if the client is a listing subscriber)

The single-property template already includes the inquiry form with
`property_id` pre-filled — no manual setup needed. This snippet is only
for **standalone landing pages**, not property listing pages.

## 4. Testing checklist before going live

- [ ] Submit the form with a real phone number — confirm a lead appears in wp-admin → Leads.
- [ ] Confirm the instant SMS arrives within a few minutes (cron runs every 5 min).
- [ ] Try submitting the honeypot field via browser dev tools — confirm it's silently rejected.
- [ ] Submit 4 times quickly from the same IP — confirm the 4th is rate-limited.
- [ ] Confirm Turnstile actually blocks a scripted/automated submission once the secret key is set in Settings.

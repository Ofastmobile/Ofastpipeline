# Progressive Web App (PWA) Architecture & Implementation Guide

## 1. Overview & Project Suitability

The **Ofast Pipeline** client portal and tenant management suite are designed with an isolated application shell architecture (`.ofp-shell`), custom navigational flows, dark/light theme switching, and responsive mobile layouts (such as collapsible filter drawers, bottom sheets, and horizontal swipe tab bars).

These architectural characteristics make the platform an ideal candidate for conversion into a **Progressive Web App (PWA)** without altering core database schemas or WordPress routing.

---

## 2. Core Benefits of Converting to PWA

| Feature | Browser Experience | PWA Experience |
|---|---|---|
| **Launch Mode** | Browser tabs with URL and search bars | Standalone window (100% native mobile app feel) |
| **Home Screen Presence** | Bookmarks hidden in browser menus | Branded app icon and custom splash screen |
| **Loading Speed** | Fetches all static assets over network | Shell assets served instantly with background refresh |
| **Connectivity Drop** | Generic browser "No Internet" dino screen | Custom branded offline state informing user |
| **Notifications** | Limited to standard web alerts | Future-ready for Web Push Notifications |

---

## 3. How Continuous Updates & Maintenance are Protected

A critical concern when developing an evolving application is avoiding the **"Stale Cache" trap**, where user devices continue to run outdated JavaScript or template files after an update has been deployed.

To ensure continuous development and bug fixes are delivered seamlessly to users, the PWA must adhere to a **Network-First** architectural strategy.

```
                     User Request (Page Navigation)
                                  │
                                  ▼
                        Service Worker (sw.js)
                                  │
                                  ▼
                         Try Network First
                           /             \
                  Network OK?          Offline?
                     /                      \
             Live Server Page         Offline Fallback Screen
             (Always Up-to-Date)      (Friendly Retry Prompt)
```

### Protection Guarantees:
1. **Network-First for HTML/PHP Pages:**
   * Navigation requests **always query the live server first**.
   * Any new column added to a table, updated PHP template, or revised query displays immediately on the next page refresh.
2. **Versioned Static Assets (Cache-Busting):**
   * CSS and JS assets are enqueued with `OFP_VERSION` query strings (`client-portal.js?ver=1.4.2`).
   * When plugin files update, asset URLs change, automatically bypassing any stale cache.
3. **Immediate Service Worker Activation:**
   * Using `self.skipWaiting()` and `clients.claim()` ensures that when a revised service worker script is detected, it takes over immediately without waiting for users to close background tabs.

---

## 4. Technical Architecture

### Component 1: Web App Manifest (`manifest.json`)
The manifest provides the browser with metadata on how the app presents itself when installed:

```json
{
  "name": "Ofast Pipeline Portal",
  "short_name": "Ofast",
  "description": "Real Estate Sales, Lease & Installments Management",
  "start_url": "/dashboard",
  "scope": "/",
  "display": "standalone",
  "orientation": "portrait-primary",
  "background_color": "#0f172a",
  "theme_color": "#5b35d5",
  "icons": [
    {
      "src": "/wp-content/plugins/ofast-pipeline/assets/images/icons/icon-192.png",
      "sizes": "192x192",
      "type": "image/png",
      "purpose": "any maskable"
    },
    {
      "src": "/wp-content/plugins/ofast-pipeline/assets/images/icons/icon-512.png",
      "sizes": "512x512",
      "type": "image/png",
      "purpose": "any maskable"
    }
  ]
}
```

### Component 2: Service Worker (`sw.js`)
The service worker manages offline resilience without interfering with dynamic database data:

* **HTML Pages:** Network-First strategy with fallback to branded offline page.
* **Static Assets (CSS, Icons, Fonts):** Cache-First with revalidation in background (Stale-While-Revalidate).
* **API & Form Submissions (`admin-ajax.php`, `wp-json`):** Network-Only (never cached to prevent duplicate transaction submissions).

### Component 3: Portal Header Meta Tags
Added to [`public/templates/partials/nav.php`](file:///c:/Users/bodma/Local%20Sites/ofast-pipeline/app/public/wp-content/plugins/ofast-pipeline/public/templates/partials/nav.php) or main shell:

```html
<!-- PWA Manifest & Mobile Capability -->
<link rel="manifest" href="/wp-content/plugins/ofast-pipeline/manifest.json">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="Ofast">
<link rel="apple-touch-icon" href="/wp-content/plugins/ofast-pipeline/assets/images/icons/icon-192.png">
<meta name="theme-color" content="#5b35d5">
```

### Component 4: In-App Install Prompt (Optional UX Enhancement)
Listen to the browser's `beforeinstallprompt` event to display a native "Install App" button in the client portal navigation or settings page:

```javascript
let deferredPrompt;
window.addEventListener('beforeinstallprompt', (e) => {
    e.preventDefault();
    deferredPrompt = e;
    const installBtn = document.getElementById('ofp-pwa-install-btn');
    if (installBtn) {
        installBtn.style.display = 'inline-flex';
        installBtn.addEventListener('click', async () => {
            installBtn.style.display = 'none';
            deferredPrompt.prompt();
            const { outcome } = await deferredPrompt.userChoice;
            deferredPrompt = null;
        });
    }
});
```

---

## 5. Rollout Checklist

When ready to implement:
- [ ] Export 192x192 and 512x512 PNG app icons with rounded/maskable boundaries.
- [ ] Create `manifest.json` under plugin root or register dynamic REST endpoint.
- [ ] Implement `sw.js` with Network-First strategy for application routes.
- [ ] Include PWA meta tags in portal document headers.
- [ ] Verify installability using Chrome DevTools Lighthouse audit (PWA category).
- [ ] Test real mobile installation on iOS Safari ("Add to Home Screen") and Android Chrome ("Install App").

# OFast Pipeline audit (v2.2.28) - payment gateway internals skipped

Format: one line per finding. Severity is a first guess in Stage 1; Stage 2 will confirm.

## STAGE 1 - MAP

### Plugin facts
- Main file `ofast-pipeline.php`. Header says 2.2.27, constant OFP_VERSION says 2.2.28. Needs PHP 8.1+, WP 6.0+.
- About 85k lines, 78 classes. Folders: `includes/` (core, 60+ classes), `admin/` (7), `public/` (5 + 40 templates), `cron/` (1), `assets/`.
- No `add_shortcode` anywhere. The plugin is route-based (rewrite rules + template_redirect), not shortcode-based.
- Images load from CDN `dl.ofastshop.com/mypropertyhq/images/` (constant OFP_CDN_URL).

### Load order (plain `require_once`, no autoloader)
1. Activator, Deactivator, Security, Admin_Url, Admin_Tweaks, Login_Redesign, Auth
2. Mailer, Client, Team_Member, Lead, Queue, SMS, Voice, Voice_Notice, Contacts, Import, Credit, Subscription, CSV
3. Property CPT, Host_Router, Notification, Logger, Communications_Log
4. Property commerce group (commerce, migration, admin_rules, payment_record, contact, purchase_service, comms_bridge, manual_payment, payment_records, checkout, commerce_repair, rent, rent_visibility)
5. White_Label, Platform, Package, Field_Agent, Brand, Bank_Account, Prembly
6. Lease_payment, tenant_transfer, my_payments, transfer_reminders, tenant_portal, receipt_generator, receipt_viewer, inquiry, land, amenities, watermark
7. Feedback, Chat_AI, Chat_Telegram, Chat_Service
8. Payment + Paystack gateway (OUT OF SCOPE)
9. admin/: admin_menu, admin_settings, commerce_admin, commerce_actions, purchase_admin, admin_portal
10. public/: rest_api, client_portal, property_sales, sales_client_ui, marketplace
11. cron/: cron_handler

### Entry points (counts)
- AJAX: 42 registrations. 10 are logged-out (nopriv).
- admin_post: 40 registrations. 3 are logged-out (nopriv).
- REST: 8 routes. All have `permission_callback => __return_true`.
- Cron events: ofp_process_queue, ofp_daily_subscription_check, ofp_daily_credit_check, ofp_monthly_archive, payment reconcile (hourly), ofp_chat_tg_pull (2 min), transfer reminders (hourly), plus single events for voice calls and reminders.
- CPT: `ofp_property` + taxonomies `ofp_property_type`, `ofp_property_location`.
- Public routes (rewrite rules): `admin-portal[/tab]`, `agent/{slug}`, portal routes from `$routes` list, `property-checkout`, `property-receipt`, `property-pay`, `my-payments`, `tenant-lease/{id}`, marketplace and sales routes.
- Host router (`class-ofp-host-router.php`) handles custom client domains/sub-domains on `template_redirect`.

### REST routes
| Route | Public? | Note |
|---|---|---|
| ofp/v1/capture-lead | yes | lead intake; needs API key check (Stage 2) |
| ofp/v1/webhook/payment | yes | payment webhook; signature check is gateway scope, but check caller code |
| ofp/v1/webhook/voice-ivr | yes | needs signature/secret check |
| ofp/v1/webhook/sms-dlr | yes | needs signature/secret check |
| ofp/v1/properties | yes | public listing; check what fields leak |
| ofast/v1/chat/send | yes | AI chat; cost risk, rate limit needed |
| ofast/v1/chat/history | yes | check who can read which chat |
| ofast/v1/chat/telegram-webhook | yes | needs Telegram secret token check |

### Logged-out AJAX (nopriv) - first look
| Action | Nonce | Other guard seen | Stage 1 note |
|---|---|---|---|
| ofp_get_bank_list | no | none | public bank list, low risk |
| ofp_resolve_bank_account | yes | rate_limit | public name lookup, cost risk |
| ofp_submit_client_verification | no | cookie session | Prembly; credit cost; custom cookie fallback |
| ofp_view_receipt | no | token? | receipts by ID/token; IDOR check |
| ofp_my_receipt | yes | rate_limit | check owner match |
| ofp_lease_proof | yes | manage_options | nopriv looks pointless for an admin check |
| ofp_submit_feedback | yes | manage_options seen in file | check which path is public |
| ofp_add_custom_amenity | yes | edit_posts + rate_limit | nopriv looks pointless |
| ofp_fetch_leads | yes | require_client_login | nopriv not needed if login is required |
| ofp_fetch_communications | yes | require_client_login | nopriv not needed |

### Guard helpers (to verify in Stage 2)
- Admin posts use `require_admin_post()` / `require_super_admin_post()` (admin-menu.php:1448+) and `admin_guard()` (chat-service). My quick scan showed "no nonce" for these only because the check is inside the helper. Helpers must be read in Stage 2.
- Client portal AJAX uses nonce `ofp_client_action` + `OFP_Auth::require_client_login()` / `is_logged_in()`.
- Custom login system (`OFP_Auth`, cookie `ofp_client_session`), not WordPress users, for clients.

## STAGE 1 - PRELIMINARY FINDINGS (all "needs confirmation" until Stage 2/4)
- S1-01 | Low | version | ofast-pipeline.php:6 vs :17 | header 2.2.27, constant 2.2.28. Fix: one source of truth.
- S1-02 | Medium | cron | activator.php:670 vs cron-handler.php:39 | `ofp_process_queue` scheduled at 5 min by activator and 2 min by cron handler; whichever runs first wins; confirm no double run.
- S1-03 | Medium | cron | ofast-pipeline.php:150 | "monthly" = 30 days, so the "1st of month 02:00" archive drifts after the first run.
- S1-04 | High? | rest | rest-api.php:93-122, chat-service.php:450-463 | all 8 routes open (`__return_true`); each must check key/signature/rate limit inside the callback. Stage 2.
- S1-05 | Medium | ajax | client-portal.php:79,108 | nopriv registered for handlers that need login; remove nopriv or confirm intended.
- S1-06 | Medium | ajax | tenant-transfer.php:27, feedback.php:26, amenities.php:24 | nopriv registered on actions that check manage_options/edit_posts; dead or wrong registration.
- S1-07 | High? | ajax | receipt-viewer.php:24 | public receipt view with no nonce; confirm token strength (IDOR).
- S1-08 | High? | ajax | prembly.php:22-23,267 | public Prembly verify with no nonce, cookie-token fallback reads `$_COOKIE` directly; credit drain risk.
- S1-09 | Medium | ajax | bank-account.php:27 | `ofp_get_bank_list` no nonce, no rate limit.
- S1-10 | Medium | files | plugin root | `Ofast_Pipeline_Universal_Receipt.html` loose in root; check if used and if directly reachable.
- S1-11 | Medium | rewrite | property-sales.php:40, property-cpt.php:122 | `flush_rewrite_rules` called in code paths; confirm not on every request.
- S1-12 | Info | structure | whole plugin | no autoloader; 70+ `require_once`; 6 giant files over 1,500 lines (settings.php 4,541; property-cpt 2,677; property-single-detail 2,546; admin-menu 2,102).
- S1-13 | Info | duplicate | receipt-generator vs receipt-viewer vs root HTML; payment-record vs payment-records; property-single vs property-single-detail; pricing vs plans-pricing | likely overlap; Stage 5.
- S1-14 | Info | plugin | shortcodes | none registered; remove any docs that mention shortcodes.

## DECISIONS LOG (from owner)
- D-01 | 2026-11-08 | Plan: drop white-label in a new "white-label edition" and sell the code once. Buyers use their own SMTP, SMS and voice accounts. Owner keeps no buyer API keys.
- D-02 | White-label edition keeps ONLY marketplace + single property page. Strip all other routes/front pages. Remove Prembly. Field agents stay out (owner's own).
- D-03 | UPDATED 2026-11-08: no clients yet on this plugin, so NO migration needed. Buyers are single normal real estate companies (not marketplaces). Remove now: white-label, subscriptions, credits, front pages (keep dashboard, marketplace, single property), AI chatbot (replace with WhatsApp button), Prembly. wp-admin keeps only the settings page.
- D-04 | UPDATED: strip FIRST, audit the smaller code AFTER (reverses the earlier "audit first" order, because no point auditing code that will be deleted). Keep a frozen copy of v2.2.28 as backup. Then run Stage 2 onward on the stripped code.
- D-05 | Planned strip list (to be confirmed by a removal map before any deletion): white-label class + host-router domain logic, subscription + credit classes, plans/pricing/funding/credits/billing pages and admin views, admin-portal (super-admin), Prembly, chat-ai + telegram + chat-service (removes 3 open REST routes + 1 cron), field agent, SaaS-only admin_post handlers (clients, admins, topup, plan matrix).
- D-06 | Assumed KEEP unless owner says otherwise: property sales/rent/installment/receipts/tenant features, manual payment, bank account, SMTP/SMS (buyer's own keys), team members, leads, import/CSV, feedback, property payments through the buyer's own gateway keys.
- D-07 | 2026-11-08 | Owner confirmed (overrides D-05/D-06 where they differ): NO payment gateway in the white-label edition (gateways folder, class-ofp-payment, Paystack, payment webhook, reconcile cron all go). Property payments = manual only (bank transfer, proof, admin verify, receipts). No funding, no credits, no billing, no pricing, no plans.
- D-08 | IVR removed completely (class-ofp-ivr, voice-ivr webhook). Outbound voice call stays as an OPTIONAL feature, OFF by default, switched on in Pipeline settings, buyer's own keys.
- D-09 | Staff move to normal WordPress users with custom roles (less custom login code). Staff use the front dashboard, wp-admin blocked for them except Settings page for the company admin. Buyers/tenants/customers stay as records and use signed links, not WP accounts.
- D-10 | New work order: (1) freeze backup of v2.2.28, (2) removal map, (3) strip, (4) move staff login to WP users, (5) Stage 2+ audit on the smaller code.
- D-11 | 2026-11-08 | Owner: leave login/signup alone for now, strip the rest first, plan the WP-users move later when code is stable. Keep OFP_Auth untouched during the strip. Stage 2 audits the current login as it is, and the login move gets its own short re-check afterwards. When the move happens: keep login/reset/OTP designs and OTP logic, remove custom sessions/cookie/password columns/reset-token code.
- D-12 | 2026-11-08 | CORRECTION from owner: OFP_Platform is the owner's OWN platform for field agents. It STAYS in the main code. White-label clients and subscribers have no access to it. Field agent module stays too (not complete yet). So the map's "Platform = single-company shortcut" idea is dropped for the main code, and map items A4 (field agent) and the Platform notes in C-group are CANCELLED for main. Strip-in-place (D-04) is replaced by TWO codebases: MAIN (owner's platform, keeps Platform, field agent, and whatever subscriber features owner confirms) and WHITE-LABEL edition (fork, fully stripped).
- D-13 | New order: (1) remove white-label from MAIN now, (2) audit and fix MAIN (Stages 2-7) once, (3) fork WHITE-LABEL edition from the fixed main, strip it (Groups A-D of the map, minus anything owner keeps), (4) short re-check of the white-label edition only. Reason: main keeps most of the code, so audit it once and let the fork inherit the fixes.
- D-14 | Admin portal (class-ofp-admin-portal + template): owner says it goes with white-label. Waiting to confirm if it also leaves MAIN or only the white-label edition.
- Audit tagging from now on: mark each finding/file as [MAIN-ONLY] (white-label, Prembly, field agent, billing/credits), [BOTH], or [WL-STRIP]. White-label touches ~25 files and ~143 mentions (class-ofp-white-label.php, host-router, brand, mailer, subscription, activator, receipt-generator, property-cpt, my-payments, 12 templates, admin-menu).

## STAGE 0 - REMOVAL MAP (read only, nothing deleted yet)
Backup saved: /home/claude/backup/ofast-pipeline-v2.2.28-frozen.zip

### Size of the cut (estimate, lines)
- Classes: white-label 709, subscription 1551, credit 296, payment 592, paystack 155, prembly 362, chat (ai 397 + telegram 495 + service 1035), ivr 267, field-agent 1110, admin-portal 618 = 7,587
- Super-admin portal template: 1,961
- Front templates: credits 277, funding 769, pricing 177, plans-pricing 1,239, investment 343, field 273, field-team 328, blog 1,843, agents 2,071 = 7,320
- wp-admin views: billing 766, clients-list 839, investment 170, property-billing 134 = 1,909 (+ manage-admins 227 later)
- Settings page sections (pricing customizer, white-label, Paystack, Prembly, AI chat) about 1,300
- Total about 20,000 of 85,000 lines (about a quarter). Plus partial clean-up inside admin-menu (2,102), client-portal (1,400), mailer, nav, properties.php. [CONFIRM: homepage 2,457 would add 3 more percent]

### Big discovery
- OFP_Platform (class-ofp-platform.php) is ALREADY a single-company mode: no listing limit, no credits, no plans, no billing, SMS never charged, team view-only rules. White-label edition = Platform mode switched on for good. Plan: force it on first, test, then delete the code behind it.
- OFP_Property_Checkout is already only a redirect to the bank-transfer page. Buyers already pay by bank transfer. So the gateway is NOT used for property payments. Safe to remove.
- OFP_Property_Lease_Payment still has gateway and gateway_reference columns (stale).

### Group A - leaf code, easy to remove (few outside links)
- A1 | Prembly | class-ofp-prembly.php (362). Outside links: public/templates/account.php (2), ofast-pipeline.php (1), settings.php section (3621-3768), AJAX ofp_submit_client_verification (2 handlers). Easy.
- A2 | AI chat + Telegram | chat-ai, chat-telegram, chat-service (1,927). Outside links: settings.php (sections 4079-4541), ofast-pipeline.php, assets/js/ofp-chat-widget.js + css, 3 REST routes, 1 cron (ofp_chat_tg_pull), 3 admin_post test handlers. Replace with a WhatsApp link button. Easy.
- A3 | IVR | class-ofp-ivr.php (267). No outside class links. Voice webhook route stays only if outbound calls need it (decide).
- A4 | Field agent | class-ofp-field-agent.php (1,110), templates field.php, field-team.php, assets/js/field.js, css. Outside links: nav.php (2), OFP_Platform (5 mentions), ofast-pipeline.php. Easy.
- A5 | Super-admin portal | class-ofp-admin-portal.php (618) + admin/views/portal/admin-portal-template.php (1,961). Only link: ofast-pipeline.php. Rewrite rule `admin-portal` goes. Easy.

### Group B - payment gateway and money screens
- B1 | Payment + Paystack | class-ofp-payment.php (592), gateways/paystack (155). Links: funding.php (6), credits.php (2), rest-api.php (4: payment_webhook), subscription (1), cron reconcile hook, settings.php Paystack section (2307-2444). Medium.
- B2 | Pages: funding, credits, pricing, plans-pricing, investment. Routes to delete in client-portal.php lines 23-66: credits, listing-billing, funding, pricing, plans-pricing, pricing-plans, plans, plans-and-pricing, investment. Plus nav.php links. Easy.
- B3 | wp-admin: billing, funding-requests, investment, property-billing views and menu entries.
- B4 | Tables to drop (fresh installs only, no clients exist): ofp_credits, ofp_credit_transactions, ofp_subscriptions, ofp_funding_requests.

### Group C - the hard part (many outside links)
- C1 | OFP_Credit (296): about 40 call sites (has_balance / deduct / topup / get) in about 14 files: queue, comms-bridge, voice-notice, voice, lease-reminders, installment-reminders, commerce-repair, tenant-transfer, team-member, client-portal, white-label, pipeline-settings.php, funding.php, admin-menu. Plan: delete each gate so sending is always allowed, never leave a fake "credit" class behind. Test sending after each file.
- C2 | OFP_Subscription (1,551): used by properties.php (14), admin-menu (14), cpt (8), client-portal (5), client (4), auth (4), nav (4), message-templates (4), team (3), account. It does plan limits, feature gates, `has_platform_access` and free-tier setup. DANGER: auth.php and client.php call it, so removing it carelessly can lock everyone out of login. Do it AFTER forcing Platform mode on.
- C3 | OFP_White_Label (709): links in admin-menu (11), subscription (9), brand (9), clients-list.php (9), account.php (5), property-cpt (3), my-payments (2), dashboard, credits, funding, main file. Hooks: `posts_clauses` (hides white-label properties from the marketplace) and `home_url` filter (rewrites links). Columns custom_domain, is_white_label on ofp_clients.
- C4 | OFP_Host_Router (416): links in cpt (6), rest-api (4), client-portal (4), auth (4), security (3), signup/login/marketplace/single/agent templates. Does domain/app-host routing. Strip the custom-domain and unknown-host parts; keep a tiny helper only if still needed.
- C5 | OFP_Brand (481): used everywhere (blog 23, homepage 22, mailer 20, single 21 ...). KEEP. Simplify to read company name/logo/colours from settings, remove white-label branches.
- C6 | Property billing coupling: ofp_properties table is the listing billing/ownership source (memory + property-billing view, commerce-admin 5 refs, admin-rules 1 ref). Split billing fields from ownership before removing. Highest risk to property data. Needs a careful read in the strip step.

### Group D - pages and settings
- D1 | Public pages to remove (default): blog-archive 908, blog-single 935, agents 1,009, agent-profile 1,062, rewrite rule agent/{slug}, `our-agents`.
- D2 | Settings page (admin/views/settings.php, 4,541): remove sections White-Labeling (1067), Pricing Cards & Matrix (2885-3356), Paystack (2307), Prembly (3621), AI Assistant + AI engine + Telegram + Instructions (4079-4541). Keep: Brand, login customizer, social, default messages, SMTP, SMS providers (Africa's Talking, SmartSMS, BulkSMS), bank wire, Turnstile, admin URL, hardening.
- D3 | wp-admin menus (admin-menu.php:101-128): keep only Settings. Remove Overview, Clients, Team, Leads, Customers, Triggers, Communications, Billing, Reports, Funding, Send Notification, Activity Logs, Feedback, Rent Oversight, Investment, Manage Admins. About 30 admin_post handlers for clients/admins/credits/plans go with them. BUT: `ofp_admins` and the wp-admin gate stay until the login move (D-11).
- D4 | Cron to remove: ofp_daily_subscription_check, ofp_daily_credit_check, payment reconcile, ofp_chat_tg_pull. Keep: ofp_process_queue (fix the double schedule, S1-02), ofp_monthly_archive (fix 30-day drift, S1-03), reminder jobs.
- D5 | REST after strip: 8 routes become 4 (capture-lead, voice, sms-dlr, properties). Becomes 3 if outbound voice does not need the callback.

### Group E - keep untouched for now (login move later, D-11)
- OFP_Auth (771), Login_Redesign, Security, Admin_Url, Admin_Tweaks, ofp_client_sessions, ofp_admins, ofp_otps, signup/login/forgot/reset templates, ofp_clients (becomes the single company record).

### Order of work (safe sequence)
1. Leaf cuts: A1 Prembly, A2 chat, A3 IVR, A4 field agent, A5 admin portal. Lint after each.
2. Gateway: B1, then B2/B3 pages and menus.
3. Force Platform mode on (C1/C2 prep) and test login, leads, sending, property pages.
4. Delete Credit gates (C1), then Subscription (C2), then White-label + Host router (C3/C4).
5. Settings sections, menus, cron, tables, activator and uninstall (D, B4).
6. Final: php -l on every file, search for leftover class names, load test on a staging site.

### Open questions for owner (defaults in brackets)
- Q1 | property-homepage.php (2,457, your new designed homepage): remove? [default: REMOVE per "only marketplace and single"; tell me if you want to keep it]
- Q2 | investment.php (343): is it the funding feature or a buyer investment feature? [default: remove with funding]
- Q3 | api-settings.php (497, Integrations and API keys): used by capture-lead. [default: KEEP]
- Q4 | Public signup: in a single-company edition anyone could create a company account. [default: keep file per D-11, but switch the route off now]
- Q5 | Voice webhook: needed by outbound calls? [default: keep until checked]
- Q6 | Admin URL shield (1,001 lines) and hardening duplicate what security plugins do. [default: keep for now, review in Stage 5]

## WHITE-LABEL REMOVAL FROM MAIN - DONE 2026-11-08
Output: ofast-pipeline-no-whitelabel.zip (working copy). Original untouched at /home/claude/backup/ofast-pipeline-v2.2.28-frozen.zip.
Result: 95,633 -> 94,406 lines (-1,227) and 35 files changed. All PHP files pass `php -l`, all JS passes `node --check`, no undefined new class references. NOT yet tested inside a live WordPress. Test on staging first.

What was removed
- Deleted class-ofp-white-label.php (709 lines) and its require/init. This also removes: the `home_url` rewrite, the marketplace hiding of white-label listings, white-label billing (yearly/lifetime/maintenance), white-label emails and the white-label daily cron work.
- Custom-domain (Level 2) lookup in the host router and the white-label page restrictions. Client sub-domains (Gold) still work.
- White-label tier, column, FAQ and plan row in the pricing page, plan matrix editor (settings + JS) and default matrix. Saved matrices in the database keep a harmless unused `wl` value.
- White-label options in add/edit client, the `ofp_wl_billing` handler, billing view badges and filter, admin-portal stats and columns, dashboard/credits/funding/account/api-settings/properties notices, mailer "send from client name", receipt tag-line hiding, Turnstile custom-domain bypass.
- Brand: the "solo" mode. Brand now has two modes: app and gold.

What was kept on purpose
- Gold branding columns (brand_color, favicon_url, support_email, support_phone, sidebar_color, menu_hover_color) and subscription `months`. They used to be created by the white-label class, so they moved to `OFP_Activator::ensure_branding_schema()` (runs once, remembers in option `ofp_branding_schema`).
- `OFP_Platform` and its unlimited mode (`is_unlimited()` is now only true for the platform account). Admin portal, subscriptions, credits, Paystack all untouched.
- "Admin Bar & White-Labeling" settings card: this is about the WordPress admin bar, not the client feature.

Database notes (existing live DB)
- Columns custom_domain, is_white_label, wl_billing, wl_paid_at, maintenance_due are left in place, unused. Safe. Drop later if wanted. Fresh installs no longer create them.
- Old `ofp_subscriptions` rows with type `whitelabel` will show as normal rows.
- Option `ofp_white_label_schema_error` / `ofp_white_label_schema` can be deleted (still unused).

New findings from this work (for Stage 4)
- S0-01 | High? | bug | public/class-ofp-client-portal.php:1040 | `OFP_Activity_Log::log(...)` but no such class exists (the logger is `OFP_Logger`). Sending a manual SMS/email from the portal will end in a fatal error. Existed before. Confirm and fix.
- S0-02 | Low | dead | public/templates/agent-profile.php:756 | `OFP_Property_Status` does not exist (guarded by class_exists, so safe but dead).
- S0-03 | Low | dead | includes/class-ofp-subscription.php:695 | comment refers to `OFP_Monnify`, which does not exist.
- S0-04 | Info | cleanup | plans-pricing.php, credits.php, funding.php, pricing.php | variable names like `$is_solo` / `$unl_sum` now mean "platform unlimited account". Rename in Stage 5.

## DECISION D-14 RESOLVED: Admin portal STAYS in main (it is the owner's main backend). Only the white-label edition drops it.
## D-15 | 2026-11-08 | OWNER TO-DO (add to the fix list): put the feature-by-feature comparison and the White-Label tier BACK on the pricing page and in the Settings plan editor (I removed the column and 3 white-label-only rows). The new meaning: White-Label = a white-label installed on the buyer's OWN domain (their own hosting, own SMTP/SMS keys). The old wording (custom domain on our server, "send from brand name") must be rewritten for that. Old text is in the frozen backup zip (class-ofp-property-cpt.php get_default_plan_matrix, plans-pricing.php TIER 4 + matrix column + FAQ 2, settings.php matrix column, assets/js/admin-plan-matrix.js). Do this after the security fixes.
## Next: Stage 2 (security) on MAIN. Then fork the white-label edition and strip it using the Group A-D map above (white-label part is already done).

## Stage 0 status: removal map done. White-label removed from main. Stage 1 stays as written above.
## Stage 1 status: done. Next after strip: Stage 2 (security).

## Stage 2 — Security (main code, white-label already removed)
Severity: H = fix before launch, M = fix soon, L = tidy. File:line are approximate (working copy).

- S2-01 [H] credit deduct is not atomic: has_balance() then separate UPDATE (includes/class-ofp-credit.php:90-113). Two parallel sends can both pass and drive balance below zero. Fix: single `UPDATE ... WHERE {col}_remaining >= %f` and check rows_affected.
- S2-02 [M] OFP_Credit::deduct builds column name from `sanitize_key($channel)` with no whitelist (credit.php:98). Safe today only because callers pass fixed strings. Fix: allow only sms/voice/email.
- S2-03 [M] SMS delivery webhook ofp/v1/webhook/sms-dlr has no secret/signature (public/class-ofp-rest-api.php:374). Anyone can overwrite the status of any message by provider_ref. Fix: shared secret in URL/header or provider IP/signature check.
- S2-04 [M] Voice webhook ofp/v1/webhook/voice-ivr has no signature check; relies only on an unguessable `notice` token (includes/class-ofp-voice.php:~167). Add a secret param/IP check. (Will be removed in white-label edition.)
- S2-05 [M] capture-lead trusts a client_id sent by the public (rest-api.php:~175). Anyone can drop spam leads into any client's CRM; limit is only 3 per 10 min per IP (IP can rotate). Fix: derive client from the property/site key, add Turnstile or signed form token.
- S2-06 [M] Property photo upload uses media_handle_upload with no mime allow-list (public/templates/properties.php:~285). WordPress default allows pdf/doc/zip etc. Fix: restrict to jpg/png/webp via `mimes` override and check size/count.
- S2-07 [M] Public receipt viewer (ajax_nopriv ofp_view_receipt) accepts a WordPress nonce as proof (includes/class-ofp-property-receipt-viewer.php:161). For logged-out visitors a nonce is identical for everyone and lasts 12-24h, so a leaked link works for any visitor. Fix: per-receipt signed token (HMAC of id + expiry) instead of wp_nonce.
- S2-08 [M] Receipt viewer has no rate limit (same file). Payment IDs are sequential; token/nonce stops guessing but add check_rate_limit like my-payments does.
- S2-09 [M] Prembly ajax_verify has no nonce and no rate limit (includes/class-ofp-prembly.php:267). It spends paid Prembly calls and has a raw cookie-session fallback that duplicates OFP_Auth. Fix: add nonce + per-client rate limit; use only OFP_Auth::current_client().
- S2-10 [M] bank list (ajax get_bank_list) has no nonce or rate limit (includes/class-ofp-bank-account.php:364). Low risk (needs login) but is registered nopriv too. Remove nopriv; add nonce.
- S2-11 [M] CORS `Access-Control-Allow-Origin: *` on ofp/v1 REST and /widget.js, /api/v1 routes (rest-api.php:63,670,680). OK for public widget/feed, but not for capture-lead/webhooks. Fix: allow-list origins per client for write routes.
- S2-12 [M] All 8 REST routes use `permission_callback => __return_true`. Public by design for chat/leads/properties, but webhooks must verify inside the callback (see S2-03/04). Paystack webhook is out of audit scope.
- S2-13 [L] admin_post_ofp_update_client is registered but handler method handle_update_client does not exist (admin/class-ofp-admin-menu.php:42). Any call = fatal error. Existed in v2.2.28 original. Fix: remove the add_action (edit uses ofp_edit_client).
- S2-14 [L] admin_post_nopriv hooks for client payment verify/reject and resend offer (3) are logged-in-only actions. They are guarded inside (nonce + client login + ownership join), so not exploitable; remove nopriv to reduce surface.
- S2-15 [L] 7 more AJAX handlers are registered with `wp_ajax_nopriv_` though they need a login (fetch_leads, fetch_communications, add_custom_amenity, submit_feedback, resolve_bank_account, my_receipt, lease_proof). Guarded inside; nopriv is needed only because clients are not WordPress users. Revisit when staff move to WP users.
- S2-16 [L] ofp_save_universal_template and ofp_send_broadcast check super admin then nonce (admin-menu.php:1742,1756): fine, but order should be nonce first. Also `wp_redirect` (not safe_redirect) in 8 places; all use fixed URLs, switch to wp_safe_redirect.
- S2-17 [L] Encryption helper uses AES-256-CBC with no authentication (HMAC) (includes/class-ofp-security.php:98). Fine for at-rest API keys; use AES-GCM or add HMAC.
- S2-18 [L] Settings page decrypts Paystack key into the page (admin/views/settings.php:116). Check it is masked in HTML source and never echoed in full.
- S2-19 [L] Files missing ABSPATH guard near top: admin/views/settings.php, includes/class-ofp-sms.php, class-ofp-queue.php, class-ofp-property-cpt.php. Add `if ( ! defined('ABSPATH') ) exit;`.
- S2-20 [L] Stray Ofast_Pipeline_Universal_Receipt.html in plugin root is web-reachable (see S1-11). Remove or move.
- S2-21 [L] lead.php:104 `LIMIT {$limit}` is typed `int $limit`, so safe. No eval/unserialize/exec/ssl-verify-off found. Variable include paths still to confirm come from whitelisted slugs (admin-portal.php:613, admin-menu.php:279, property-sales.php:48/65/85, client-portal.php:689/732, property-marketplace.php:72, admin-url.php:487).

Checked and OK: SQL uses prepare + placeholders everywhere reviewed; admin_post handlers use require_admin_post/require_super_admin_post (nonce + ofp_admins check) except where noted; client AJAX uses nonce + session; client payment verify/reject has ownership join (IDOR safe); receipts stored in ofp-private-receipts with deny .htaccess + real mime check; API keys encrypted at rest; Turnstile on login/signup; lead honeypot + phone check.
Not yet checked: CSV import (class-ofp-import), login/OTP brute-force limits, session cookie flags, password hashing, XSS in JS innerHTML, variable includes (S2-21), open-redirect on login `redirect` param.


## STAGE 2 CLOSE OUT (2026-11-08) [BOTH unless noted]
Checked on the no whitelabel zip, PHP 8.3 syntax check: 145 PHP files, 0 errors. No white label code left.
Confirmed bugs from earlier notes:
- S0 01 confirmed, High (bug): class-ofp-client-portal.php:1040 calls OFP_Activity_Log, which does not exist. Message is sent, then the page gets a fatal error and nothing is logged. Fix: use OFP_Logger.
- S2 13 confirmed, Low: admin-menu.php:42 hooks handle_update_client, which does not exist. Remove the add_action.
Items that were "not yet checked", now checked:
- Login: IP rate limit, 5 tries per login token, OTP 5 tries per token. OK.
- Session token stored hashed (sha256). Cookie HttpOnly, Secure on HTTPS, SameSite Lax. OK.
- Passwords use bcrypt. Login redirect uses wp_safe_redirect. OK.
- CSV import: 5 MB and 5000 row cap. Export blocks formula injection (class-ofp-csv.php:132). OK.
- Communications feed (render_feed_html): message text is escaped with esc_html. OK.
- Variable includes (marketplace, sales, client portal route map, admin view loader): paths come from fixed names or a fixed route map. One remaining check: where $view comes from in admin-menu.php:263 (load_view). Needs confirmation that callers only pass fixed names.
- Low: assets/js/import.js:15 puts the chosen file name into innerHTML. Only affects the person who picked the file. Use textContent.
- Still not checked: the other 80 innerHTML uses in JS that take server data.

## STAGE 3 - PREMBLY [MAIN ONLY, white-label edition removes it]
Files read: includes/class-ofp-prembly.php (362 lines), settings.php Prembly card, admin-menu.php save code, account.php form and JS, properties.php gate, clients-list.php, marketplace and agents badge code.
No file was changed.

### Findings
- P3.01 | High (Critical if your live site is on Sandbox or has no key) | prembly.php:83,157,217 and :58-60 | Test mode and "no key" both return success without checking anything. get_env() defaults to "test". Attack: any person with a free account opens Account, types any CAC number and any 11 digits, and gets is_verified = 1. This opens the Silver and Gold listing gate (properties.php:104) and shows the public Verified badge on listings and agents. Fix: simulated success only when WP_DEBUG is on or a constant OFP_PREMBLY_SANDBOX is set. Otherwise return "Verification is not available". Needs confirmation: what env is saved on your live site.
- P3.02 | High | prembly.php:307-322 | Passing the checks proves nothing about the person. Any real RC number plus any real NIN or BVN (even of a stranger) passes. The company name and ID owner are never compared with the client. Fix: compare the CAC company name with the client business name (fuzzy match), compare NIN or BVN name data with owner name, or use Prembly face match (nin_w_face, bvn_w_face) with a selfie. Needs your decision on how strict to be.
- P3.03 | High | prembly.php:22-23,267 and account.php JS | No nonce, no rate limit, no daily cap, no check that the client is already verified. Each click can cost 2 paid calls. Attack: a script with one free account loops the request and drains your Prembly wallet. A hostile page can also fire it for a logged in client (CSRF). Fix: nonce (ofp_client_action), OFP_Security::check_rate_limit per client (for example 3 per day), and refuse if is_verified is already 1.
- P3.04 | Medium | prembly.php:279-290 | Raw cookie fallback duplicates OFP_Auth. It accepts the raw token or its stored hash (IN (hash, raw)), so a leaked hash from the database works as a cookie on this endpoint. It also ignores user_type, so a team member session verifies the whole company. Fix: delete the fallback, use OFP_Auth::current_client() only. Needs confirmation of how current_client treats team members.
- P3.05 | Medium | prembly.php:297,332,336-353 | id_type is not checked against a list (anything not "bvn" is treated as NIN), and the raw value is saved (column is VARCHAR 20). If wpdb->update and the fallback query both fail, the code still says success. ofp_prembly_env is also not limited to test or live (admin-menu.php:757); any other value is treated as live. Fix: allow only nin or bvn, allow only test or live, and check the result of the update before sending success.
- P3.06 | Medium | prembly.php:113-141,176-201 | Failure handling. Two calls of 20 seconds each can hold a PHP worker for 40 seconds. No retry. Raw provider messages and the raw WP_Error text (line 123) are shown to the user. Prembly response_code 02 (service unavailable) is treated like 01 (not found). If CAC passes and NIN fails, the next try pays CAC again (no caching). Fix: 10 second timeout, show a fixed message for outages, read response_code, and save the CAC result for the client for 24 hours.
- P3.07 | Medium, needs confirmation | prembly.php:94,166,226,129,191,251 | The code uses the older /identitypass/verification/... paths and requires body["data"]. Prembly docs now show POST https://api.prembly.com/api/v1/verification/api/{service_name}/ with an X-API-Key header. The old paths may still work, but the response shape can differ, and then real users would always fail. Test once with a real key in live mode before trusting this.
- P3.08 | Low | clients-list.php, schema | Verified is forever. No admin button to revoke or to verify by hand, no record of which ID type or reference was used, no re check. Fix: add an admin revoke action (nonce and super admin) and store the Prembly reference.
- P3.09 | Low (speed) | prembly.php:21 and account.php:51 | ensure_client_schema runs 5 SHOW COLUMNS queries on every request. Fix: move to the activator with a version option.
- P3.10 | Low | prembly.php:23,296-298 | nopriv registration does nothing useful. $_POST values are not wp_unslash. Fix both.

### Done well (leave alone)
- API key is encrypted in the database, saved only when a new value is typed, shown in a password field with a masked placeholder, and never sent to the browser.
- Calls go to a fixed HTTPS host. SSL check is left on (default). No user controlled URL, so no SSRF.
- NIN and BVN numbers are cleaned to digits, are NOT saved and NOT logged. Prembly response data is not stored.
- The result is decided only on the server. The browser cannot send "verified". Only the server updates is_verified.
- Front end message uses textContent. All displayed values use esc_html.
- No Prembly webhook or callback exists, so there is no signature risk.

### Flow check (settings > save > class > form > result > database > admin > badge)
- Option names match everywhere (ofp_prembly_app_id, ofp_prembly_secret_key, ofp_prembly_env). Form field names match the handler. Columns are read in clients list, account, agents, marketplace, homepage, single property.
- Broken link: the form sends no nonce, so the server cannot check one (see P3.03).
- Note for the white-label fork: clients-list is the only admin view of the badge.

### Open questions for owner
- Q7 | What is the saved Prembly env on live (Sandbox or Live), and is a key saved?
- Q8 | How strict should matching be: name match only, or selfie match (costs more per check)?


## OWNER ANSWERS (2026-11-08) for Stage 3
- Prembly account is verified but not funded. Live site uses Sandbox with a test key. So P3.01 is REAL on your site today: anyone can self verify.
- Matching rule chosen: CAC plus either NIN or BVN, and the CAC company name must match the business name the client used at signup. Fix plan for P3.02: after Prembly returns the company name, compare it to ofp_clients.business_name (ignore case, spaces, and words like LTD, LIMITED, ENTERPRISES, NIGERIA). If it does not match, refuse and do not save. Note: BN (business name) registrations often differ slightly from the trading name, so allow admin to approve a near match by hand.
- Also do now: P3.01, P3.03 (nonce, rate limit, already verified check), P3.05.

## STAGE 4 - BUGS AND LOGIC [BOTH unless noted]
Method: PHP 8.3 syntax check on all 145 files (clean), then read of cron handler, queue, activator, schema code, migration and repair classes, offer acceptance, installment maths and reminders, rewrite flushing, uninstall. No file was changed.

### Findings
- P4.01 | High | includes/class-ofp-property-commerce-repair.php:12 and :21-63 | reconcile_properties runs on EVERY init request, for every visitor. It loads all properties of all clients, then for each one reads 3 meta values, runs a SELECT and an UPDATE (and can call wp_update_post). With 500 listings that is about 2,000 queries and 500 writes on every page load. Fix: remove the init hook. Run it once with an option flag, or only on save_post and from a daily cron.
- P4.02 | High, needs confirmation of the last part | includes/class-ofp-property-commerce.php:220-300 | Offer acceptance checks status, existing purchase and duplicate buyer BEFORE START TRANSACTION, with no row lock. Two taps, or two offers on the same property accepted at the same moment, can both pass and create two active purchases for one property. Fix: inside the transaction, first run UPDATE offers SET status='accepted' WHERE id=%d AND status='pending' and stop if rows_affected is 0, and lock the property row with SELECT ... FOR UPDATE before the purchase check. Please also let me read lines 300-340 and the manual add purchase path (purchase-service.php) for the same pattern.
- P4.03 | High (carried) | client-portal.php:1040 OFP_Activity_Log does not exist (S0 01). Credit deduct not atomic (S2 01) is also a race bug.
- P4.04 | Medium | activator.php:38-60, 119 | Main tables (23 dbDelta calls) and maybe_upgrade_schema run only on activation. Replacing plugin files on a live site does not run them, and there is no global ofp_db_version check (the option is only listed in uninstall). Many classes heal themselves with their own option flags (good), but the core tables do not. Fix: on plugins_loaded, if get_option('ofp_db_version') differs from OFP_VERSION, run create_tables and maybe_upgrade_schema, then save the version. Multisite: nothing handles network activation (needs confirmation, no is_multisite code found); tables would only be created for the site that activates.
- P4.05 | Medium | ofast-pipeline.php:150 and cron-handler.php:78-79 | Monthly archive drift (S1 03 confirmed). Interval is 30 days, so after the first run it moves off the 1st. It also uses strtotime('last month'), which on the 29th, 30th or 31st lands in the wrong month (31 March gives 3 March, so it archives March again). It uses gmdate, not site time. Fix: schedule one single event for the 1st at 02:00 site time and reschedule it at the end of each run. Use first day of last month for the month maths.
- P4.06 | Medium | cron-handler.php:56-66 | check_subscriptions runs 4 jobs one after another with no try/catch. If the subscription check throws, installment reminders, lease reminders and log purge do not run that day. Fix: try/catch around each, or give each its own hook.
- P4.07 | Medium | includes/class-ofp-property-installment-reminders.php:37-41,105 | Overdue notice is sent only when days late is exactly 1. If cron did not run that day (common on shared hosting), the buyer never gets it, and the row is skipped by "if (! $type) continue", so the status may never change to overdue (the update at line 105 sits after that skip; needs confirmation of the full flow). Fix: treat any days late of 1 or more as overdue, mark status overdue first, and repeat the reminder every 3 days up to a limit.
- P4.08 | Medium (speed) | includes/class-ofp-property-commerce-migration.php:8 | Runs 3 INFORMATION_SCHEMA queries and a CREATE TABLE IF NOT EXISTS on every request. It is safe to run twice, but wasted. Fix: guard with an option flag. Since you have no live clients (D-03), move these columns and the contacts table into the main create tables and delete the class ("check first").
- P4.09 | Low | uninstall.php | The drop block is commented out, so deleting the plugin removes nothing, and the table list is out of date (misses about 25 tables such as property purchases, payments, leases, bank accounts, chat). There is no personal data erase or export hook. For the white-label edition, buyers will need a real uninstall and erase option.
- P4.10 | Low | activator.php create tables | CREATE TABLE lines use "PRIMARY KEY (id)" with one space; dbDelta wants two spaces. Needs confirmation on your WordPress version. Fix: two spaces, then check the debug log on staging for key errors.
- P4.11 | Low | property-cpt.php:122 vs ofast-pipeline.php:218 | Two flush systems: the CPT flushes at its own init time (before later routes exist) and checks a transient that no one sets, while other classes set an option. Fix: use only the central option flag.
- P4.12 | Low | activator.php:706-707 vs cron-handler.php:31 | S1 02 resolved: the activator schedules a 5 minute event, then init replaces it with the 2 minute one. No double run. Make the activator use the 2 minute schedule directly.
- P4.13 | Low | many files | Date handling mixes date(), gmdate(), wp_date() and current_time('timestamp') (discouraged since WP 5.3). Example: reminders.php:44 compares date() to a site time day. Fix: one helper that uses wp_date and the site timezone.
- P4.14 | Low | class-ofp-import.php:119 | utf8_encode fallback is deprecated in PHP 8.2. Use mb_convert_encoding.

### Done well (leave alone)
- Queue claims each row with UPDATE ... WHERE status='pending' and checks the result, so one message cannot be sent twice. Stuck rows are failed, not resent. Retry limit of 3. Old rows are skipped after 48 hours. Times are stored in UTC and compared in UTC.
- Money columns are DECIMAL(14,2). The installment total must match the balance within 0.01.
- Offer expiry and property taken checks exist. Reminder is limited to one per day per installment.
- Schema helpers behind option flags (watermark, inquiries, rent, bank account, platform, comms log). Migration and repair classes are safe to run twice.

### Not checked in Stage 4 (say if you want it done)
- Runtime warnings (undefined index, null access). A syntax check cannot find these. Needs a staging run with WP_DEBUG on.
- Lease and rent status flow, tenant transfer approval flow, lead status flow, lease reminders.
- Manual add purchase race (purchase-service.php), and the rest of the offer acceptance function.


## STAGE 4 - PART 2 (the items I first left unchecked, now read in full) 2026-11-08
Added after the owner asked me to read the "not checked" items. What I did: read the rest of offer acceptance, the manual purchase path, lease payments, tenant transfer approve and reject, lease reminders, lead status code, then ran PHPStan (level 1, WordPress function and class noise filtered out) on the no whitelabel zip, and checked every hit by reading the code. A new check also compared all 29 methods removed by the white label work against the code that is left.
Correction to the earlier note in this file: "no undefined new class references" only looked at class names, not methods. It missed the first item below.

- P4.15 | CRITICAL [regression from the white label removal] | public/templates/login.php:170, signup.php:225, forgot-password.php:43, reset-password.php:57 | OFP_Brand::print_host_head() was deleted from class-ofp-brand.php (it was at line 405 in the frozen original) but these 4 templates still call it. Result: fatal error on the login page, signup page, forgot password page and reset password page. Nobody can log in or sign up with the no whitelabel zip. Of the 29 removed methods this is the only one still called. Fix: delete those 4 lines. The method only printed head tags for a solo host, which no longer exists.
- P4.16 | High | includes/class-ofp-feedback.php:214 and :327 | OFP_Notification::create() is called with one array, but its signature is create(int $client_id, string $type, string $title, string $message) (class-ofp-notification.php:42). On PHP 8 this is an ArgumentCountError, a fatal error, when feedback is submitted or answered. Exists in the frozen original too. Fix: pass the 4 arguments.
- P4.17 | High | admin/class-ofp-admin-menu.php:1964 | OFP_Auth::current_admin_name() does not exist. The admin resend communication action ends in a fatal error. Exists in the original. Fix: use OFP_Auth::current_user() and read the name, or add the method.
- P4.18 | Medium | admin/class-ofp-property-commerce-admin.php:258,262 | $offer_url is never defined in that file. After an admin creates an offer, the "Send the secure buyer link" box never shows (PHP warning, link missing). Fix: build the URL from the new offer token and pass it in.
- P4.19 | Medium | admin/views/customers-list.php:111 | Calls OFP_Property_Receipt_Generator::build_rent_data(), which is private (receipt-generator.php:293). Opening a rent receipt from the admin customers list ends in a fatal error. Fix: make the method public.
- P4.20 | Medium | public/templates/forgot-password.php, reset-password.php, team-invite.php (line 1) | These files start with a UTF-8 BOM (hidden bytes EF BB BF). They are sent before WordPress can set headers, which can cause "headers already sent" and break cookies and redirects on the password reset and team invite pages. Fix: re save the 3 files without BOM. settings.php also ends with whitespace after the closing tag (line 4535).
- P4.21 | High (money) | includes/class-ofp-property-lease-payment.php:56-84 (success) | Approving a rent payment is not atomic. It reads the status, then writes, then reads the lease and writes a computed total. Two quick clicks on Approve both count as newly successful, so the payment events (receipt, SMS, email, in app notice) can fire twice. Two different payments approved at once both read the same amount_paid, so the second overwrites the first and the balance is wrong. Fix: claim with UPDATE ... SET status='successful' WHERE id=%d AND status='pending_verification' and stop if rows_affected is 0. Then update the lease with one query: amount_paid = LEAST(rent_amount, amount_paid + %f) and balance = GREATEST(0, rent_amount - new amount_paid).
- P4.22 | Medium (money) | tenant-transfer.php:92-105 and lease-payment.php:75 | A tenant may have up to 5 pending transfers, and each is checked against the balance alone, not against the other pending ones. When the owner approves them all, the total is cut off at the rent amount by min(), so the extra money the tenant really sent disappears with no flag and no record. Fix: when approving, if the amount is more than the balance, refuse or record the extra as a credit and tell the owner.
- P4.23 | Medium (logic) | lease-payment.php:19 vs lease-reminders.php:20,59-63 | create() refuses payments when the lease status is "expired" (only pending_offer and active are allowed). The reminder job sets leases to expired after the end date and then tells the tenant to "settle your account". A tenant who tries to pay a late balance by transfer is blocked. Fix: allow expired leases while balance is above 0.
- P4.24 | Medium (money) | includes/class-ofp-property-commerce.php:343,355 and purchase-service.php:calculate_due_date | Month maths overflow. PHP turns 31 January plus 1 month into 3 March (tested). Installments that start on the 29th, 30th or 31st drift and skip months. Fix: use DateTime, add the months to the 1st, then use min(day, last day of that month).
- P4.25 | High (logic) | commerce.php:355 | An accepted offer ignores the offer frequency. The purchase row stores frequency (weekly, quarterly...), but the schedule is always built with "+N month". A weekly or quarterly offer gets a monthly schedule. The manual path (purchase-service.php) does use frequency correctly. Fix: call the same calculate_due_date helper in both places.
- P4.26 | Medium | commerce.php:327-366 | Installment inserts are not checked. If one insert fails, the code still commits, so a purchase can exist with a missing schedule. Fix: check each insert and throw so the rollback runs. Needs confirmation: whether the offer creation step checks that initial plus installments equal the price (the manual path does).
- P4.02 CONFIRMED (both paths) | commerce.php:220-300 and purchase-service.php:~40-130 | In both the accept offer path and the manual add purchase path, the checks (property taken, existing purchase, duplicate buyer) run before START TRANSACTION and with no lock. Two requests at the same time can both pass. Fix as written in P4.02 earlier, and also lock the property row with SELECT id FROM ofp_properties WHERE id=%d FOR UPDATE in both paths.
- P4.27 | Low, CORRECTED in Stage 5: the whole file is dead (never loaded, the voice-ivr web address goes to OFP_Voice), so this fatal can never run | includes/class-ofp-ivr.php:183 | OFP_Lead::record_ivr_response() does not exist, so every IVR digit callback ends in a fatal error. IVR is meant to be removed (D-08), so remove the whole class instead of fixing it. [BOTH until removed]
- P4.28 | Low | lead.php:236-251 | update_status accepts any text as a status (no allowed list). add_note reads the notes, adds a line and writes them back, so two notes at the same time lose one, and notes grow without limit. Fix: allow only known statuses; append with CONCAT in one query.
- P4.29 | Low | lease-reminders.php:20-63 | A lease only flips to "expired" when a reminder is sent that day. A lease more than 90 days past its end date never flips. Expired leases with no balance still get "Outstanding 0" reminders for 90 days. SMS cost is a fixed 6.99 in the file, separate from the credit price. Fix: flip the status in its own query first.
- P4.30 | Low | admin/views/billing.php, property-billing.php, partials/nav.php, signup.php:268 | PHPStan lists variables that "might not be defined" ($total_pages, $total_revenue, $client, $owner_name). Billing views go in the white-label strip. nav.php gets $client from its caller (OK today). signup.php:268 shows $owner_name on the success screen; needs confirmation it is always set before $success is true.
- Dead code, not a bug: OFP_Bank_Account::online_payments_enabled() is called by render_online_option() (payment-entry-ui.php:17), but that method is never hooked, so nothing calls it. Move to Stage 5 as dead.

### Stage 4 status
Everything on my "not checked" list is now read, except a staging run with WP_DEBUG on (a tool cannot replace that) and the code of the files inside the payment gateways (out of scope).
Plan note: P4.15 must be fixed before anyone installs the no whitelabel zip, so put it first in the Phase 1 fix plan.


## STAGE 5 - DUPLICATE AND DEAD CODE (2026-11-08) [report only, nothing deleted]
Method: a script that finds methods never referenced anywhere, classes used only in their own file, PHP files never required, views and assets never referenced by name, registered AJAX actions with no caller, JS actions with no PHP handler, options written but never read (and the reverse), identical function bodies, and files that share most of their lines. Each hit was then checked by reading the code. Numbers: 145 PHP files, 23 JS files, 28 CSS files.

### Dead code, safe to remove (all in MAIN; check the white-label fork list too)
- D5.01 | includes/class-ofp-property-payment-context.php | Never required or called by any file. 3 unused methods inside (generate_reference, process_verified_payment, process_verified_va_payment). Safe to delete.
- D5.02 | includes/class-ofp-property-payment-entry-ui.php | Never required. Its only method is never hooked. It calls OFP_Bank_Account::online_payments_enabled(), which does not exist. Safe to delete.
- D5.03 | includes/class-ofp-ivr.php | Never required. The address /webhook/voice-ivr goes to OFP_Voice (rest-api.php:105 to 108), not to this class. Its call to a missing OFP_Lead::record_ivr_response() can never run. Safe to delete. This matches decision D-08 (IVR removed). Keep the route name, the comment says queued calls still use it.
- D5.04 | admin/views/property-billing.php (134 lines) | No file loads it. The admin portal has its own property-billing tab (admin-portal-template.php:874). Its PHPStan undefined variable warnings from Stage 4 go away with it. Check first: open the portal tab once and make sure it does not rely on this file.
- D5.05 | assets/css/marketplace.css and assets/js/marketplace.js (8 KB each) | No file names them. Marketplace pages use Tailwind and Alpine instead (property-marketplace.php:24 and after). Check first: open the marketplace page and look at the network tab for these two names.
- D5.06 | Methods nobody calls: OFP_Admin_URL::get_custom_login_url and get_emergency_bypass_url (leftovers of a hide login feature), OFP_Brand::has_custom_logo and logo_icon_url (white label leftovers), OFP_Payment::get_active_provider, OFP_Subscription::process_gateway_payment (about 70 lines; check first that no webhook path calls it by a variable name), OFP_Property_Commerce_Repair::mark_property_taken (private, one line, unused).
- D5.07 | AJAX action ofp_get_bank_list is registered but I found no caller in any JS or template. Check first, then remove.
- Option ofp_db_version is written and never read. It becomes useful when P4.04 is fixed, so keep it.

### Real bug found during Stage 5
- B5.01 | Medium | includes/class-ofp-property-cpt.php:293 | Reads the option ofp_company_account_number, but the admin saves it as ofp_company_account_no (admin-menu.php:810 and :1429). The company account number is always empty, so the property editor shows a dash for the platform bank account. Fix: read ofp_company_account_no.

### Duplicates
- X5.01 | The company bank details are saved by two separate pieces of code with the same 3 options (admin-menu.php:809 to 811 and :1427 to 1430). One of them also skips wp_unslash. Keep one handler.
- X5.02 | The theme toggle function ofpToggleTheme is pasted in 8 templates (blog archive, blog single, agents, property marketplace, property homepage, property single detail, agent profile, plans pricing). 6 of them are exactly identical. Move it to one JS file.
- X5.03 | Page shells share a large part of their lines: blog archive and blog single 56 percent, agents and marketplace 50 percent, signup and login 49 percent, marketplace and homepage 46 percent (header, footer, theme switch, Tailwind setup). Do NOT merge now. Plan shared partials in a later phase, after bugs are fixed.
- X5.04 | Tailwind is loaded from the Play CDN (cdn.tailwindcss.com, version 3.4.17) in 5 places: blog archive, blog single, agent profile, plans pricing, and the marketplace loader. That script is meant for development, compiles in the visitor's browser on every page view, and is an outside dependency. Move to Stage 6 (speed) with the fix: build one static CSS file once.
- X5.05 | Large single CSS files: client-portal.css 308 KB, admin.css 82 KB, property-checkout.css 63 KB. Stage 6 item.

### Checked and clean
- No var_dump, print_r, console.log or debugger lines. The 45 error_log calls are real error reports, not leftover debug. One note: gateways/class-ofp-gateway-paystack.php:71 writes the whole provider response to the log. Cut it to the first 300 characters.
- Only 6 lines of commented out code and no TODO, FIXME or HACK notes.
- Every JS action name has a PHP handler. The CSV templates (loaded by name in import.php:32 and class-ofp-import.php:921), the root receipt HTML file (receipt-generator.php:201), chart.min.js (client-portal.php:314), the Paystack gateway (loaded by a class name built at run time) and the PlusJakartaSans font (watermark.php:277) are all in use. Keep them.
- Of 145 PHP files, 3 are never loaded (D5.01 to D5.03). Of 28 CSS and 23 JS files, 2 are unused (D5.05).

### Size of the clean up
About 3 dead PHP files, 1 dead view, 2 dead assets, 7 unused methods. Low risk, and no live client depends on any of it (D-03).


## DECISION D-16 (2026-11-08): REMOVE VOICE COMPLETELY [BOTH editions]
Owner decision: remove voice calls, voice scripts (call scripts) and IVR completely from the codebase. This is wider than D-08 (which only said IVR). SMS and email stay. Nothing has been changed yet. This list was made by searching all PHP and JS files for voice, ivr, tts and call script words (the word "invoice" was ignored). After the removal, the same search must return zero hits except the one time clean up step.

### 1. Delete whole files
- includes/class-ofp-voice.php (Africa's Talking call maker and the callback handler)
- includes/class-ofp-voice-notice.php (call scripts, call hours, interest call and reminder call jobs)
- includes/class-ofp-ivr.php (already dead, see D5.03)
- Remove the 2 require_once lines (ofast-pipeline.php:67 and :68) and the init call (ofast-pipeline.php:191)

### 2. Code that calls voice and must be edited
- public/class-ofp-rest-api.php: the /webhook/voice-ivr route (lines 103 to 108), the voice_webhook method (354 to 366), and the interest call schedule at 309 to 311 (after a lead is created). Keep the sms-dlr route.
- includes/class-ofp-property-installment-reminders.php:78 to 80 (voice call on the due day) and the same kind of block in includes/class-ofp-property-lease-reminders.php (3 voice lines).
- includes/class-ofp-queue.php: VOICE_COST constant (line 41), the comments at lines 6 and 40, the "voice rows" branch at 350, and migrate_voice_followups_once (639 to 670). Also remove its call in cron/class-ofp-cron-handler.php:24 after the one time clean up below.
- includes/class-ofp-credit.php: voice channel in topup and deduct (lines 5, 9, 31, 86 and the voice columns it reads).
- includes/class-ofp-payment.php:75, 126, 136: channel list and the reference pattern ofp_credit_(sms|voice)_ become sms only.
- includes/class-ofp-subscription.php:370 (default follow up 2 type voice) and :878 (credit label).
- includes/class-ofp-mailer.php: low credit warning has a channel argument for sms or voice (lines 35, 779, 783). Keep sms only.
- includes/class-ofp-communications-log.php: voice type in log writer, pair merge code (lines 104 to 135), type detection by the words "voice" and "call" (220 to 261), provider label (322), badge (459 to 460). Old rows with type voice: delete or keep as history (owner choice).
- includes/class-ofp-csv.php:879 to 885 (voice balance card in the export), includes/class-ofp-sms.php:21 (comment), includes/class-ofp-platform.php:14 (comment), includes/class-ofp-client.php (2 mentions).
- includes/class-ofp-chat-service.php:217 and :238: the AI helper tells users about voice follow ups. Edit the text so the chat does not promise voice.

### 3. Admin and client screens
- admin/views/settings.php: tab title "SMS & Voice" (590), call script box and call hours block (1863 to 1929), Africa's Talking "SMS and Voice" card (2521 to 2523) and the Virtual Phone Number (Voice Calls) field (2611).
- admin/class-ofp-admin-menu.php: the 3 voice hours options saved at 731 to 733; credit top up channel list at 1233 to 1234, 1258, 1459 to 1460.
- admin/views/billing.php (about 16 lines: voice circulation, voice balance column, voice option at 575), admin/views/clients-list.php (386 to 444: voice credit option, price text, voice balance), admin/views/communications.php and communications-log.php (voice count, filter and badge), admin/views/portal/admin-portal-template.php:667 (text).
- public/templates/pipeline-settings.php (about 20 lines: the whole call scripts card, nonce, save code, balance), assets/js/pipeline-settings.js:103.
- public/templates/funding.php (12 lines: voice channel in top up, balance, price text), credits.php (voice balance card 137 to 142), dashboard.php:16, reports.php:181 (SMS & Voice Spend option), message-templates.php (voice count, filter and preview at 89 to 202 and 939 to 944), activity-logs.php:279 to 280, api-settings.php:223 (text), signup.php:451 and :522 (text), login.php:355 (text), blog-archive.php, blog-single.php, agent-profile.php, plans-pricing.php (marketing text that says voice), assets/js/client-portal.js:1257 (title text).
- Plan texts and pricing pages that promise "auto voice relay" or "automated voice calls" must change too (signup, login, plans, blog and agent pages).

### 4. Database and options (fresh installs only, there are no live clients, D-03)
- ofp_credits: drop voice_loaded, voice_used, voice_remaining from the create table (activator.php:375 to 377) and from the upgrade code.
- ofp_clients: drop voice_api_key_encrypted (activator.php:290).
- ofp_leads: drop ivr_response (activator.php:316).
- ofp_pipeline_configs: followup_2_type default 'voice' becomes 'sms' (activator.php:436). Check the other followup type columns too.
- ofp_communications_log: type 'voice' is no longer written. Decide what to do with old rows.
- Options to delete in uninstall and in one upgrade step: ofp_voice_hours_enabled, ofp_voice_hours_start, ofp_voice_hours_end, ofp_ivr_responses, ofp_voice_followups_removed, ofp_default_call_* (one per call type), per client voice notice settings, and the virtual phone number option.
- Scheduled events to clear in that step: ofp_send_interest_call and ofp_send_voice_reminder (use wp_unschedule_hook).
- uninstall.php:28 lists ofp_ivr_responses.

### 5. Risks to check before the work
- Money: removing the voice credit columns changes the credit and payment code, which is the same code as S2 01 (credit race) and the payment reference pattern in payment.php. Do these changes together in the same step so credits are tested once.
- If any live site already pays with a reference like ofp_credit_voice_..., the webhook would stop matching it. There are no live clients (D-03), so safe.
- Search again after the removal for: voice, ivr, call_script, default_call, VOICE_COST, interest_call, tts.
- Old SMS queue rows that are typed voice are already cancelled by the existing clean up. Keep that clean up as the last use of the word voice, then delete it in a later version.
- The REST address /webhook/voice-ivr can be removed completely (no calls are queued, no live clients).


## STAGE 6 - SPEED AND PERFORMANCE (2026-11-08) [BOTH unless noted]
Method: I READ the code. I did not run the site, so nothing below is a measured time. What I did: listed all 29 hooks on init, plugins_loaded, wp_loaded and admin_init; read the early part of every per request handler; listed every table index and compared with real queries; searched for queries inside loops; checked WP_Query settings; counted caching, outside calls and their time limits; checked how assets load and how big they are. Before launch, confirm with Query Monitor on a staging site (see the last section).

### Findings
- P6.01 | High | commerce-repair.php:12,21-63 | The biggest one (same as P4.01). reconcile_properties runs on every request and does about 4 queries and 1 write per listing. This alone can make every page slow once there are a few hundred listings. Fix first.
- P6.02 | High | Setup code that runs on every request | Together with P6.01 each page also pays for: 5 SHOW COLUMNS queries (Prembly, P3.09), 3 INFORMATION_SCHEMA queries plus CREATE TABLE IF NOT EXISTS (commerce migration, P4.08), and about 15 schema helpers that each read an option. Fix once: one ofp_db_version check (P4.04) and remove the per request ones.
- P6.03 | Medium | public/templates/property-marketplace.php:60-123 | The marketplace list uses WP_Query with meta_query (NUMERIC price range, OR relation, verified filter) and orderby meta_value_num on postmeta. Postmeta values have no usable index, so this gets slow past a few thousand listings. The plugin already has an indexed table, ofp_properties (client_id, status). Fix later: filter and sort from that table, or cache the list of IDs for 5 minutes. Also: includes/class-ofp-property-cpt.php:2403 count_for_client loads every listing ID just to count them (called by can_add_property); use SELECT COUNT(*) on ofp_properties. get_client_properties (cpt.php:2426) and agent-profile.php:120 load all listings with posts_per_page -1; add paging.
- P6.04 | Medium | Missing indexes | (a) ofp_clients.subdomain has no index. It is searched on every request that comes from a client subdomain (host-router.php:142) and when a subdomain is checked (client.php:648, 657). Add KEY subdomain. (b) ofp_communications_log has only client_id and lead_id keys, but the admin pages run 3 full table COUNT(*) by type (communications.php:88-90, communications-log.php:52-54) and sort by created_at. Add KEY type_created (type, created_at) and KEY client_created (client_id, created_at), and keep the 3 counts in a transient for 5 minutes. (c) ofp_leads has client_id and status separately; most lists use both, so add KEY client_status (client_id, status) and KEY client_created (client_id, created_at). Fresh install, so put them in the create tables (D-03).
- P6.05 | Medium | Tailwind Play CDN and Alpine | The marketplace, homepage, blog, agent profile and plans pages load cdn.tailwindcss.com. The browser must download a large script and build the CSS on every visit, so first paint is slower and the page can flash unstyled, which hurts most on a phone with weak data. Alpine comes from jsDelivr with no integrity hash. Fix: build one static tailwind.css file once with the Tailwind command line tool, ship that, and self host Alpine (pinned version). The CDN is also an outside dependency that can go down.
- P6.06 | Medium | Asset sizes | client-portal.css 301 KB, admin.css 80 KB, property-checkout.css 61 KB, client-portal.js 77 KB (chart.min.js 200 KB is only loaded on dashboard, leads and reports, which is correct). Inline script in templates: message-templates.php 41 KB, properties.php 36 KB. Fix: minify the CSS and JS at release time, check which routes load the big CSS (the portal enqueues many style files in client-portal.php:120-240, so it may already be split; needs confirmation in the browser network tab), and move big inline scripts to cached files. Large PHP templates (settings.php 204 KB, property-single-detail 191 KB, property-homepage 181 KB) do not slow the server much, but they are hard to maintain.
- P6.07 | Medium | includes/class-ofp-sms.php:184, 227, 270 | SMS time limits are 15 seconds (30 seconds for SmartSMS). Instant lead SMS is sent after the visitor already got the page (queue.php:470-488, good), but OTP and manual sends run inside the user's own request, so a slow provider freezes the page for up to 15 to 30 seconds. Fix: 8 second limit for these, and show a "taking longer than usual" message. Other calls: Paystack, Prembly (20 seconds, P3.06), Turnstile and the chat AI all set time limits.
- P6.08 | Medium, needs confirmation | options | 70 update_option calls, only 33 use autoload false. Large options with autoload yes are read on every request. I cannot see sizes without the database. Run on staging: SELECT option_name, LENGTH(option_value) FROM wp_options WHERE autoload='yes' AND option_name LIKE 'ofp_%' ORDER BY 2 DESC LIMIT 20.
- P6.09 | Low | purchase-service.php:282 | A wp_footer function writes a script into EVERY front end page for every visitor. The script loops all forms to add a frequency box. This belongs in the one template that needs it (offer and purchase form), not in every page. Same file has two more late patches (admin_footer at 235 and wp_footer at 335). See correction below.
- P6.10 | Low | commerce.php:505-530 | sync_purchase_totals() with no id loops all purchases with 3 or more queries each. Called from one admin action (commerce-admin.php:222). Fine for hundreds, slow for thousands. Replace with one grouped query.
- P6.11 | Low, needs confirmation | chat-service.php:42,325 | The chat widget code runs on every front end page (wp_footer) and calls get_current_user_context, which probably looks up the session in the database even for visitors. Check that widget_visible is cheap and exits first for public pages.
- Looks fine: no query loops that matter (homepage listing cards use meta that WordPress already loaded in one query), queue has a good index (scheduled_at, status), cron uses batches, all client and property tables have client_id keys, no sleep or set_time_limit (one 0.7 second pause in chat AI retry), instant sends use the shutdown hook.

### Corrections to earlier notes (found while reading for Stage 6)
- P4.25 STANDS (offer frequency ignored). I first thought a footer patch fixes it (purchase-service.php:335 to 396). It does not: the accept request is handled at template_redirect priority 1 by commerce-repair.php:142 to 151, which creates the purchase and then redirects and exits, so wp_footer never runs for an accept. That footer patch is dead code. Remove it and fix the schedule inside create_purchase_from_offer (one shared due date helper, P4.24).
- Offer acceptance exists in two places. commerce-repair.php:142 (runs first, redirects to the checkout link with a token) and public/templates/property-offer.php:62 to 76 (calls the same service, redirects to OFP_Property_Checkout::payment_link). Because the first one always exits on success, the accept branch in the template only runs when the first one fails, and then repeats the same call to show the error. Keep one handler. This is also where the double click race in P4.02 starts.
- commerce-repair.php lines 143 to 151 are written as dense one line code. It was hard to read and review. Reformat it when it is edited.

### Suggested order for the speed fixes (all small)
1. P6.01 and P6.02 together with P4.04 (one db version flag). Biggest win for the least work.
2. P6.04 indexes in the create tables. P6.07 time limits.
3. P6.05 static Tailwind file and self hosted Alpine (one build step, then every public page gets lighter).
4. P6.03 marketplace from the indexed table, when there are more than about 1,000 listings.
5. Minify assets at release time (P6.06).

### Measure on staging before and after (recommended)
Install Query Monitor on a copy of the site with 300 test listings. Record for the homepage, marketplace, login page, client dashboard and a property page: number of queries, slowest query, page generation time, and page weight. Then repeat after the fixes above. Without these numbers every claim here is an estimate from reading code.


## STAGE 7 - WORDPRESS STANDARDS AND CONNECTION CHECK (2026-11-08) [BOTH unless noted]
Method: scripts that read every PHP and JS file and test each link in the chain (loader, classes, hooks, menus, templates, JS, AJAX, nonces, database), then the WordPress Coding Standards checker (PHPCS, WordPress Extra rules, PHP 8.1 and WP 6.0 targets) over all 145 PHP files, then reading of every spot the checker flagged that could matter. No file was changed.

### 7A. Is everything joined properly?
Checked and joined correctly:
- Loader: every require_once points to a file that exists. Every class that has an init() is started. No class is missing.
- Hooks: 1 hook points to a method that does not exist (admin_post_ofp_update_client, same as S2-13).
- Admin menu: 22 pages registered, each loads a real view. Menus are only added for plugin admins (register_menu stops if not an admin), and WordPress itself refuses pages that are not registered. All views have an ABSPATH guard.
- Nonces: every admin_post action creates and checks the same nonce name (through require_admin_post). 0 mismatches. (The checker's 175 "missing nonce" hits in admin-menu.php are false alarms for this reason.)
- AJAX: every action name used in JS has a PHP handler (Stage 5). Inline onclick handlers all call functions that exist.
- Shortcodes: none registered and none used. Nothing to clean.
Broken or loose links found:
- C7.01 | Low | admin/class-ofp-property-commerce-admin.php:163 and :178 | A form and link go to wp-admin page ofp-property-billing. That page exists only as a tab in the front end admin portal, not as a wp-admin page, so in wp-admin it shows "not allowed". Its old view file is dead (D5.04). Fix: remove the link in wp-admin or link to the portal tab.
- C7.02 | Medium | includes/class-ofp-property-cpt.php:293 | Setting is read but never saved: ofp_company_account_number (saved name is ofp_company_account_no). Same as B5.01.
- C7.03 | Low | ofp_db_version | Setting is saved but never read (it will be read once P4.04 is done).
- C7.04 | Low | ofp_get_bank_list | AJAX action with no caller (D5.07).
- C7.05 | Low | admin and settings screens | The Settings API is not used (0 register_setting). Every settings page has its own save handler with a nonce. It works, but each new setting needs 3 edits. Not a bug.

### 7B. WordPress API use
- P7.01 | Medium | includes/class-ofp-security.php (9 places outside it) | Rate limits read REMOTE_ADDR directly in 9 places (properties.php:85, funding.php:78,113,163, property-offer.php:81, tenant-portal.php:64, admin-url.php, chat-service.php, commerce-repair.php) while the safe helper OFP_Security::get_client_ip() (which understands Cloudflare and trusted proxies) is used in 24 other places. Once the site is behind Cloudflare, all visitors in these 9 places look like a few Cloudflare addresses, so real users share one limit and logs show the wrong IP. Fix: use get_client_ip() everywhere.
- P7.02 | Low | many | wp_redirect is used in 8 places (funding.php:92,136 go to a payment page, which is a correct use; admin-menu.php:1202,1752,1816 and host-router.php:168,253,259 are internal and should use wp_safe_redirect where possible). 7 strip_tags calls should be wp_strip_all_tags. 47 json_encode calls print data inside script tags (dashboard.php:730 to 734 and others); use wp_json_encode with JSON_HEX_TAG so a "</script>" inside data cannot break the page. 37 date() calls should be wp_date (time zone, see P4.13).
- P7.03 | Low | public/templates, includes | 12 files write script and link tags by hand (Tailwind, Alpine, Chart, fonts) instead of wp_enqueue. 57 other places do use wp_enqueue and 8 use wp_localize_script correctly. The hand written tags are on stand alone pages that do not load the theme, so it is a choice, but it hides the files from caching and optimisation plugins. Fix with the static CSS build in X5.04.
- P7.04 | Low | includes/class-ofp-field-agent.php:516, manual-payment.php:657, csv.php:52 | Private upload folders (ID photos, selfies, title documents, payment receipts) are protected by a .htaccess file. That works on Apache and LiteSpeed but is ignored by Nginx. File names are random (16 random bytes or a UUID), so a file cannot be guessed, but a leaked link would work for ever. Raw PHP file functions are used instead of WP_Filesystem; for writing inside uploads this is normal. Fix: add a short install note ("on Nginx add a deny rule for uploads/ofp-private*"), and keep serving files only through the token pages.
- P7.05 | Low | whole plugin | Translation: 39 translatable strings (37 use the right text domain), about 500 plain English strings in templates, no load_plugin_textdomain call and no languages folder. The plugin cannot be translated. Fix only if white-label buyers need other languages.
- P7.06 | Low | ofast-pipeline.php:6 vs :17 | Header version 2.2.27, constant 2.2.28 (S1-01 confirmed). The rewrite flush uses the constant, so it still works. Fix: one source of truth.
- P7.07 | Info | | Uses correctly: $wpdb->prefix everywhere (no hardcoded wp_ table), wp_send_json_* (128 uses), wp_safe_redirect (143 uses), prepare with placeholders, wp_mail through the plugin mailer, nonces, capability checks, wp_check_filetype_and_ext on uploads, wp_unique_filename, wp_mkdir_p.

### 7C. Compatibility
- PHP: header says 8.1 or higher. I found no PHP 8.2 or 8.3 only feature (no readonly class, json_validate, typed class constants). Syntax check passes on 8.3. I could NOT run it on 8.1 here, so test once on a real PHP 8.1 host.
- WordPress: header says 6.0 or higher. I found no function newer than 6.0 (wp_admin_notice, script strategy, flush_group and similar are not used). Not run on WP 6.0 itself.
- P7.08 | Medium | activator.php, whole plugin | Multisite is not supported. There is no network activation code, no per site table handling, and no check. On a network install the tables are made only for the site that activates, and the others break. Fix: on activation, if is_multisite() and network wide, stop with a clear message, and write "single site only" in the docs.
- P7.09 | Low | public pages | Caching plugins: the front end router sends no-store headers on every portal page (client-portal.php:726), which LiteSpeed Cache, WP Rocket and most CDNs respect. Pages with nonces for logged out visitors (property offer, receipt, tenant pages) are safe only if they also go through that router. Needs confirmation. Fix: also define DONOTCACHEPAGE next to nocache_headers(), because some plugins use only that.
- P7.10 | Low | includes/class-ofp-host-router.php:174,307 | Stale comments still talk about custom domains. The code now handles only subdomains (resolve_host_client looks up the subdomain column). Subdomain routing needs wildcard DNS and a wildcard SSL certificate (Cloudflare: Advanced Certificate Manager or an origin certificate). The login cookie is set for ".basedomain" (auth.php:281 to 292, 742 to 757), so it is shared by every client subdomain; a script flaw on one subdomain would expose the session on all. Fix: update the comments, and test the wildcard setup on staging. See also P4.08 and P6.02 for the cost per request.
- Page builders and other plugins: no dependency on Elementor, WooCommerce or any plugin. The only link is a webhook for Elementor forms (api-settings.php). The plugin draws its own pages with template_redirect and exit, so themes and page builders do not change them (and these pages do not get the theme header or footer). LiteSpeed is used for instant sends (queue.php:478). Not tested with any plugin installed together.

### 7D. Coding standards (PHPCS WordPress Extra)
Result: 75,749 errors and 2,991 warnings in 145 files; 74,873 can be fixed automatically. This looks bad but 98 percent is only code style:
- 64,412 = spaces instead of tabs for indentation (the plugin uses 4 spaces).
- 2,564 short array syntax, 996 Yoda conditions, 696 short ternary, 593 plus 593 plus 427 function call line layout, 499 one line if.
Hits that could matter, and what I found when I read them:
- 787 "SQL not prepared": 606 are only the table prefix variable {$p}; 54 more are table names. I read the rest that carry fragments (agents.php, lead.php, rest-api.php, property-inquiry.php, client.php, credit.php). Every fragment is built from fixed text with %s and %d placeholders or from a fixed list. The one weak spot is the credit column name (S2-02). 19 "NotPrepared" hits are table alter and describe commands without user data.
- 202 "nonce missing": 175 are the admin handlers explained above; 11 are the login page (it uses Turnstile; like WordPress's own login it has no nonce); the rest (purchase-service, prembly, rest-api, account.php) were checked in earlier stages or are listed there.
- 201 "output not escaped": I read the first 40. All were internal HTML (svg icons, logo HTML, the communications feed which escapes inside, trimmed content that WordPress already strips, a CSV string). I did not find user data printed without escaping. The other 160 are NOT read.
- 37 date(), 7 strip_tags, 8 wp_redirect, 3 translation text domain: see P7.02.
Advice: do NOT run an automatic reformat of the whole codebase. It would change about 75,000 lines and hide the real fixes in review. Add a phpcs.xml that skips the style rules and keeps only the Security, DB, PHPCompatibility and I18n groups, and run the full style fixer only on files you edit.

### Stage 7 status
Done. Items I could not fully check are in section 6 of the final report.


# FINAL REPORT (2026-11-08)
Code audited: OFast Pipeline main plugin, version 2.2.28, white label already removed (the "no whitelabel" zip), 145 PHP files, about 83,000 lines of PHP. Stages 1 to 7 are done. Nothing was changed in the code. File and line numbers are approximate and come from the working copy.
How to read: Critical means fix before anyone uses the plugin. High means fix before launch. Medium means fix soon. Low means tidy. Info means no action needed. Effort: small is under half a day, medium is one to two days, large is more than two days.

## 1. SHORT SUMMARY

### Scores out of 10
- Security: 6 out of 10. The basics are strong (every SQL spot I read uses placeholders, nonces on every admin action, hashed session tokens, bcrypt, login and OTP limits, private uploads with random names and file type checks, no eval or unserialize or turned off SSL). It loses points for: fake verification is open on your live Sandbox setup, a few public doors are weak (SMS status webhook with no secret, lead capture trusts a client id, receipt links use a shared nonce), credits can be overspent by parallel sends, and CORS is open on write routes.
- Quality: 5 out of 10. The syntax is clean on PHP 8.3 and most logic is sound, but I found 6 places that end in a fatal error, 4 money races (credits, offer acceptance, manual purchase, rent payment approval), wrong installment dates and frequency, and money that disappears on overpayment.
- Speed: 4 out of 10. This is an estimate from reading the code, not a measurement. The repair job and the setup checks run on every request, 3 indexes are missing, and public pages load Tailwind in the browser.
- Maintainability: 4 out of 10. 83,000 lines, 6 files over 1,500 lines (settings.php is 4,541), no autoloader, no automated tests, the offer acceptance exists in two places, there are layered patches and dead files, there is no database version, and 75,000 style errors.

### Top 10 things to fix first
1. P4.15: the login, signup, forgot password and reset password pages crash in the no whitelabel zip (4 deleted calls to print_host_head). Nothing else can be tested until this is fixed. Small.
2. P3.01: Sandbox or no key makes every Prembly check succeed. Anyone can get the Verified badge and open the Silver and Gold gate. Your live site is on Sandbox, so this is open today. Small.
3. P3.02 and P3.03: match the CAC company name to the signup business name, add a nonce, a daily limit and an "already verified" check on the verify action. Small to medium.
4. P4.01 with P6.02: the repair job (and 3 setup checks) run on every page load for every visitor. One ofp_db_version flag fixes most of it. Small to medium.
5. S2-01 with S2-02 and D-16: make credit deduction one atomic query and limit the channel to a fixed list, together with the voice removal (same code). Medium.
6. P4.02: two taps or two buyers can create two purchases for one property (offer path and manual path). Use an atomic claim and a row lock. Medium.
7. P4.25 and P4.24: accepted offers ignore weekly or quarterly frequency, and month end dates overflow (31 January becomes 3 March). Use one shared due date helper. Medium.
8. P4.21, P4.22, P4.23: rent payment approval can double fire or lose a payment, overpayments vanish, and late payers on expired leases are blocked. Medium.
9. S0-01, P4.16, P4.17, P4.19: four more crashes (client portal manual send, feedback, admin resend, receipt from the customers list). Small each.
10. S2-03, S2-05, S2-07: the SMS status webhook has no secret, lead capture trusts a client id, and public receipt links use a shared nonce. Small to medium.

## 2. FULL TABLE OF FINDINGS
IDs from earlier stages are kept (S1 and S2 from the first stages, S0 is the early note on the portal crash, P3 Prembly, P4 bugs, B5 D5 X5 cleanup, P6 speed, P7 and C7 Stage 7). "See" means merged into another row. Rows are grouped by area.

| ID | Severity | Area | File:line | Problem | Fix | Effort |
|---|---|---|---|---|---|---|
| P4.15 | Critical | Login | public/templates/login.php:170, signup.php:225, forgot-password.php:43, reset-password.php:57 | OFP_Brand::print_host_head() was deleted with white label, but 4 pages still call it, so they crash | Delete the 4 calls | small |
| P3.01 | Critical | KYC | includes/class-ofp-prembly.php:58-60,83,157,217 | Sandbox mode or no key returns success without checking anything. Opens the listing gate and the Verified badge for anyone | Simulate only with WP_DEBUG or a constant, else refuse; switch live site to a clear message until funded | small |
| P3.02 | High | KYC | prembly.php:307-322 | Real company number plus any real NIN or BVN passes; nothing ties them to the client | Compare CAC company name to signup business name, allow admin approval of near matches | medium |
| S2-09 / S1-08 / P3.03 | High | KYC | prembly.php:22-23,267 | No nonce, no rate limit, no already verified check, up to 2 paid calls per click, CSRF | Nonce, 3 tries per day per client, refuse if verified | small |
| S2-01 | High | Credits | includes/class-ofp-credit.php:90-113 | Balance check and deduction are separate, so parallel sends overspend | One UPDATE with a balance check, test rows_affected | small |
| P4.02 | High | Money | includes/class-ofp-property-commerce.php:220-300, purchase-service.php:40-130 | Offer and manual purchase checks run before the transaction with no lock, so a double tap makes two purchases | Atomic offer claim and SELECT FOR UPDATE on the property inside the transaction | medium |
| P4.25 | High | Money | commerce.php:355 | Accepted offer ignores the frequency and always builds a monthly schedule (the footer patch in purchase-service.php:335 never runs) | One shared due date helper used by both paths | medium |
| P4.21 | High | Money | includes/class-ofp-property-lease-payment.php:56-84 | Approving a rent payment is not atomic: double click fires events twice, parallel approvals lose one amount | Claim the row with UPDATE where status is pending, update lease in one query | medium |
| P4.01 / P6.01 | High | Speed | includes/class-ofp-property-commerce-repair.php:12,21-63 | reconcile_properties runs on every request, about 4 queries and a write per listing | Remove the init hook; run on save and once a day | small |
| P6.02 / P4.08 / P3.09 | High | Speed | commerce-migration.php:8, prembly.php:21, about 15 schema helpers | Setup queries on every request (5 SHOW COLUMNS, 3 INFORMATION_SCHEMA, CREATE TABLE) | One ofp_db_version check; remove per request ones | medium |
| S0-01 / P4.03 | High | Bug | public/class-ofp-client-portal.php:1040 | OFP_Activity_Log does not exist; manual send crashes after the message is sent | Use OFP_Logger | small |
| P4.16 | High | Bug | includes/class-ofp-feedback.php:214,327 | OFP_Notification::create called with an array instead of 4 arguments, fatal on PHP 8 | Pass 4 arguments | small |
| P4.17 | High | Bug | admin/class-ofp-admin-menu.php:1964 | OFP_Auth::current_admin_name() does not exist; admin resend crashes | Use current_user() name or add the method | small |
| P3.04 | Medium | KYC | prembly.php:279-290 | Raw cookie fallback accepts a stored session hash and lets team members verify the company | Delete fallback, use OFP_Auth::current_client() | small |
| P3.05 | Medium | KYC | prembly.php:297,332,336-353; admin-menu.php:757 | id_type and env not checked against lists; failed update still returns success | Allow nin or bvn and test or live; check the update result | small |
| P3.06 | Medium | KYC | prembly.php:113-141,176-201 | 20 second calls twice, raw provider text shown, outage treated as not found, CAC paid again on retry | 10 second limit, fixed messages, read response_code, cache CAC result | small |
| P3.07 | Medium | KYC | prembly.php:94,166,226 | Uses the older identitypass paths and body["data"]; current docs use /api/v1/verification/api/{service}/ | Test once with a funded live key, then adjust | small |
| S2-02 | Medium | Credits | credit.php:46,98 | Column name built from the channel with no whitelist | Allow a fixed list only (sms after D-16) | small |
| S2-03 | Medium | Webhook | public/class-ofp-rest-api.php:374 | SMS delivery webhook has no secret; anyone can change message status | Secret in URL or header | small |
| S2-05 | Medium | Leads | rest-api.php:~175 | capture-lead trusts a client_id from the public; spam into any CRM | Site key or signed token, Turnstile | medium |
| S2-06 | Medium | Uploads | public/templates/properties.php:~285 | Photo upload has no mime allow list | Allow jpg, png, webp, check size and count | small |
| S2-07 / S2-08 | Medium | Receipts | includes/class-ofp-property-receipt-viewer.php:161 | Public receipt link uses a shared WordPress nonce; no rate limit | Per receipt HMAC token with expiry, add rate limit | medium |
| S2-10 / S1-09 | Medium | Bank list | includes/class-ofp-bank-account.php:364 | No nonce, no rate limit, registered nopriv | Nonce, rate limit, drop nopriv | small |
| S2-11 | Medium | CORS | rest-api.php:63,670,680 | Allow Origin * also on write routes | Origin list per client for write routes | medium |
| P7.01 | Medium | Proxy | 9 files (funding.php:78,113,163, properties.php:85, property-offer.php:81 and others) | Raw REMOTE_ADDR in rate limits; behind Cloudflare everyone shares an IP | Use OFP_Security::get_client_ip() | small |
| P4.18 | Medium | Bug | admin/class-ofp-property-commerce-admin.php:258,262 | $offer_url never defined; buyer link not shown after creating an offer | Build the URL from the offer token | small |
| P4.19 | Medium | Bug | admin/views/customers-list.php:111 | Calls a private method (build_rent_data), fatal | Make it public | small |
| P4.20 | Medium | Bug | public/templates/forgot-password.php, reset-password.php, team-invite.php (line 1) | UTF-8 BOM breaks headers and cookies | Save without BOM | small |
| P4.22 | Medium | Money | tenant-transfer.php:92-105, lease-payment.php:75 | Several pending transfers can exceed the balance; the extra is cut off silently | Refuse or record as credit | medium |
| P4.23 | Medium | Logic | lease-payment.php:19, lease-reminders.php:20,59-63 | Expired leases cannot receive payment although reminders tell tenants to pay | Allow expired leases while balance is above 0 | small |
| P4.24 | Medium | Money | commerce.php:343,355, purchase-service.php calculate_due_date | 31 January plus 1 month becomes 3 March | DateTime with last day clamp | small |
| P4.26 | Medium | Money | commerce.php:327-366 | Installment inserts not checked; a failed insert still commits | Check each insert, throw to roll back | small |
| P4.04 | Medium | Setup | activator.php:38-60,119 | Core tables update only on activation; no db version | ofp_db_version check on plugins_loaded | medium |
| P4.05 / S1-03 | Medium | Cron | ofast-pipeline.php:150, cron-handler.php:78-79 | Monthly job drifts; strtotime last month is wrong on the 29th to 31st | Schedule the 1st each month; first day of last month maths | small |
| P4.06 | Medium | Cron | cron-handler.php:56-66 | 4 jobs in one hook with no try and catch | Separate hooks or try and catch | small |
| P4.07 | Medium | Logic | includes/class-ofp-property-installment-reminders.php:37-41,105 | Overdue notice only at exactly 1 day late; status may never turn overdue | Treat 1 or more days as overdue, repeat every 3 days | small |
| P6.03 | Medium | Speed | public/templates/property-marketplace.php:60-123, property-cpt.php:2403,2426 | Marketplace sorts and filters on postmeta; counts load all IDs; lists load all posts | Use the indexed ofp_properties table, COUNT(*), paging | medium |
| P6.04 | Medium | Speed | activator.php create tables | Missing indexes: clients.subdomain, communications_log type and created_at, leads client plus status | Add keys; cache the 3 counts for 5 minutes | small |
| P6.05 / X5.04 | Medium | Speed | marketplace, blog, agent profile, plans pages | Tailwind Play CDN script compiles in the browser | Build one static CSS file, self host Alpine | medium |
| P6.06 / X5.05 | Medium | Speed | assets/css/client-portal.css 301 KB and others | Big CSS and JS files, big inline scripts | Minify at release, check route splitting | medium |
| P6.07 | Medium | Speed | includes/class-ofp-sms.php:184,227,270 | OTP and manual SMS wait up to 15 to 30 seconds inside the page | 8 second limit and a message | small |
| P6.08 | Medium | Speed | 70 update_option calls, 33 with autoload false | Big options may load on every request | Check sizes on staging, set autoload false | small |
| P7.08 | Medium | Compat | activator.php | No multisite support or check | Stop on network activation, document single site | small |
| B5.01 / C7.02 | Medium | Bug | includes/class-ofp-property-cpt.php:293 | Reads ofp_company_account_number but it is saved as ofp_company_account_no | Use the saved name | small |
| S2-04 | Low | Webhook | voice webhook | No signature (file removed by D-16) | Goes away with D-16 | small |
| S2-12 / S1-04 | Info | REST | rest-api.php:93-122 | All routes public by design; checks must be inside callbacks | Covered by S2-03, S2-05 | small |
| S2-14 / S2-15 / S1-05 / S1-06 | Low | AJAX | admin-post and wp_ajax nopriv hooks | nopriv on login only actions (guarded inside) | Remove nopriv where clients become WP users | small |
| S2-16 | Low | Admin | admin-menu.php:1742,1756 | Super admin check before nonce; wp_redirect | Nonce first; wp_safe_redirect | small |
| S2-17 | Low | Crypto | includes/class-ofp-security.php:98 | AES CBC with no authentication | AES GCM or add HMAC | small |
| S2-18 | Low | Settings | admin/views/settings.php:116 | Decrypts a gateway key into the page | Mask it | small |
| S2-20 / S1-10 | Low | Files | Ofast_Pipeline_Universal_Receipt.html in plugin root | Reachable directly | Move into includes or deny direct access | small |
| S2-19 | Info | Guards | all PHP files | ABSPATH guard now present in every file (re checked) | None | small |
| S2-21 | Low | Includes | admin-menu.php:263-277 | Path comes from callers; the other variable includes checked safe | Confirm callers pass fixed names only | small |
| S2-13 / C7.02b | Low | Hook | admin/class-ofp-admin-menu.php:42 | admin_post hook with no handler | Remove add_action | small |
| P3.08 | Low | KYC | clients list, schema | Verified for ever, no revoke or hand approval, no reference stored | Admin revoke action, store reference | small |
| P3.10 | Low | KYC | prembly.php:23,296-298 | Useless nopriv hook; no wp_unslash | Fix both | small |
| P4.09 | Low | Uninstall | uninstall.php | Drop block commented out; table list out of date; no personal data erase | Real uninstall and erase hooks | medium |
| P4.10 | Low | Database | activator.php create tables | One space after PRIMARY KEY, dbDelta wants two | Two spaces, check debug log on staging | small |
| P4.11 / S1-11 | Low | Rewrite | property-cpt.php:122 | Two flush systems, CPT flushes before other routes exist | One central flag | small |
| P4.12 / S1-02 | Low | Cron | activator.php:706-707 | Activator makes the 5 minute event first (no double run) | Use 2 minute schedule directly | small |
| P4.13 | Low | Dates | many files | Mix of date, gmdate, wp_date, current_time timestamp | One helper with wp_date | medium |
| P4.14 | Low | PHP 8.2 | includes/class-ofp-import.php:119 | utf8_encode is deprecated | mb_convert_encoding | small |
| P4.27 | Low | IVR | includes/class-ofp-ivr.php:183 | Missing method in a dead file | Goes away with D-16 | small |
| P4.28 | Low | Leads | includes/class-ofp-lead.php:236-251 | Any text accepted as a status; notes lose writes and grow | Allowed status list; append in one query | small |
| P4.29 | Low | Rent | lease-reminders.php:20-63 | Lease turns expired only when a reminder is sent; fixed SMS cost 6.99 | Flip status in its own query | small |
| P4.30 | Low | Views | billing.php, property-billing.php, nav.php, signup.php:268 | Variables that might not be defined | Check, most go with strip | small |
| P6.09 | Low | Speed | includes/class-ofp-property-purchase-service.php:282,235,335 | Script written into every front end page; 2 more late patches (335 is dead) | Move into the one template, delete the dead patch | small |
| P6.10 | Low | Speed | commerce.php:505-530 | sync_purchase_totals loops all purchases | One grouped query | small |
| P6.11 | Low | Speed | includes/class-ofp-chat-service.php:42,325 | Chat widget runs on every front page | Check it exits early | small |
| P7.02 | Low | WP API | 8 wp_redirect, 7 strip_tags, 47 json_encode, 37 date | Not the WordPress preferred functions | Replace | small |
| P7.03 | Low | WP API | 12 template files | Hand written script and link tags | Static CSS build (X5.04) | medium |
| P7.04 | Low | Files | field-agent.php:516, manual-payment.php:657 | Private folders protected only by .htaccess | Install note for Nginx | small |
| P7.05 | Low | i18n | whole plugin | No textdomain loading, about 500 untranslated strings | Only if needed | large |
| P7.06 / S1-01 | Low | Version | ofast-pipeline.php:6,17 | Header 2.2.27, constant 2.2.28 | One source of truth | small |
| P7.09 | Low | Cache | public pages | Nonce pages safe only through the router; no DONOTCACHEPAGE | Add the constant | small |
| P7.10 | Low | Router | includes/class-ofp-host-router.php:174,307 | Stale custom domain comments; cookie shared by all subdomains | Update comments, test wildcard SSL | small |
| C7.01 | Low | Link | admin/class-ofp-property-commerce-admin.php:163,178 | Link to a wp-admin page that exists only in the portal | Remove or link to portal | small |
| C7.03 | Low | Setting | ofp_db_version | Saved, never read | Read it in P4.04 | small |
| D5.01 | Low | Dead | includes/class-ofp-property-payment-context.php | Never loaded | Delete | small |
| D5.02 | Low | Dead | includes/class-ofp-property-payment-entry-ui.php | Never loaded | Delete | small |
| D5.03 | Low | Dead | includes/class-ofp-ivr.php | Never loaded | Delete | small |
| D5.04 | Low | Dead | admin/views/property-billing.php | No file loads it | Delete after portal tab check | small |
| D5.05 | Low | Dead | assets/css/marketplace.css, assets/js/marketplace.js | Not referenced | Delete after a network tab check | small |
| D5.06 | Low | Dead | admin-url, brand, payment, subscription, commerce-repair | 7 methods nobody calls | Delete | small |
| D5.07 / C7.04 | Low | Dead | ofp_get_bank_list | AJAX action with no caller | Check then remove | small |
| X5.01 | Low | Duplicate | admin-menu.php:809-811 and :1427-1430 | Company bank saved by two handlers | Keep one | small |
| X5.02 | Low | Duplicate | 8 templates | ofpToggleTheme pasted 8 times | One JS file | small |
| X5.03 | Info | Duplicate | page shells (blog, agents, marketplace, signup, login) | 46 to 56 percent same lines | Shared partials later, not now | large |
| D-16 | Medium | Cleanup | about 45 files | Remove voice calls, voice scripts and IVR completely | Follow the list in the D-16 section | large |
| S1-12 | Info | Structure | whole plugin | No autoloader, 6 files over 1,500 lines, no tests | Split only when touching them | large |
| S1-13 | Info | Duplicate | receipt and payment record files | Looked like overlap; no real duplicates found beyond Stage 5 | None | small |
| S1-14 | Info | Shortcodes | none | None registered or used | None | small |


## 3. FIX PLAN IN PHASES (ordered so nothing breaks)
Rules for every phase: work on a staging copy with WP_DEBUG on and Query Monitor installed; one git branch per phase and one commit per finding ID; keep the frozen v2.2.28 backup untouched; run the matching part of the test checklist (section 5) after each step before starting the next; change the database only through the ofp_db_version step (2.3); do not reformat whole files.

### Phase 0 | Before any fixing (half a day)
- Freeze both zips as backups. Create the staging site (PHP 8.1 for one run and PHP 8.3 for another, WP 6.x latest). Record baseline numbers for speed (query count, page time) on the homepage, marketplace, login, dashboard and a property page.
- Reason: Phase 3 needs a before and after, and Phase 1 step 1 needs a place to test the login.

### Phase 1 | Critical and High security
1.1 P4.15: delete the 4 print_host_head calls. Do this FIRST. Without it you cannot log in to test anything else. Test: login, signup, forgot, reset pages open.
1.2 Prembly (P3.01 to P3.06): Sandbox and no key must refuse with "Verification is not available yet" (simulate only on staging with a constant). Add the nonce to the form and the JS call, rate limit (3 per day per client), refuse if already verified, whitelist id type and environment, remove the cookie fallback, match the CAC company name to the signup business name (admin can approve near matches), short time limits and fixed messages. Keep the form hidden until the Prembly wallet is funded. When funded: test P3.07 (paths and response shape) once with a live key.
1.3 Credits (S2-01, S2-02): one atomic UPDATE, fixed channel list. Do it here with sms and voice in the list; step 2.5 narrows it to sms.
1.4 Public doors: S2-03 (secret on the SMS status webhook, then set the same secret at the SMS provider), S2-05 (lead capture: site key or signed token plus Turnstile), S2-07 and S2-08 (per receipt signed token, rate limit), S2-10 (bank list nonce), S2-06 (photo types), S2-11 (origin list on write routes), P7.01 (use get_client_ip in the 9 places).
1.5 The small security tidy ups: S2-14, S2-15, S2-16, S2-17, S2-18, S2-20, S2-21 check, P7.04 install note.
Order reason: 1.1 unlocks testing; 1.2 closes the one hole that is open today; the rest can go in any order but all must pass test items 1 to 14 before Phase 2.

### Phase 2 | Bugs and data safety
2.1 Crash fixes (all small): S0-01, P4.16, P4.17, P4.18, P4.19, P4.20, S2-13, C7.01, B5.01. Test each screen listed in the checklist.
2.2 Money and data, in this order: (a) one shared due date helper (P4.24, P4.25) first, because the next step uses it; (b) P4.02 atomic claim and row lock in the offer path and the manual path, with P4.26 insert checks; (c) P4.21 rent approval, P4.22 overpayment rule, P4.23 expired lease payments; (d) P4.07 overdue reminders, P4.29 lease expiry. Test with two browser tabs clicking at the same time.
2.3 Database version (P4.04): add the ofp_db_version check, run create tables and the upgrade code on change, fix the dbDelta spacing (P4.10), add the real uninstall and the data erase (P4.09). This must come BEFORE Phase 3 indexes and before removing the per request migration code.
2.4 Cron (P4.05, P4.06, P4.12): month scheduling, separate hooks or try and catch, the 2 minute schedule in the activator.
2.5 Voice removal (D-16): after the money fixes are tested, so the credit tests only run once more. Finish with the search for voice, ivr, call_script, default_call, VOICE_COST, interest_call, tts returning zero hits, and narrow the credit channel list to sms.
2.6 Housekeeping: P7.08 multisite stop, P7.06 version, P4.14, P4.28, P4.30, X5.01.

### Phase 3 | Speed and indexes
3.1 Remove the per request work: the reconcile hook (P4.01/P6.01), the Prembly and commerce migration queries (P6.02, P4.08, P3.09). Requires step 2.3.
3.2 Indexes (P6.04): put them in the create tables and bump the db version so existing test sites get them. Cache the 3 communications counts.
3.3 Small speed items: P6.07 SMS time limits, P6.08 autoload sizes (run the query on staging), P6.09 footer script, P6.10 grouped query, P6.11 chat widget check, P4.13 date helper.
3.4 Front end weight: static Tailwind file and self hosted Alpine (P6.05, X5.04, P7.03), minify assets at release (P6.06), marketplace list from the indexed table (P6.03) when you have more than about 1,000 listings.
3.5 Measure again and write the numbers next to the Phase 0 numbers.

### Phase 4 | Clean up duplicate and dead code
4.1 Delete the dead files and methods (D5.01 to D5.07), after searching once more for each name. Keep the three "check first" items until the check is done.
4.2 Duplicates: one theme toggle file (X5.02), one handler for offer acceptance (the second copy in property-offer.php), update stale comments (P7.10).
4.3 Add phpcs.xml with Security, DB, PHPCompatibility and I18n groups only; format only the files you touched. Page shell partials (X5.03) and translation (P7.05) are optional and later.
4.4 Full retest of section 5, then create the white-label edition fork from the fixed main (D-13) and run a short re check on it.
Order reason: dead code is deleted last because deleting it changes no behaviour and is easy to verify once everything else is stable and tested; the fork inherits all fixes.

## 4. THINGS DONE WELL (leave alone)
- Database: every SQL statement I read uses $wpdb->prepare with placeholders; dynamic fragments (agents, leads, rest-api, inquiries) are built from fixed text and placeholders; table names always use $wpdb->prefix.
- Login and sessions: IP rate limit, 5 tries per login token, OTP with 5 tries, session token stored as a hash, cookie is HttpOnly and SameSite Lax (Secure on HTTPS), bcrypt passwords, safe redirect on login, Turnstile on login and signup.
- Admin actions: all admin_post actions go through require_admin_post (nonce plus plugin admin check); menus and views are only available to plugin admins.
- Uploads: receipts and field agent files are in private folders with random names, size limits and real file type checks (wp_check_filetype_and_ext).
- Secrets: API keys are encrypted at rest, only a new value is saved, shown masked, never sent to the browser; calls go to fixed HTTPS hosts with SSL checking on.
- Prembly: NIN and BVN numbers are not stored or logged; the result is decided only on the server; messages use textContent.
- Queue: each row is claimed atomically so a message cannot go out twice; retries limited to 3; stale rows are cancelled after 48 hours; UTC times used consistently.
- Money columns are DECIMAL(14,2); installment totals are checked against the balance on the manual path; offer expiry and property taken checks exist.
- Reminders: one reminder per day per installment; instant SMS is sent after the visitor has already received the page.
- Output: the samples of unescaped output I read were internal HTML; the communications feed escapes message text; JS uses textContent where it handles user text.
- WordPress use: wp_send_json_* (128), wp_safe_redirect (143), wp_enqueue (57), nonces, capability checks, no eval, unserialize, exec or turned off SSL; no shortcodes or plugin dependencies; PHP syntax clean on 8.3; no TODO notes and very little commented out code.
- Schema helpers for watermark, inquiries, rent, bank account, platform and communications log sit behind option flags and can run twice safely; the migration and repair classes are safe to run twice.
- The CORS header on non plugin REST routes is left to WordPress; only /ofp/v1 and the widget routes are open.

## 5. TEST CHECKLIST (follow after the fixes)
Use two browsers (one logged in as client, one private window as a visitor) and one staff account. Tick each line.

### A. Login and access (after Phase 1.1)
1. Open /login, /signup, /forgot-password and a reset link. Expected: every page opens with no error.
2. Log in with a right and a wrong password. Expected: right works; after 5 wrong tries you are blocked for a while.
3. Enter a wrong OTP 6 times. Expected: the login attempt is cancelled.
4. Log in with a redirect to an outside site in the address. Expected: you stay on your own site.
5. As a normal WordPress subscriber, open /wp-admin/admin.php?page=ofp-clients. Expected: not allowed.

### B. Verification (Prembly) (after Phase 1.2)
6. On the live site (Sandbox or no key), open Account and try to verify. Expected: a message that verification is not available; the badge does NOT appear.
7. On staging with the test constant on, verify with a company name that does not match the signup name. Expected: refused. With a matching name: verified.
8. Click verify 4 times quickly. Expected: after 3 tries you are blocked; no extra paid call.
9. Post the verify request from another site (a copied form). Expected: blocked (no nonce).
10. Try to publish a Silver or Gold listing without verification. Expected: blocked.

### C. Public doors (after Phase 1.4)
11. Post a fake SMS status to the webhook without the secret. Expected: refused. With the secret: accepted.
12. Submit a lead to a client you do not belong to without the site key or Turnstile. Expected: refused.
13. Open a public receipt link, wait for the expiry or change one character. Expected: refused; 30 quick opens are limited.
14. Upload a zip or pdf as a property photo. Expected: refused. Open the rate limit page from behind Cloudflare. Expected: limits count each visitor by the real IP.

### D. Crash fixes (after Phase 2.1)
15. Client portal: send a manual SMS and a manual email to a lead. Expected: no error page; the message shows in the log.
16. Submit feedback and answer it from admin. Expected: no error; a notification arrives.
17. In wp-admin, resend a communication. Expected: works.
18. Create an offer in wp-admin. Expected: the secure buyer link is shown.
19. Open a rent receipt from the admin customers list. Expected: opens.
20. Check the property editor: the company bank account number is shown (not a dash).

### E. Money and data (after Phase 2.2)
21. Accept the same offer twice quickly (two tabs). Expected: one purchase only.
22. Two buyers accept two offers on one property at the same moment. Expected: one purchase, the other is told the property is taken.
23. Create offers with weekly, monthly and quarterly frequency with a start date of 31 January. Expected: dates match the frequency and every date is a real month end or the same day, never 3 March.
24. Click Approve on one rent payment twice quickly. Expected: counted once; one receipt and one SMS.
25. Approve two payments for the same lease together. Expected: both are counted and the balance is right.
26. Send two transfers that together are more than the balance. Expected: refused or the extra is clearly shown as credit.
27. Let a lease expire and pay the late balance by transfer. Expected: accepted.
28. Send 5 SMS at the same moment with credit for only 3. Expected: only 3 are sent, the balance never goes below 0.
29. Skip the cron for 3 days, then run it. Expected: the overdue notice is sent and the installment is marked overdue.
30. Run the monthly archive on the 31st. Expected: it archives the right month.

### F. Updates and removal (after Phase 2.3 and 2.5)
31. Replace the plugin files on a test site that has the old tables. Expected: the new columns and indexes appear without deactivating the plugin.
32. Search the whole code for voice, ivr, call_script, default_call, VOICE_COST, interest_call and tts. Expected: no hits.
33. Open Settings, Funding, Credits, Billing, Message templates and the signup and plans pages. Expected: no word about voice and no error.
34. Delete the plugin on a test site. Expected: the tables and options are removed (if you chose full removal); a data erase request removes a client.

### G. Speed (after Phase 3)
35. With Query Monitor, open the homepage, marketplace, login, dashboard and a property page with 300 listings. Expected: far fewer queries than Phase 0, no query that runs once per listing, no slow query in the list.
36. Open the marketplace on a phone with a slow connection. Expected: the page is styled on first paint (no unstyled flash).
37. Open the admin Communications page. Expected: loads fast; counts are cached.

### H. Clean up (after Phase 4)
38. Walk through every menu item in the admin portal and wp-admin. Expected: no page says "not allowed" or "being built".
39. Turn WP_DEBUG on and click through the whole client portal. Expected: nothing in the debug log.
40. Install on PHP 8.1 and PHP 8.3. Expected: both work.
41. Install and activate on a multisite test. Expected: a clear message that only a single site is supported.

## 6. WHAT I COULD NOT CHECK, AND WHY
- Real running behaviour. I did not run WordPress or a database here. Everything is from reading code, a PHP 8.3 syntax check, PHPStan and PHPCS. Query counts and speed are estimates, not measured. Query Monitor on staging is needed (Phase 0).
- PHP 8.1 and WordPress 6.0 runs. I only ran PHP 8.3. I found no feature that needs more than 8.1 or WP 6.0, but it is not tested.
- PHP warnings at run time (undefined index, null values) except those PHPStan found. A staging run with WP_DEBUG on is needed (test 39).
- Prembly in live mode. The account is unfunded, so I could not test the real endpoints (P3.07).
- The payment gateway code (Paystack and the gateways folder) and the Paystack webhook, because you set them out of scope (D-07). Money checks there are NOT covered.
- The white-label edition fork. It does not exist yet; it needs its own short re check (D-13).
- Output escaping: PHPCS flagged 201 spots; I read 40. The other 160 were not read one by one. Also about 80 places in JS that put server HTML into the page: I read the communications one and the import one only.
- The 60 or so templates and views were not read line by line (settings.php alone is 4,541 lines). I read the parts that handle forms, money, access and the places tools pointed to.
- Lead status flow beyond update_status, the rent and lease flow beyond payments, reminders and transfers, and the investment module were not read in full.
- The email sending part (SMTP) and the SMS providers (SmartSMS, Africa's Talking): only time limits and call sites. I did not test delivery.
- Real hosting: Nginx rules, Cloudflare wildcard SSL, caching plugins (WP Rocket, LiteSpeed Cache), page builders and other plugins running together. I only read how the code behaves.
- Accessibility, the visual design and the browser compatibility of the pages.
- The size of the options in your database (P6.08) and the real sizes of CSS when minified.
- There are no automated tests in the plugin (no tests folder), so I could not run any.
- Dates and the "approximate" line numbers: line numbers came from the working copy and may shift after the voice removal.


## CROSS-CHECK OF STAGES 2-6 (Claude, 2026-11-09) - what the report above did not have
Method: I read the whole report first, then re-opened the code for its main claims and looked for what it missed. No file was changed. No staging run was possible, so nothing here is a measured time.

### A. Claims I re-checked in the code - all CONFIRMED
- P3.01 (test mode and "no key" return success), P4.01 (reconcile_properties on every init), P4.16 (Notification::create called with one array, 4 args needed), P4.17 (current_admin_name missing), P4.19 (build_rent_data is private), P4.20 (BOM in forgot-password, reset-password, team-invite; login and signup have none), D5.01-D5.03 (never required), D5.05 (no reference to marketplace.css/js), X5.04 (Tailwind CDN in 5 files).
- P4.15 is REAL and it is my own mistake: I deleted OFP_Brand::print_host_head() during the white-label removal and left 4 calls to it (login.php:170, signup.php:225, forgot-password.php:43, reset-password.php:57). The no-whitelabel zip I sent you cannot show login or signup. Do not install that zip until P4.15 is fixed (delete the 4 lines). Waiting for your OK.
- Full scan of every `OFP_Class::method()` call and every hook callback in the code: the only missing methods are the ones already in this report (print_host_head, current_admin_name, online_payments_enabled, record_ivr_response, voice_ivr_webhook, handle_update_client). Nothing else is hidden.

### B. Corrections to the report above
- Stage 2 in this file stops at S2-21. My second pass (S2-22 to S2-29) is not in it. Added in section F below.
- P6.04 says the admin pages in communications-log.php run 3 COUNT queries. That view is never loaded (see D5.08). The live page is communications.php:88-90. The index advice still stands.
- Stage 2 close-out says OTP "5 tries per token. OK". True, but an attacker can restart the login to get new tries (see S2-23, Low because the password is needed first).

### C. New findings - Prembly (Stage 3)
- G3.01 | High (decision) | The fix you chose for P3.02 (CAC name must match the business name typed at signup) is weaker than it looks: the person signing up types both. A stranger can sign up as "Dangote Homes", enter Dangote's public RC number, and the names match. What it really proves is "a company with this name exists". The real link is the NIN/BVN: its holder name must match the owner_name AND be a director/proprietor of that CAC record (Prembly has advanced CAC data), or use face match. Please decide before P3.02 is coded.
- G3.02 | Medium | prembly.php:83,157,217,330 | Test-mode "verifications" are saved like real ones: is_verified = 1 and verified_company_name = "VERIFIED ENTERPRISE (TEST RC ...)". The `simulated` flag is returned but never used or stored. When you switch to Live those accounts stay verified forever. Fix: do not save simulated results, and at go-live run a reset. Check now on your live site: SELECT id, business_name FROM wp_ofp_clients WHERE verified_company_name LIKE 'VERIFIED ENTERPRISE (TEST RC%';
- G3.03 | Medium | prembly.php:296-346 | One CAC number can verify many accounts. No uniqueness check on cac_number. Fix: refuse when the RC is already on another verified client (admin can override).
- G3.04 | Low | properties.php:104 | The verified rule is only checked in the template's save branch, and only for Silver/Gold. Listings already live stay live after a plan change or a future revoke. Decide: should Free also need it, and should an admin revoke hide the client's listings.

### D. New findings - bugs, money and the field agent module (Stage 4)
- G4.01 | High (money) | includes/class-ofp-payment.php:155-182 | Credit top-up can be applied twice. confirm_credit_topup reads "already processed", then credits, then inserts the transaction row, with no lock. ofp_credit_transactions has no unique key on reference (activator.php:387-398). Two callers can run together: the Paystack webhook (gateway-paystack.php:136) and the return page check (payment.php:494). Both pass the check, the client gets double credit. Fix: UNIQUE KEY (reference, type), insert the transaction row FIRST, credit only if the insert worked. Do it in the same step as S2-01 (credit race) and the voice removal (D-16), so credits are tested once.
- G4.02 | Medium (privacy) | includes/class-ofp-field-agent.php:399-412 | remove_agent keeps the agent's ID photo and selfie files forever (ofp-private/fa). These are personal documents (NDPR). Fix: delete the files when an agent is removed or rejected, and add a retention rule.
- G4.03 | Low | field-agent.php:973-1000 | decide() reads status 'submitted' then writes later with no "AND status='submitted'" in the update. Two reviewers can approve the same listing at once (double email, double log). Fix: claim with a conditional UPDATE first.
- G4.04 | Low | field-agent.php:677 | No daily cap or rate limit on agent submissions (each one creates a post, up to many photos, and a YouTube check). Add a per day limit per agent.
- Field agent module - done well (this module was not read in the report above): every form has a nonce; agent can reach one page only; agents are blocked from all ofp_ AJAX; agent area, ownership of edits and "profile approved first" are checked on the server; ID files are stored under random names with deny rules, mime-checked, served only to reviewers, and every view is logged; YouTube link is rebuilt from the 11 character ID; sale and land listings need owner or Manager approval.
- G4.05 | Low | client-portal.php:771-772, 808 | Team member role name and permission values are free text with no allow-list. Not exploitable today (field agent and approve rights also need the platform account), but add an allow-list.
- G4.06 | Low | includes/class-ofp-property-my-payments.php:82-107 | The buyer "my payments" session is a signed cookie that cannot be cancelled on the server. Logout only deletes the browser copy; a copied cookie works until it expires. Fine for now, add a server side revoke later.

### E. New findings - duplicate code, speed, extras (Stages 5 and 6)
- D5.08 | admin/views/communications-log.php | Never loaded by any menu, tab or include. Dead view. Remove with D5.04.
- G6.01 | Low | my-payments.php:127,143 | `LIKE '%' . phone` cannot use an index (full table scan on purchases and tenants). Fix: store the last 10 digits in an indexed column and compare with =.
- G6.02 | Low | property-cpt.php:1750 | Another posts_per_page = -1 (the report lists 2409, 2432, agent-profile and repair). Add paging.
- G6.03 | Medium | 8 public templates | Google Fonts load from fonts.googleapis.com in 8 files, GSAP from cdnjs on the pricing page, Alpine 3.13.3 from jsDelivr in 5 files. 10 outside script tags have no integrity (SRI) hash. Besides speed, every visitor IP goes to Google (NDPR note). Fix with P6.05: self-host fonts, Alpine and GSAP, pinned versions.
- G6.04 | Low | many files | About 8 of 18 wp_remote_* calls have no explicit timeout (quick scan, confirm each). WordPress default is 5 seconds, but set it on purpose.
- G2.01 | Medium | includes/class-ofp-client.php:180-211 | The plain text temporary password of every new client is kept 1 hour in a transient (a normal row in wp_options) so the admin can read it on the client page, and it is also emailed in plain text. A database copy or backup taken within that hour exposes it. Fix: send a "set your password" link (the reset token code already exists) and delete the transient.

### F. My Stage 2 second pass (not in the report above)
- S2-22 [closed] All 10 variable includes use fixed paths - safe.
- S2-23 [Low] verify_otp has no counter of its own. Add 5 tries per contact, lock 15 minutes.
- S2-24 [Low] Signup and forgot password OTP only have IP limits.
- S2-25 [Low] Session lasts 7 days; token hashed, cookie flags good. Consider shorter for staff and a log out everywhere button.
- S2-26 [Low] innerHTML with unescaped text: properties.php:2276 (custom amenity), feedback-list.php:533/537, import.js:15. About 74 uses in total; the rest paste server-escaped HTML.
- S2-27 [OK] login redirect_to uses wp_safe_redirect.
- S2-28 [Low] CSV/XLSX import is well guarded (nonce, caps, NONET, formula safe export). Still open: check the unzipped size of the XLSX parts (zip bomb).
- S2-29 [OK] bcrypt passwords, hashed reset tokens, IP limits on login and reset.

### G. Updated order for the fix plan (changes to the report above are marked NEW)
1. P4.15 (my regression, 4 lines) - first, before anything is installed.
2. Money, in ONE step, tested once: S2-01 credit race + G4.01 double top-up (NEW) + P4.21 rent approve + D-16 voice removal from credits.
3. Prembly: P3.01, P3.03, P3.05 now; P3.02 after you decide G3.01 (NEW); add G3.02 (NEW, reset test verifications) and G3.03 (NEW).
4. Fatals: S0-01, P4.16, P4.17, P4.19, P4.20, S2-13.
5. Speed: P6.01 + P6.02 + P4.04 together; then indexes P6.04; then P6.05 + G6.03 (NEW).
6. Privacy: G2.01 (NEW) and G4.02 (NEW).

## Stage 3-6 CROSS-CHECK STATUS: DONE 2026-11-09. Nothing else found missing in the report above beyond sections C to F. Next: Stage 7 (WordPress standards and connectivity), then the final report.


## PROPERTY TYPE AND LOCATION - TWO SYSTEMS (Claude, 2026-11-09) [BOTH editions]
- G7.01 | Medium (design + SEO) | includes/class-ofp-property-cpt.php:137-160, admin/class-ofp-admin-portal.php:251-254, public/templates/properties.php:159-162, includes/class-ofp-field-agent.php:802-805, public/templates/property-marketplace.php:36-52 | The plugin keeps property type and location in TWO places. (1) Taxonomies ofp_property_type and ofp_property_location: only the admin portal fills them. (2) Post meta ofp_property_type and ofp_location_text: the client form, the field agent form, the marketplace filter, cards and the single page all use this. So the taxonomies are almost always empty, but they are public (public=true) with web addresses /property-type/... and /property-location/..., so WordPress can list them in its sitemap as empty or thin pages. This is the "useless" duplicate you noticed.
- G7.02 | Medium (SEO + speed) | properties.php:1440, property-marketplace.php:48 | Location is ONE free text box ("Lekki Phase 1, off Admiralty Way, Lagos"). The marketplace filters it with LIKE on post meta. Result: "Lekki", "lekki phase 1" and "Lekki, Lagos" are three different places, no clean "Houses for rent in Lekki" page can be built, and the filter cannot use an index. The schema on the single page puts this whole text in addressLocality (single-detail.php:195).
- G7.03 | Low (SEO) | property-marketplace.php:142 | Marketplace page title comes from wp_title and has no description, canonical or list schema. Filter pages made with query strings (?type=...&location=...) would be seen as many copies of one page.
- G7.04 | Decision (owner) | Recommended: ONE source of truth = taxonomies, not meta. Property type = taxonomy ofp_property_type. Location = taxonomy ofp_property_location made hierarchical (State > Area). Keep a separate plain text field "Street address" (not used for filtering). Listing type (sale, rent, shortlet, commercial, land) stays as meta. Then: (a) the client form, import and REST set terms on save; (b) marketplace uses tax_query (indexed); (c) taxonomy pages become real SEO landing pages (own title, description, canonical, list schema), e.g. /property-location/lekki/; (d) pages with no listings get noindex; (e) marketplace filter pages use a canonical to the clean page. No live clients (D-03), so no data migration. Do NOT use a post type for these: type and location are labels on a listing, not content of their own. FIELD AGENT is NOT touched by this (see D-17): its own area list (ofp_fa_areas) and its save path stay as they are until the owner finishes building it; it is joined to the new system later.
- G7.05 | Design | How clients get a type or area the admin did not add: (1) Property type: today the client form uses a fixed list written inside properties.php:1092 (not the backend list). Move it to the taxonomy, seeded with the standard types. A client who does not find theirs picks "Other" and types it; it is created as a pending term (shown on their listing at once, admin approves, renames or merges). (2) Location: State is a fixed list (36 states + FCT, seeded). Area is a text box with suggestions from existing areas under that State. Typing a new area creates it automatically under that State (matched ignoring capital letters and spaces, so "lekki" and "Lekki" are one). New areas are marked "unreviewed" so admin can merge or rename them. Limit new areas per client per day to stop spam. (3) The plugin already has this pattern for amenities (ofp_add_custom_amenity, properties.php:2262); reuse it. (4) Street address stays free text and is never turned into a place automatically (no address reader without a paid map service).

## DECISION D-17 (2026-11-09): FIELD AGENT MODULE IS FROZEN [MAIN only]
Owner: the field agent module is still being built. Nothing in it may be fixed, changed or joined to other code until the owner says it is finished. Applies to includes/class-ofp-field-agent.php, public/templates/field.php and field-team.php, the field agent assets, and its tables (ofp_fa_*). Audit items G4.02, G4.03, G4.04 and G4.05 (field agent parts) are ON HOLD and are NOT in the fix plan. Any fix that would touch these files (for example the unified type/location change) must leave them alone and be joined later. White-label removal and other cross-cutting edits must not edit them either; the previous scan only READ them.


## DECISION D-18 (2026-11-09, FINAL): ONE PRODUCT = THE WHITE-LABEL EDITION. NO LICENSING.
Owner's words, now recorded exactly: white-label and "licence" are the SAME thing. There is no separate licence product and no licensing system. The owner sells the white-label plugin to individual real estate companies that do not want a subscription and want full branding and full control. They pay ONCE and own it.
- No licence keys, no activation, no "phone home", no expiry, no recurring check. Do not build any of it.
- The white-label edition is the plugin with everything the buyer does not need stripped out. It keeps ONLY: dashboard, marketplace, single property page, and the APIs (the APIs come with the dashboard, so the buyer can show properties on their main domain and add a lead form).
- The owner's header and footer are removed. The buyer adds their own.
- Stripped out for the buyer (this is the list earlier notes called "licence strip"): subscriptions, credits, funding, pricing and plans, payment gateway, voice/IVR, Prembly, AI chat/Telegram (a WhatsApp button instead), admin portal, field agent, the owner's public pages other than marketplace and single property. Manual payments only; buyer uses own SMTP and SMS keys; wp-admin has only the Settings page.
- Setup: the buyer has their own domain; the owner creates a subdomain for them and installs the plugin.
- Wherever older notes in this file say "licence edition", "licence fork", "LICENCE-STRIP" or "licence strip", read: WHITE-LABEL EDITION (white-label strip). The words were renamed below.
- Still to confirm with the owner when the build starts: (1) hosting - the buyer's server or the owner's; (2) header/footer - a setting that prints a bare page, or content wrapped by the buyer's theme.
- Effect on the audit: because the APIs stay, S2-05 (capture-lead trusts a client_id from the public), S2-11 (CORS * on write routes) and S2-12 (all REST routes public) must be fixed first: allow only the buyer's main domain and protect the lead form with Turnstile or a signed key. S2-03 and the lead spam limit also apply.
- Build base: the fixed main plugin (not v2.2.28), after the fix phases. Copy it to a new folder and strip it. D-15: the White-Label tier and comparison are restored on the owner's pricing page, reworded to say: one-time payment, own domain, full branding and control.

## WHICH CODE TO FIX (2026-11-09)
Work base = ofast-pipeline-no-whitelabel.zip (v2.2.28 with white-label removed). The original ofast-pipeline-v2.2.28-frozen.zip is only for comparing. First change of the fix chat must be P4.15 (the no-whitelabel zip cannot show login or signup until it is fixed). Field agent files are frozen (D-17).


## OWNER NOTES 2026-11-09 (admin portal in white-label, voice, SMS price)
- D-14 UPDATE (open, owner to decide): The admin portal (class-ofp-admin-portal.php 618 lines + admin-portal-template.php 1,945 lines) is the owner's modern admin dashboard with about 28 tabs. Most tabs manage MANY companies (clients, billing, funding requests, property-billing, manage-admins, investment, send-notification, cross-client reports, triggers, feedback). A single white-label buyer is one company and already has the client dashboard (leads, properties, customers, team, communications, sales). The only buyer need the client dashboard does not cover is SETTINGS (SMTP and SMS keys, branding) and, if G7 is adopted, the property TYPE and LOCATION lists. Settings already lives as a tab inside the portal (template line 802, settings.php 4,535 lines). Two options for white-label: (A) strip the portal and keep only wp-admin Settings (the earlier plan; least code, but the buyer sees the WordPress admin look); (B) keep a SLIM admin portal with 3 tabs only (Settings, Property types, Locations) and strip the other tabs (same modern look, buyer never goes to wp-admin, a little more code to carry). Claude's view: B fits the owner's wish to stay in his own dashboard; the extra code is small once the other tabs are cut. Owner to decide when the white-label build starts. The MAIN plugin keeps the full portal either way.
- D-16 CONFIRMED (BOTH editions): voice calls, call scripts and IVR are removed from MAIN completely, so white-label gets no voice either (it is copied from fixed MAIN). Same as SMS: whatever is removed or changed in MAIN is already removed or changed in the white-label copy. The D-16 list stands as written. The text "NGN 15.00 per voice minute" (clients-list.php:408, funding.php:353) goes with it.
- D-19 | SMS PRICE = NGN 10 per SMS (owner, 2026-11-09). The code still says 6.99.
- G8.01 | Medium (bug, money) | SMS price 6.99 is typed into the code in 6 separate places instead of one: includes/class-ofp-queue.php:38 (SMS_COST), class-ofp-property-comms-bridge.php:5, class-ofp-property-installment-reminders.php:6, class-ofp-property-lease-reminders.php:18, class-ofp-team-member.php:63 (fallback), class-ofp-sms.php:151 (fallback). It is also typed into 5 screens/texts: admin/views/clients-list.php:408, public/templates/funding.php:353, credits.php:133 (message count = balance / 6.99), message-templates.php:598 (cost estimate in JavaScript), and the comment in class-ofp-credit.php:9. Changing the price in one place leaves the others wrong, so clients would see one cost and be charged another. Fix (one step, together with the credit work in Phase 3): ONE setting ofp_sms_price (default 10.00, editable in Settings) read by ONE function (for example OFP_Credit::sms_price()); all 6 code places and 5 texts call it; the JavaScript gets it from the page. Also decide: is NGN 10 per SMS PART (a long message uses several parts, as the code does now: cost x segments) or per message? Ask the owner.
- Note for white-label: credits and SMS prices are stripped there (buyer pays their own SMS provider), so none of G8.01 is carried into it.
- P4.29 UPDATE: the fixed 6.99 mentioned there is part of G8.01.


## OWNER DECISIONS 2026-11-09 (second set)
- D-14 RESOLVED = option A: the admin portal is STRIPPED from the white-label edition (less code). The buyer's Settings use the normal WordPress admin page; the owner will fine-tune the WordPress admin look with snippets. MAIN keeps the full portal. If G7 is adopted, property types and locations in white-label are managed from the normal WordPress admin screens (the taxonomy pages), so nothing extra is needed.
- D-19 CLARIFIED: SMS price = NGN 10 per SMS PAGE (not per whole message). A page is 160 characters when the text is plain (GSM). A longer message is split into pages of 153 characters (the code already does this in OFP_SMS::segments, class-ofp-sms.php:97). Owner thought 140: 140 is the size in BYTES (140 bytes = 160 seven-bit characters); customers count characters, so 160 is right.
- G8.02 | Medium (money + bug) | includes/class-ofp-sms.php:97-103 | segments() always assumes 160 characters per page. A message that has any character outside the plain SMS set switches to Unicode and holds only 70 characters per page (67 when split). The Naira sign is one of those characters, so every payment, installment and rent reminder that writes the amount with the Naira sign is billed as 1 page but costs the provider 2 to 3. Same for emoji, curly quotes and letters with accents (Yoruba names such as "Olabode" are fine, "Ṣ" and "ọ" are not). Result: the client is charged less than the real cost, or the message is cut. Fix: count by the real rule (plain set = 160/153, otherwise 70/67), show the page count in the message box, and write amounts as "NGN" in system SMS (or convert the Naira sign). Do together with G8.01.


## FIX LOG
- 2026-11-09 | P4.15 FIXED | Removed the 4 calls to OFP_Brand::print_host_head() (public/templates/login.php, signup.php, forgot-password.php, reset-password.php). Checked: PHP syntax check on all 145 PHP files = 0 errors; no reference to print_host_head left anywhere; scan of every OFP_ static call and hook callback finds only the already listed pre-existing missing methods (current_admin_name, online_payments_enabled, record_ivr_response, voice_ivr_webhook, handle_update_client). LINTED ONLY, needs a staging test: open /login, /signup, /forgot-password and a reset link.
- 2026-11-09 | White-label removal CLOSED | Last leftovers were 2 help texts in admin/views/settings.php (watermark text and Turnstile hint) that still talked about a white-label agency name and a client custom domain. Reworded. Search for OFP_White_Label, is_white_label, custom_domain, wl_billing, whitelabel, white_label in all PHP and JS = 0 hits. Kept on purpose: the "Admin Bar & White-Labeling" card (it is about the WordPress admin bar), a comment in message-templates.php, a note in activator.php, and unused database columns on old installs. New zip: ofast-pipeline-no-whitelabel.zip (rebuilt 2026-11-09 03:02).


## STAGE 7 CROSS-CHECK (Claude, 2026-11-09)
Verified in the code and CONFIRMED: P7.01 (9 raw REMOTE_ADDR uses; get_client_ip itself is sound: trusts proxy headers only from private ranges, Cloudflare ranges or the ofp_trusted_proxies option), P7.04 (6 .htaccess writers, Apache only), P7.05 (no textdomain loading, no languages folder), P7.06 (header 2.2.27 vs constant 2.2.28), P7.08 (no is_multisite anywhere), P7.09 (0 DONOTCACHEPAGE, 16 nocache_headers), P7.10 (cookie domain ".basedomain", auth.php:283-293, 744-753), C7.01, C7.02 (settings read ofp_company_account_number, saved as ofp_company_account_no). Also confirmed good: deactivation clears cron events; error_log lines do not print emails or secrets. Not re-run by me: the PHPCS numbers in 7D (I accept them; I agree with the advice not to reformat the code base).
New in Stage 7, not in the report:
- G9.01 | Low-Medium | includes/class-ofp-property-my-payments.php:178 and includes/class-ofp-property-tenant-transfer.php:208 | Receipt and lease-proof links are built from the raw HTTP_HOST header (no check), and the link carries a nonce. If a request arrives with a forged Host (open default site on the server, or cache poisoning) the link, nonce included, points at the attacker's domain. Fix: accept the host only when it equals the site host or ends with .{ofp_crm_base_domain}; otherwise use admin_url().
- G9.02 | Low | includes/class-ofp-auth.php:293,753; my-payments.php:108 | Cookie Secure flag and https links use is_ssl(). Behind Cloudflare in "Flexible" mode the server sees http, so is_ssl() is false: login cookie is sent without Secure and links are http. Fix: use Cloudflare "Full (strict)" and, if needed, a wp-config line that trusts X-Forwarded-Proto; test on staging with the real setup.
- G9.03 | Low (deployment) | queue, reminders, cron-handler | Reminders and the message queue run on WP-Cron, which only fires when someone visits. A quiet buyer site sends late. Fix (install note): disable WP-Cron and add a real server cron every 1 to 5 minutes calling wp-cron.php (LiteSpeed instant send already helps).
- G9.04 | Low | ofast-pipeline.php header | No "Update URI:" header. WordPress can look up the slug "ofast-pipeline" on wordpress.org and offer an update from a different plugin with the same slug. Fix: add `Update URI: false` (WP 5.8 and later) or the real update address.

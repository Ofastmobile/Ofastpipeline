# FIXLIST.md - what to fix (compact). Updated 2026-11-09
Use this file, NOT the full AUDIT.md. AUDIT.md is the reference: when you need the detail of one ID, run `grep -n "ID" AUDIT.md` and read only that part. Do not read AUDIT.md from top to bottom.

## HOW A FIX SESSION WORKS
1. Owner uploads: latest master zip (ofast-pipeline-no-whitelabel.zip), FIXLIST.md, FIXLOG.md, and says the IDs to fix (for example "fix Phase 1.2").
2. Open ONLY the files named in those rows. Read a function before changing it. One ID per change.
3. After the work: PHP syntax check on every PHP file, scan for undefined classes/methods, search for names that must be gone, then update FIXLOG.md (ID, DONE or TODO, files touched, rules) and rebuild the zip.
4. Say "linted only" or "needs staging test". Never say tested if it was not run in WordPress.
5. Stop and wait for the owner's "continue". Ask before any database change, file deletion or visible behaviour change.

## FROZEN - DO NOT TOUCH (decision D-17)
Field agent module: includes/class-ofp-field-agent.php, public/templates/field.php, field-team.php, field agent assets, tables ofp_fa_*. Rows G4.02-G4.05 and the field-agent part of P7.04 are ON HOLD. If a fix needs these files, stop and ask.
Out of scope: payment gateway internals (includes/gateways/*, Paystack code). Credit logic IS in scope.

## OWNER DECISIONS ALREADY MADE
D-14 admin portal stays in MAIN, stripped from white-label. D-16 voice/IVR/call scripts removed completely from MAIN (and so from white-label). D-17 field agent frozen. D-18 white-label = the stripped edition sold once, built AFTER the fixes from the fixed MAIN; no licence keys. D-19 SMS = NGN 10 per SMS page (160 plain characters per page, 153 when split). Order: fix MAIN, then copy and strip it for white-label.

## OWNER DECISIONS STILL OPEN (ask when you reach the row)
G3.01 how strict Prembly matching is (name match alone is weak). G7.04/G7.05 type and location design (taxonomy as single source). White-label build: hosting place; header/footer method.

## RULES THAT MUST NOT BE UNDONE
- Always use OFP_Security::get_client_ip(), never $_SERVER['REMOTE_ADDR'] (P7.01).
- Money changes are atomic: one conditional UPDATE and test rows_affected; no check-then-write (S2-01, G4.01, P4.02, P4.21).
- SMS price comes from ONE setting/function, never typed in code or JS (G8.01). Count SMS pages by the real rule (G8.02).
- Credit column names come from a fixed list only (S2-02).
- Every public action needs a nonce or signed token, a rate limit, and an ownership check on the row.
- No schema work on every request; schema changes only behind ofp_db_version (P4.04).
- No voice, ivr, call_script, VOICE_COST, tts anywhere after D-16.
- Do not reformat whole files; do not touch style outside the lines you fix.
- Do not rename the admin-bar "White-Labeling" settings card (it is about the WordPress admin bar).

## DONE
P4.15 (2026-11-09: 4 print_host_head calls removed; linted only). White-label removal from MAIN: closed.

## PHASES (IDs in order)
Phase 1.1 DONE (P4.15). 
Phase 1.2 Prembly: P3.01, G3.02, P3.03/S2-09, G3.03, P3.04, P3.05, P3.06; then P3.02 after G3.01 is decided; P3.07 only with a funded live key.
Phase 1.3 Money, one step, tested once: S2-01, S2-02, G4.01, G8.01, G8.02, P4.02, P4.26, P4.24, P4.25, P4.21, P4.22, P4.23. (Voice stays in the channel list until D-16 step.)
Phase 1.4 Public doors: S2-03, S2-05, S2-07/08, S2-10, S2-06, S2-11, P7.01, G9.01, G2.01.
Phase 1.5 Small security: S2-14, S2-15, S2-16, S2-17, S2-18, S2-20, S2-21, S2-23, S2-24, S2-26, S2-28, G9.04, P7.04 (install note only).
Phase 2.1 Crashes: S0-01, P4.16, P4.17, P4.18, P4.19, P4.20, S2-13, C7.01, B5.01.
Phase 2.2 Database version: P4.04, P4.10, P4.09 (before any speed work).
Phase 2.3 Cron: P4.05, P4.06, P4.12, P4.07, P4.29, G9.03 (note).
Phase 2.4 Voice removal D-16 (narrow credit channels to sms).
Phase 2.5 Housekeeping: P7.08, P7.06, P4.14, P4.28, P4.30, X5.01, G3.04 (decide).
Phase 3 Speed: P4.01/P6.01, P6.02/P4.08/P3.09, P6.04, G6.01-G6.04, P6.07-P6.11, P4.13, then P6.05/X5.04/P7.03/G6.03, P6.06, P6.03.
Phase 4 Clean up: D5.01-D5.08, X5.02, P7.10, phpcs.xml; then full retest; then white-label copy.
Later/decision: G7.01-G7.05 (type and location), G4.06, P7.05, X5.03, S1-12.

## FINDINGS (ID | Severity | File:line | Fix | Effort | Status)

| ID | Sev | File:line | Fix | Effort | Status |
|---|---|---|---|---|---|
| P4.15 | Critical | public/templates/login.php:170, signup.php:225, forgot-password.php:43, reset-password.php:57 | Delete the 4 calls | small | DONE |
| P3.01 | Critical | includes/class-ofp-prembly.php:58-60,83,157,217 | Simulate only with WP_DEBUG or a constant, else refuse; switch live site to a clear message until funded | small | TODO |
| P3.02 | High | prembly.php:307-322 | Compare CAC company name to signup business name, allow admin approval of near matches | medium | TODO |
| S2-09 / S1-08 / P3.03 | High | prembly.php:22-23,267 | Nonce, 3 tries per day per client, refuse if verified | small | TODO |
| S2-01 | High | includes/class-ofp-credit.php:90-113 | One UPDATE with a balance check, test rows_affected | small | TODO |
| P4.02 | High | includes/class-ofp-property-commerce.php:220-300, purchase-service.php:40-130 | Atomic offer claim and SELECT FOR UPDATE on the property inside the transaction | medium | TODO |
| P4.25 | High | commerce.php:355 | One shared due date helper used by both paths | medium | TODO |
| P4.21 | High | includes/class-ofp-property-lease-payment.php:56-84 | Claim the row with UPDATE where status is pending, update lease in one query | medium | TODO |
| P4.01 / P6.01 | High | includes/class-ofp-property-commerce-repair.php:12,21-63 | Remove the init hook; run on save and once a day | small | TODO |
| P6.02 / P4.08 / P3.09 | High | commerce-migration.php:8, prembly.php:21, about 15 schema helpers | One ofp_db_version check; remove per request ones | medium | TODO |
| S0-01 / P4.03 | High | public/class-ofp-client-portal.php:1040 | Use OFP_Logger | small | TODO |
| P4.16 | High | includes/class-ofp-feedback.php:214,327 | Pass 4 arguments | small | TODO |
| P4.17 | High | admin/class-ofp-admin-menu.php:1964 | Use current_user() name or add the method | small | TODO |
| P3.04 | Medium | prembly.php:279-290 | Delete fallback, use OFP_Auth::current_client() | small | TODO |
| P3.05 | Medium | prembly.php:297,332,336-353; admin-menu.php:757 | Allow nin or bvn and test or live; check the update result | small | TODO |
| P3.06 | Medium | prembly.php:113-141,176-201 | 10 second limit, fixed messages, read response_code, cache CAC result | small | TODO |
| P3.07 | Medium | prembly.php:94,166,226 | Test once with a funded live key, then adjust | small | TODO |
| S2-02 | Medium | credit.php:46,98 | Allow a fixed list only (sms after D-16) | small | TODO |
| S2-03 | Medium | public/class-ofp-rest-api.php:374 | Secret in URL or header | small | TODO |
| S2-05 | Medium | rest-api.php:~175 | Site key or signed token, Turnstile | medium | TODO |
| S2-06 | Medium | public/templates/properties.php:~285 | Allow jpg, png, webp, check size and count | small | TODO |
| S2-07 / S2-08 | Medium | includes/class-ofp-property-receipt-viewer.php:161 | Per receipt HMAC token with expiry, add rate limit | medium | TODO |
| S2-10 / S1-09 | Medium | includes/class-ofp-bank-account.php:364 | Nonce, rate limit, drop nopriv | small | TODO |
| S2-11 | Medium | rest-api.php:63,670,680 | Origin list per client for write routes | medium | TODO |
| P7.01 | Medium | 9 files (funding.php:78,113,163, properties.php:85, property-offer.php:81 and others) | Use OFP_Security::get_client_ip() | small | TODO |
| P4.18 | Medium | admin/class-ofp-property-commerce-admin.php:258,262 | Build the URL from the offer token | small | TODO |
| P4.19 | Medium | admin/views/customers-list.php:111 | Make it public | small | TODO |
| P4.20 | Medium | public/templates/forgot-password.php, reset-password.php, team-invite.php (line 1) | Save without BOM | small | TODO |
| P4.22 | Medium | tenant-transfer.php:92-105, lease-payment.php:75 | Refuse or record as credit | medium | TODO |
| P4.23 | Medium | lease-payment.php:19, lease-reminders.php:20,59-63 | Allow expired leases while balance is above 0 | small | TODO |
| P4.24 | Medium | commerce.php:343,355, purchase-service.php calculate_due_date | DateTime with last day clamp | small | TODO |
| P4.26 | Medium | commerce.php:327-366 | Check each insert, throw to roll back | small | TODO |
| P4.04 | Medium | activator.php:38-60,119 | ofp_db_version check on plugins_loaded | medium | TODO |
| P4.05 / S1-03 | Medium | ofast-pipeline.php:150, cron-handler.php:78-79 | Schedule the 1st each month; first day of last month maths | small | TODO |
| P4.06 | Medium | cron-handler.php:56-66 | Separate hooks or try and catch | small | TODO |
| P4.07 | Medium | includes/class-ofp-property-installment-reminders.php:37-41,105 | Treat 1 or more days as overdue, repeat every 3 days | small | TODO |
| P6.03 | Medium | public/templates/property-marketplace.php:60-123, property-cpt.php:2403,2426 | Use the indexed ofp_properties table, COUNT(*), paging | medium | TODO |
| P6.04 | Medium | activator.php create tables | Add keys; cache the 3 counts for 5 minutes | small | TODO |
| P6.05 / X5.04 | Medium | marketplace, blog, agent profile, plans pages | Build one static CSS file, self host Alpine | medium | TODO |
| P6.06 / X5.05 | Medium | assets/css/client-portal.css 301 KB and others | Minify at release, check route splitting | medium | TODO |
| P6.07 | Medium | includes/class-ofp-sms.php:184,227,270 | 8 second limit and a message | small | TODO |
| P6.08 | Medium | 70 update_option calls, 33 with autoload false | Check sizes on staging, set autoload false | small | TODO |
| P7.08 | Medium | activator.php | Stop on network activation, document single site | small | TODO |
| B5.01 / C7.02 | Medium | includes/class-ofp-property-cpt.php:293 | Use the saved name | small | TODO |
| S2-04 | Low | voice webhook | Goes away with D-16 | small | TODO |
| S2-12 / S1-04 | Info | rest-api.php:93-122 | Covered by S2-03, S2-05 | small | TODO |
| S2-14 / S2-15 / S1-05 / S1-06 | Low | admin-post and wp_ajax nopriv hooks | Remove nopriv where clients become WP users | small | TODO |
| S2-16 | Low | admin-menu.php:1742,1756 | Nonce first; wp_safe_redirect | small | TODO |
| S2-17 | Low | includes/class-ofp-security.php:98 | AES GCM or add HMAC | small | TODO |
| S2-18 | Low | admin/views/settings.php:116 | Mask it | small | TODO |
| S2-20 / S1-10 | Low | Ofast_Pipeline_Universal_Receipt.html in plugin root | Move into includes or deny direct access | small | TODO |
| S2-19 | Info | all PHP files | None | small | TODO |
| S2-21 | Low | admin-menu.php:263-277 | Confirm callers pass fixed names only | small | TODO |
| S2-13 / C7.02b | Low | admin/class-ofp-admin-menu.php:42 | Remove add_action | small | TODO |
| P3.08 | Low | clients list, schema | Admin revoke action, store reference | small | TODO |
| P3.10 | Low | prembly.php:23,296-298 | Fix both | small | TODO |
| P4.09 | Low | uninstall.php | Real uninstall and erase hooks | medium | TODO |
| P4.10 | Low | activator.php create tables | Two spaces, check debug log on staging | small | TODO |
| P4.11 / S1-11 | Low | property-cpt.php:122 | One central flag | small | TODO |
| P4.12 / S1-02 | Low | activator.php:706-707 | Use 2 minute schedule directly | small | TODO |
| P4.13 | Low | many files | One helper with wp_date | medium | TODO |
| P4.14 | Low | includes/class-ofp-import.php:119 | mb_convert_encoding | small | TODO |
| P4.27 | Low | includes/class-ofp-ivr.php:183 | Goes away with D-16 | small | TODO |
| P4.28 | Low | includes/class-ofp-lead.php:236-251 | Allowed status list; append in one query | small | TODO |
| P4.29 | Low | lease-reminders.php:20-63 | Flip status in its own query | small | TODO |
| P4.30 | Low | billing.php, property-billing.php, nav.php, signup.php:268 | Check, most go with strip | small | TODO |
| P6.09 | Low | includes/class-ofp-property-purchase-service.php:282,235,335 | Move into the one template, delete the dead patch | small | TODO |
| P6.10 | Low | commerce.php:505-530 | One grouped query | small | TODO |
| P6.11 | Low | includes/class-ofp-chat-service.php:42,325 | Check it exits early | small | TODO |
| P7.02 | Low | 8 wp_redirect, 7 strip_tags, 47 json_encode, 37 date | Replace | small | TODO |
| P7.03 | Low | 12 template files | Static CSS build (X5.04) | medium | TODO |
| P7.04 | Low | field-agent.php:516, manual-payment.php:657 | Install note for Nginx | small | TODO (field-agent part ON HOLD) |
| P7.05 | Low | whole plugin | Only if needed | large | TODO |
| P7.06 / S1-01 | Low | ofast-pipeline.php:6,17 | One source of truth | small | TODO |
| P7.09 | Low | public pages | Add the constant | small | TODO |
| P7.10 | Low | includes/class-ofp-host-router.php:174,307 | Update comments, test wildcard SSL | small | TODO |
| C7.01 | Low | admin/class-ofp-property-commerce-admin.php:163,178 | Remove or link to portal | small | TODO |
| C7.03 | Low | ofp_db_version | Read it in P4.04 | small | TODO |
| D5.01 | Low | includes/class-ofp-property-payment-context.php | Delete | small | TODO |
| D5.02 | Low | includes/class-ofp-property-payment-entry-ui.php | Delete | small | TODO |
| D5.03 | Low | includes/class-ofp-ivr.php | Delete | small | TODO |
| D5.04 | Low | admin/views/property-billing.php | Delete after portal tab check | small | TODO |
| D5.05 | Low | assets/css/marketplace.css, assets/js/marketplace.js | Delete after a network tab check | small | TODO |
| D5.06 | Low | admin-url, brand, payment, subscription, commerce-repair | Delete | small | TODO |
| D5.07 / C7.04 | Low | ofp_get_bank_list | Check then remove | small | TODO |
| X5.01 | Low | admin-menu.php:809-811 and :1427-1430 | Keep one | small | TODO |
| X5.02 | Low | 8 templates | One JS file | small | TODO |
| X5.03 | Info | page shells (blog, agents, marketplace, signup, login) | Shared partials later, not now | large | TODO |
| D-16 | Medium | about 45 files | Follow the list in the D-16 section | large | TODO |
| S1-12 | Info | whole plugin | Split only when touching them | large | TODO |
| S1-13 | Info | receipt and payment record files | None | small | TODO |
| S1-14 | Info | none | None | small | TODO |
| G3.01 | High | prembly.php:307-322 | DECISION: name match is self-typed; tie NIN/BVN holder name to CAC director/owner or add face match | medium | DECISION |
| G3.02 | Medium | prembly.php:83,157,217,330 | Do not save simulated results as verified; reset test-mode verifications at go-live (SQL in AUDIT.md) | small | TODO |
| G3.03 | Medium | prembly.php:296-346 | Refuse a CAC number already verified on another client (admin override) | small | TODO |
| G3.04 | Low | properties.php:104 | Decide: Free plan needs KYC? hide listings on revoke? | small | DECISION |
| G4.01 | High | class-ofp-payment.php:155-182; gateway-paystack.php:136; payment.php:494 | UNIQUE KEY (reference,type) on ofp_credit_transactions; insert row first, credit only if insert worked | small | TODO |
| G2.01 | Medium | class-ofp-client.php:180-211 | Stop keeping plain temp password in a transient and emailing it; send a set-password link | small | TODO |
| G4.06 | Low | property-my-payments.php:82-107 | Server-side revoke of the buyer session cookie | small | LATER |
| G4.02-G4.05 | Mixed | field-agent.php, client-portal.php:771 | Field agent items | - | ON HOLD (D-17); G4.05 team role allow-list is outside the frozen files: TODO Low |
| G6.01 | Low | my-payments.php:127,143 | Store last 10 digits in an indexed column; avoid LIKE '%'.phone | small | TODO |
| G6.02 | Low | property-cpt.php:1750 | Add paging to posts_per_page -1 | small | TODO |
| G6.03 | Medium | 8 public templates | Self-host Google Fonts, GSAP, Alpine; add SRI where CDN stays | medium | TODO (with P6.05) |
| G6.04 | Low | several files | Set an explicit timeout on wp_remote_* calls (about 8 lack one; confirm each) | small | TODO |
| G7.01-G7.05 | Medium | property-cpt.php:137-160, admin-portal.php:251, properties.php:159, property-marketplace.php:36-52 | Type and location: ONE source (taxonomies), State dropdown, auto-created Area; field agent untouched | medium | DECISION |
| G8.01 | Medium | queue.php:38 +5 code places +5 texts | One setting ofp_sms_price (default 10); all callers use it | small | TODO |
| G8.02 | Medium | class-ofp-sms.php:97-103 | Count Unicode pages (70/67); write NGN not the Naira sign in system SMS | small | TODO |
| G9.01 | Low-Med | my-payments.php:178; tenant-transfer.php:208 | Validate Host before building links | small | TODO |
| G9.02 | Low | auth.php:293,753; my-payments.php:108 | Cloudflare Flexible makes is_ssl() false; use Full(strict) / trust X-Forwarded-Proto; test | small | TODO |
| G9.03 | Low | queue, reminders | Install note: real server cron + DISABLE_WP_CRON | small | TODO |
| G9.04 | Low | ofast-pipeline.php header | Add Update URI: false | small | TODO |
| S2-23/S2-24 | Low | class-ofp-auth.php:230; login/signup/forgot | OTP try counter per contact inside verify_otp; lock 15 min | small | TODO |
| S2-26 | Low | properties.php:2276; feedback-list.php:533,537 | Use textContent, not innerHTML | small | TODO |
| S2-28 | Low | class-ofp-import.php | Check unzipped size of XLSX parts (zip bomb) | small | TODO |
| D5.08 | Low | admin/views/communications-log.php | Dead view, never loaded; delete with D5.04 | small | TODO |

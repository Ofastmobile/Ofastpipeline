# OFast Pipeline — Queue Architecture & Cloudflare Cron Setup

This document explains how the **OFast Pipeline Queue and Automation Engine** operates, the limitations of default WordPress WP-Cron, and how to configure a **Cloudflare Worker Cron Trigger** to run all automation triggers (Instant SMS, Voice Calls, Email Follow-ups, Payment Reminders) with 100% reliability 24/7/365.

---

## 1. How the Pipeline Queue Works

When a lead enters the system (via the public lead capture form, property inquiry booking card, or CSV bulk import), the following happens automatically:

1. **Lead Creation (`wp_ofp_leads`)**:
   - The lead is inserted with `name`, `phone`, `email` (optional), `property_id`, and `source`.
   - Built-in 24-hour phone deduplication prevents duplicate submissions for the same client.

2. **Trigger Population (`wp_ofp_trigger_queue`)**:
   - `OFP_Queue::populate_triggers($client_id, $lead_id)` reads the client's automation configuration from `wp_ofp_pipeline_configs`.
   - Up to 4 automated stages are scheduled:
     - **Instant SMS**: Scheduled for `NOW` (fires on the very next cron cycle).
     - **Follow-up 1**: Scheduled for `NOW + (delay_1 * 1 hour)` (Type: SMS, Voice, or Email).
     - **Follow-up 2**: Scheduled for `NOW + (delay_2 * 1 hour)` (Type: SMS, Voice, or Email).
     - **Follow-up 3**: Scheduled for `NOW + (delay_3 * 1 hour)` (Type: SMS, Voice, or Email).
   - If enabled, automated voice calls (e.g. Lead Interest Call) are queued via `OFP_Voice_Notice`.

3. **Client Configuration vs. Defaults**:
   - **If Client Does NOT Configure**: By default, new accounts are seeded with SMS and Voice defaults. No automated emails are sent unless the client explicitly selects `Email` as the type in `/pipeline-settings`.
   - **If Client Configures**: Clients can switch any follow-up to `Email`, customize delay hours, edit message templates with dynamic tags (`{{name}}`, `{{business_name}}`, `{{property}}`), and style their emails under `/message-templates`.

---

## 2. The Problem with Default WordPress Cron (WP-Cron)

By default, WordPress **does not run on a real system clock**.

- **Visitor-dependent**: WP-Cron only checks for due jobs when a human visitor browses your website.
- **The Delay Problem**: If a lead submits an inquiry at 11:30 PM and no one visits the site until 7:00 AM, the "Instant" SMS, calls, and follow-ups **will sit in the database for 7.5 hours** without firing.
- **Performance Overhead**: Having WordPress check cron jobs during visitor page requests slows down front-end response times for regular users.

---

## 3. The Cloudflare Solution

Cloudflare provides free **Cron Triggers** within Cloudflare Workers that send automated HTTP requests to your site on an exact schedule.

### Cloudflare Free Tier Capacity Math:
- Cloudflare gives **100,000 requests per day for free**.
- A full 24-hour day has **1,440 minutes**.

| Schedule Interval | Requests per Day | % of Free 100k Daily Limit |
| :--- | :--- | :--- |
| **Every 5 minutes** (`*/5 * * * *`) | **288** requests / day | **0.29%** |
| **Every 2 minutes** (`*/2 * * * *`) | **720** requests / day | **0.72%** |
| **Every 1 minute** (`* * * * *`) | **1,440** requests / day | **1.44%** |

Even on an aggressive **1-minute cron**, you use less than **1.5%** of the free daily allowance. You will never incur costs or hit rate limits.

---

## 4. Setup Guide (2 Steps)

### Step 1: Disable Visitor-Based Cron in WordPress

Open your WordPress `wp-config.php` file in the root directory and add the following line above `/* That's all, stop editing! */`:

```php
define( 'DISABLE_WP_CRON', true );
```

This prevents WordPress from executing cron tasks during regular visitor page loads.

---

### Step 2: Create the Cloudflare Worker

1. Log in to your **[Cloudflare Dashboard](https://dash.cloudflare.com/)**.
2. Navigate to **Workers & Pages** in the left sidebar.
3. Click **Create Application** → **Create Worker**.
4. Give it a name (e.g. `ofast-cron-worker`) and click **Deploy**.
5. Click **Edit Code**, replace the default code with the script below, and click **Deploy**:

```javascript
/**
 * Cloudflare Worker Cron Trigger for OFast Pipeline
 * Pings WordPress wp-cron.php on a fixed background schedule.
 */
export default {
  async scheduled(event, env, ctx) {
    // Replace with your production domain
    const targetUrl = "https://yourdomain.com/wp-cron.php?doing_wp_cron";

    try {
      const response = await fetch(targetUrl, {
        method: "GET",
        headers: {
          "User-Agent": "Cloudflare-Cron-OFast-Pipeline/1.0",
        },
      });

      console.log(`Cron triggered at ${new Date().toISOString()} - Status: ${response.status}`);
    } catch (err) {
      console.error(`Cron trigger failed: ${err.message}`);
    }
  },
};
```

6. In the Worker details page, navigate to the **Settings** tab → **Triggers** section.
7. Under **Cron Triggers**, click **Add Cron Trigger**.
8. Set the Cron Expression:
   - **Recommended**: `*/2 * * * *` (Fires every 2 minutes for fast instant SMS/call delivery).
   - **Standard**: `*/5 * * * *` (Fires every 5 minutes).
9. Click **Add Trigger**.

---

## 5. Master Inventory of OFast Pipeline Cron Jobs

Once Cloudflare is active, all scheduled tasks within OFast Pipeline run automatically:

| Hook Name | Frequency | What It Handles |
| :--- | :--- | :--- |
| `ofp_process_queue` | Every 5 mins (or 2 mins with Cloudflare) | Dispatches due Instant SMS, Follow-up SMS, Voice calls, and Automated Emails to leads from `wp_ofp_trigger_queue`. |
| `ofp_send_interest_call` | Dynamic (Immediate / Next morning) | Places instant lead interest calls if enabled in Call Scripts. Restricts calls to configured call hours. |
| `ofp_send_voice_reminder` | Dynamic (Due dates) | Automated voice call reminders for buyer installments and tenant rent. |
| `ofp_daily_subscription_check` | Daily | • Checks client plan expiry & renewal nudges.<br>• Checks and sends buyer installment reminders (`OFP_Property_Installment_Reminders`).<br>• Checks and sends tenant rent reminders (`OFP_Property_Lease_Reminders`).<br>• Purges debug logs older than 30 days. |
| `ofp_daily_credit_check` | Daily | Alerts clients when SMS or voice credits drop below low-balance thresholds. |
| `ofp_monthly_archive` | 1st of each month | Generates downloadable CSV audit packs for clients and purges expired queue records older than 90 days. |

---

## 6. Verification & Troubleshooting

- **Check Queue Processing**: View `wp_ofp_trigger_queue` in your database. Active rows should transition from `pending` → `processing` → `completed`.
- **Check Communications Log**: Sent messages appear in the client dashboard under **Communications** with accurate delivery status and timestamps.
- **Cloudflare Worker Logs**: In the Cloudflare Worker dashboard under **Logs** → **Real-time Logs**, verify that executions show `Status: 200`.

# VTUWeb Affiliate & Referral Engine (Reference Files)

These files are copied from `C:\Users\bodma\Downloads\vtuweb` as a standalone reference. They are not hooked into the OFast Pipeline plugin runtime.

---

## File Directory & Purpose

| File | Original Source | Description |
| :--- | :--- | :--- |
| [`referral.php`](file:///c:/Users/bodma/Local%20Sites/ofast-pipeline/app/public/wp-content/plugins/ofast-pipeline/affiliate/referral.php) | `web/dash/referral.php` | Main affiliate dashboard & bonus withdrawal engine. Generates user referral link, shows stats, lists downstream users, and handles bonus withdrawals to main wallet balance. |
| [`ref_cost.php`](file:///c:/Users/bodma/Local%20Sites/ofast-pipeline/app/public/wp-content/plugins/ofast-pipeline/affiliate/ref_cost.php) | `403_admin_not_found/ref_cost.php` | Admin setting page to configure the bonus amount awarded per referral (`config.refBonus`). |
| [`sign_up.php`](file:///c:/Users/bodma/Local%20Sites/ofast-pipeline/app/public/wp-content/plugins/ofast-pipeline/affiliate/sign_up.php) | `web/sign_up/index.php` | Web sign-up handler. Captures `referrer`, links the new user into the referrer's `refs` JSON array, and sets `refBy`. |
| [`api_register.php`](file:///c:/Users/bodma/Local%20Sites/ofast-pipeline/app/public/wp-content/plugins/ofast-pipeline/affiliate/api_register.php) | `app_api/get/register.php` | Mobile API registration endpoint. Same affiliate linking logic for mobile app users. |
| [`asyncAir.php`](file:///c:/Users/bodma/Local%20Sites/ofast-pipeline/app/public/wp-content/plugins/ofast-pipeline/affiliate/asyncAir.php) | `web/dash/asyncAir.php` | Airtime purchase transaction. Increments referrer's `commission` balance and logs to `transactions`. |
| [`topUp.php`](file:///c:/Users/bodma/Local%20Sites/ofast-pipeline/app/public/wp-content/plugins/ofast-pipeline/affiliate/topUp.php) | `web/dash/topUp.php` | Data purchase transaction. Increments referrer's `commission` balance and logs to `transactions`. |
| [`check-cable.php`](file:///c:/Users/bodma/Local%20Sites/ofast-pipeline/app/public/wp-content/plugins/ofast-pipeline/affiliate/check-cable.php) | `web/dash/check-cable.php` | Cable TV purchase transaction. Increments referrer's `commission` balance and logs to `transactions`. |
| [`checkbill.php`](file:///c:/Users/bodma/Local%20Sites/ofast-pipeline/app/public/wp-content/plugins/ofast-pipeline/affiliate/checkbill.php) | `web/dash/checkbill.php` | Electricity bill transaction. Increments referrer's `commission` balance and logs to `transactions`. |
| [`user_transactions.php`](file:///c:/Users/bodma/Local%20Sites/ofast-pipeline/app/public/wp-content/plugins/ofast-pipeline/affiliate/user_transactions.php) | `web/dash/transactions.php` | User transaction history page showing `REFERRAL COMMISSION` and `BONUS WITHDRAWAL` entries. |
| [`admin_transactions.php`](file:///c:/Users/bodma/Local%20Sites/ofast-pipeline/app/public/wp-content/plugins/ofast-pipeline/affiliate/admin_transactions.php) | `403_admin_not_found/transactions.php` | Admin audit ledger of all transaction logs across all users. |

---

## Database Fields Used in VTUWeb
* **`users` table:**
  * `refBy`: Username of the referrer who invited this user.
  * `refs`: JSON array of usernames invited by this user (e.g. `["userA", "userB"]`).
  * `compRef`: JSON array of usernames whose referral bonus has already been withdrawn.
  * `commission`: Total accumulated commission earned from downstream transactions.
  * `bonus`: Total bonus withdrawn to wallet balance.
* **`config` table:**
  * `refBonus`: Flat bonus amount awarded per referral (e.g. ₦100 or ₦250).
* **`transactions` table:**
  * `type = 'REFERRAL COMMISSION'`: Triggered on purchase events.
  * `type = 'BONUS WITHDRAWAL'`: Triggered when referral bonus is withdrawn to main wallet balance.


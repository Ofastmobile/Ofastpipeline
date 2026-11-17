# FIXLOG.md - one line per ID. Keep short.
Format: ID | DONE or TODO | files touched | rule that must not be undone | checks (linted only / staging tested)

P4.15 | DONE 2026-11-09 | public/templates/login.php, signup.php, forgot-password.php, reset-password.php | never call OFP_Brand::print_host_head (method is gone) | linted only; needs staging test of /login /signup /forgot-password and reset link
WL-REMOVAL | DONE 2026-11-08/09 | 35 files (white-label removed from MAIN); admin/views/settings.php (2 help texts) | no OFP_White_Label, is_white_label, custom_domain, wl_billing in code | linted only

## Checks to run before every hand-back
1. php -l on every PHP file (0 errors). 2. Scan every OFP_Class::method() call and hook callback for missing methods. 3. Search for names that must be gone (after D-16: voice, ivr, call_script, default_call, VOICE_COST, interest_call, tts). 4. Update this file.

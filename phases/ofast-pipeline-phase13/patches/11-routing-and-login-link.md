# PATCH 11 — Route registration + login.php link

**Type:** INSERT (small, 2 parts)

---

## 11a. Register the 2 new public routes

Find wherever `signup` and `login` are currently registered as
no-login-required exceptions — likely in
`includes/class-ofp-client-portal.php`'s `handle_routes()`, something
shaped like:

```php
$public_routes = [ 'login', 'signup' ];
```

Add the two new templates:

```php
$public_routes = [ 'login', 'signup', 'forgot-password', 'reset-password' ];
```

If your routing instead uses a `switch`/`match` statement per route
rather than an array, add matching `case 'forgot-password':` and
`case 'reset-password':` entries pointing at
`public/templates/forgot-password.php` and
`public/templates/reset-password.php` respectively, following exactly
the same pattern the existing `case 'signup':` entry uses.

---

## 11b. Add a "Forgot password?" link to login.php

Find the existing login form in `public/templates/login.php` — insert
this immediately below the password field, before the submit button:

```php
<p class="ofp-forgot-link">
    <a href="<?php echo esc_url( home_url( '/forgot-password' ) ); ?>">Forgot your password?</a>
</p>
```

---

## Why this is safe to insert as-is

- Both new routes are public (no session required), matching exactly
  how `signup` already works — no changes to your session/auth
  middleware needed beyond adding these 2 strings to whatever allowlist
  already exists.
- The login.php addition is a single `<p>` tag with no logic — can't
  break the existing form submission.

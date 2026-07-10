# PATCH 9 — includes/class-ofp-auth.php

**Type:** INSERT (new methods only — do not replace the file)

**Read this first:** your notes say `generate_reset_token()` already
exists on this class as infrastructure, but I don't have its actual
current code — only that it exists. To avoid a "cannot redeclare
method" fatal, I've deliberately named the orchestration methods below
something distinct (`request_password_reset`, not
`generate_reset_token`). If your existing `generate_reset_token()`
already does token generation + storage, the cleanest move is to have
`request_password_reset()` below call into it instead of the inline
generation code — I built it self-contained because I can't see your
existing token storage shape (which column(s), what hash algorithm,
what expiry window) to safely reuse it blind.

This also requires **2 new columns** on `wp_ofp_clients` — see Patch 10.

---

## Add these methods to the class

```php
/* -----------------------------------------------------------
 * Password reset (Phase 13)
 *
 * Three-step flow: request (email lookup + token email) ->
 * verify (token + expiry check, shown before the new-password form
 * loads) -> complete (token re-checked, password updated, token
 * cleared, single-use).
 *
 * SECURITY NOTES:
 * - The raw token is only ever shown to the client once, inside the
 *   emailed link. Only its hash is stored in the database — if the
 *   database were ever compromised, stored hashes alone can't be used
 *   to reset an account.
 * - request_password_reset() always returns the same generic
 *   response regardless of whether the email matched a client, so
 *   this endpoint can't be used to enumerate which emails have
 *   accounts.
 * - Tokens expire after 30 minutes and are single-use — cleared the
 *   moment complete_password_reset() succeeds.
 * --------------------------------------------------------- */

const RESET_TOKEN_TTL_MINUTES = 30;

/**
 * Starts a password reset: looks up the client by email, generates a
 * token, stores its hash + expiry, and emails the reset link.
 *
 * Deliberately returns true whether or not the email matched a real
 * client — callers (forgot-password.php) should always show the same
 * generic "if that email exists, we've sent a link" message, so this
 * can't be used to check which emails are registered.
 *
 * @param string $email
 * @return true always, by design — see note above
 */
public static function request_password_reset( string $email ): bool {
    $client = OFP_Client::get_by_email( sanitize_email( $email ) );

    if ( ! $client ) {
        // Same return value as the success path — no enumeration signal.
        return true;
    }

    $raw_token = wp_generate_password( 32, false, false );
    $token_hash = self::hash_reset_token( $raw_token );
    $expires = date( 'Y-m-d H:i:s', strtotime( '+' . self::RESET_TOKEN_TTL_MINUTES . ' minutes' ) );

    global $wpdb;
    $wpdb->update( $wpdb->prefix . 'ofp_clients', [
        'reset_token_hash'    => $token_hash,
        'reset_token_expires' => $expires,
    ], [ 'id' => $client->id ] );

    $reset_link = add_query_arg( [
        'client' => $client->id,
        'token'  => $raw_token,
    ], home_url( '/reset-password' ) );

    OFP_Mailer::send_transactional(
        $client->email,
        $client->owner_name,
        'Reset your OFast Pipeline password',
        "Hi {$client->owner_name},<br><br>
         We received a request to reset your password. This link expires in " . self::RESET_TOKEN_TTL_MINUTES . " minutes:<br><br>
         <a href=\"{$reset_link}\">Reset your password</a><br><br>
         If you didn't request this, you can safely ignore this email — your password will not change."
    );

    return true;
}

/**
 * Verifies a reset token is valid (matches the stored hash for that
 * client and hasn't expired) WITHOUT consuming it. Used by
 * reset-password.php to decide whether to show the new-password form
 * or an "this link has expired" message, before the client has typed
 * anything.
 *
 * @param int    $client_id
 * @param string $token raw token from the URL
 * @return bool
 */
public static function verify_reset_token( int $client_id, string $token ): bool {
    $client = OFP_Client::get( $client_id );
    if ( ! $client || empty( $client->reset_token_hash ) || empty( $client->reset_token_expires ) ) {
        return false;
    }

    if ( strtotime( $client->reset_token_expires ) < time() ) {
        return false;
    }

    return hash_equals( $client->reset_token_hash, self::hash_reset_token( $token ) );
}

/**
 * Completes a password reset: re-verifies the token (never trust that
 * verify_reset_token() was called earlier in the same request — this
 * re-checks independently), updates the password, and clears the
 * reset token so it can't be reused.
 *
 * @param int    $client_id
 * @param string $token raw token from the form
 * @param string $new_password plaintext — hashed here, never stored raw
 * @return bool true on success, false if the token is invalid/expired
 */
public static function complete_password_reset( int $client_id, string $token, string $new_password ): bool {
    if ( ! self::verify_reset_token( $client_id, $token ) ) {
        return false;
    }

    global $wpdb;
    $wpdb->update( $wpdb->prefix . 'ofp_clients', [
        'password_hash'       => password_hash( $new_password, PASSWORD_DEFAULT ),
        'reset_token_hash'    => null,
        'reset_token_expires' => null,
    ], [ 'id' => $client_id ] );

    return true;
}

/**
 * One-way hash for a reset token before storage/comparison. Uses
 * sha256 (not password_hash/bcrypt) deliberately — this is a
 * high-entropy, randomly-generated 32-char token being compared for
 * exact equality, not a low-entropy human password needing slow
 * hashing to resist brute force. hash_equals() below provides the
 * timing-safe comparison instead.
 *
 * @param string $raw_token
 * @return string
 */
private static function hash_reset_token( string $raw_token ): string {
    return hash( 'sha256', $raw_token );
}
```

---

## A note on `password_hash` as the column name

I've assumed your existing client login flow stores passwords in a
`password_hash` column on `wp_ofp_clients`, hashed via PHP's
`password_hash()` / verified via `password_verify()` — this is the
standard WordPress-adjacent convention and the most likely shape given
everything else in this plugin. If your actual column or hashing
approach differs, the only line that needs adjusting is the
`'password_hash' => password_hash(...)` line inside
`complete_password_reset()`.

## Why this is safe to insert as-is

- All 4 new methods (`request_password_reset`, `verify_reset_token`,
  `complete_password_reset`, `hash_reset_token`) are new, specific
  names — no collision with anything already in the class except the
  possible overlap with `generate_reset_token()` flagged above.
- `OFP_Mailer::send_transactional()` is assumed to exist as your
  ZeptoMail-routed method (per your notes: ZeptoMail for transactional,
  Brevo for marketing) — if your mailer class uses a different method
  name for transactional sends, swap it in the one call site above.

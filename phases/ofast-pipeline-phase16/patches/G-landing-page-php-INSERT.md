# PATCH G — includes/class-ofp-landing-page.php

**Type:** INSERT (1 small guard clause)

**Why this is needed:** `OFP_Landing_Page::get_client_by_host()` (Phase
15) looks up a client by matching the subdomain slug in the hostname.
Without this guard, if a request ever came in for `app.crmdomain.com`
before `OFP_Host_Router` had a chance to handle it (or if a client's
row somehow ever had `subdomain = 'app'` from a data-entry mistake),
this method would incorrectly try to treat "app" as a client's
subdomain slug. This guard makes that impossible regardless of data
mistakes, not just reliant on admin form validation.

---

## Add this at the top of `get_client_by_host()`

Find, from Phase 15:

```php
private static function get_client_by_host( string $host ): ?object {
    global $wpdb;

    $base_domain = self::normalize_host( get_option( 'ofp_crm_base_domain', '' ) );

    // Subdomain match: {slug}.{base_domain}
    if ( $base_domain && str_ends_with( $host, '.' . $base_domain ) ) {
        $slug = substr( $host, 0, -( strlen( $base_domain ) + 1 ) );
        ...
```

Add a guard right after `$slug` is computed:

```php
private static function get_client_by_host( string $host ): ?object {
    global $wpdb;

    $base_domain = self::normalize_host( get_option( 'ofp_crm_base_domain', '' ) );

    // Subdomain match: {slug}.{base_domain}
    if ( $base_domain && str_ends_with( $host, '.' . $base_domain ) ) {
        $slug = substr( $host, 0, -( strlen( $base_domain ) + 1 ) );

        // Phase 16: "app" and "property" (and other reserved words)
        // are system subdomains, never a client's — skip the lookup
        // entirely rather than risk a false match.
        if ( OFP_Host_Router::is_reserved( $slug ) ) {
            return null;
        }

        $row = $wpdb->get_row( $wpdb->prepare( "
            SELECT * FROM {$wpdb->prefix}ofp_clients WHERE subdomain = %s LIMIT 1
        ", $slug ) );
        if ( $row ) return $row;
    }
    ...
```

---

## Also add server-side validation when saving a client's subdomain

Wherever Patch E's save handler (Phase 15) sanitizes the `subdomain`
field before saving, add this check right after the `sanitize_title()`
call:

```php
if ( $subdomain && OFP_Host_Router::is_reserved( $subdomain ) ) {
    // Reject the save / show an admin error — "app", "property", etc.
    // can never be used as a client's subdomain.
    $subdomain = '';
    // (wire this into whatever error-display mechanism the rest of
    // that form already uses)
}
```

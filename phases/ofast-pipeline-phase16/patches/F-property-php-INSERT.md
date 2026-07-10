# PATCH F — includes/class-ofp-property.php

**Type:** INSERT (2 additions to the file from Phase 14 — you should
have this file already; these are new pieces, not replacements of
anything already in it)

---

## F1. Add this filter registration to `init()`

Find the `init()` method from Phase 14:

```php
public static function init(): void {
    add_action( 'init', [ __CLASS__, 'register_post_type' ] );
    add_action( 'init', [ __CLASS__, 'register_meta_fields' ] );
    add_filter( 'template_include', [ __CLASS__, 'load_templates' ] );
}
```

Add one more line:

```php
public static function init(): void {
    add_action( 'init', [ __CLASS__, 'register_post_type' ] );
    add_action( 'init', [ __CLASS__, 'register_meta_fields' ] );
    add_filter( 'template_include', [ __CLASS__, 'load_templates' ] );
    add_filter( 'post_type_link', [ __CLASS__, 'rewrite_permalink_to_property_subdomain' ], 10, 2 );
}
```

## Add this new method to the class

```php
/**
 * Makes every property permalink (the_permalink(), get_permalink(),
 * etc. — anywhere in the codebase) use property.{base_domain}
 * instead of your main site address. This is what makes the property
 * cards on the archive page link correctly to
 * property.crmdomain.com/property/{slug}/ instead of back to your
 * main domain, with no changes needed to property-archive.php itself.
 *
 * As a side effect, this also prevents WordPress's canonical-redirect
 * feature from trying to bounce visitors away from
 * property.crmdomain.com back to your main domain — that check
 * compares against get_permalink(), which this filter has already
 * corrected, so there's nothing left for it to "fix."
 *
 * @param string  $post_link
 * @param WP_Post $post
 * @return string
 */
public static function rewrite_permalink_to_property_subdomain( string $post_link, WP_Post $post ): string {
    if ( $post->post_type !== self::POST_TYPE ) {
        return $post_link;
    }

    $base_domain = get_option( 'ofp_crm_base_domain' );
    if ( ! $base_domain ) {
        return $post_link;
    }

    return preg_replace( '#^https?://[^/]+#', 'https://property.' . $base_domain, $post_link );
}
```

---

## F2. Make property.crmdomain.com's bare root show the archive directly

Find `load_templates()` from Phase 14:

```php
public static function load_templates( string $template ): string {
    if ( is_post_type_archive( self::POST_TYPE ) ) {
        ...
    }
    if ( is_singular( self::POST_TYPE ) ) {
        ...
    }
    return $template;
}
```

Add this check at the very top, before the two existing `if` blocks:

```php
// Phase 16: property.crmdomain.com's homepage IS the property
// archive — nicer than making visitors go to /properties/ on their
// own subdomain.
if ( is_front_page() && OFP_Host_Router::current_zone() === 'property' ) {
    $theme_override = locate_template( 'archive-' . self::POST_TYPE . '.php' );
    if ( $theme_override ) return $theme_override;
    return OFP_PLUGIN_DIR . 'public/templates/property-archive.php';
}
```

---

## Why this is safe to insert as-is

- The permalink filter only touches posts of type `ofp_property` —
  every other post/page link on your site (including the normal
  Contact page link used inside property-single.php) is completely
  unaffected.
- The root-override check is additive (a new `if` before the existing
  ones), so `/properties/` and `/property/{slug}/` keep working
  exactly as they did in Phase 14 on your main domain too, if you ever
  need them there.

---

## F3. A real conflict this phase catches — worth understanding, not just applying

Back in Phase 14, the property CPT was registered with
`'has_archive' => 'properties'`, meaning WordPress treats the URL path
`/properties/` as "show the property marketplace" everywhere,
regardless of which address someone used to get there. Also in Phase
14, the client's private "My Properties" dashboard page was ALSO
registered at the path `/properties` (Patch D). On one shared domain,
those two were already quietly competing for the same URL — this
phase is what finally exposes it, since now both addresses genuinely
need `/properties` to mean different things at the same time
(app.crmdomain.com/properties = dashboard, property.crmdomain.com/ =
marketplace).

**Fix:** make the marketplace archive check in `load_templates()`
explicitly skip the `app` zone, so the dashboard route always wins
there. Change this existing Phase 14 code:

```php
if ( is_post_type_archive( self::POST_TYPE ) ) {
```

to:

```php
if ( is_post_type_archive( self::POST_TYPE ) && OFP_Host_Router::current_zone() !== 'app' ) {
```

That one condition is what guarantees `app.crmdomain.com/properties`
always shows the client's own listings dashboard, never the public
marketplace, even though both technically live at the same URL path
on different hosts.

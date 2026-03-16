<?php
/**
 * Plugin Name:       KT Product Category Slugs
 * Plugin URI:        https://github.com/kontikidigital/kt-product-cat-slugs
 * Description:       Removes any base slug from WooCommerce product category URLs, exposing the full category hierarchy directly (e.g. /fertilizers/biostimulants/hormones/). Supports up to three levels. Fixes permalinks for uncategorised products. Compatible with WordPress Multisite (subdirectory and subdomain), WPML and Polylang.
 * Version:           1.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Kontiki Digital
 * Author URI:        https://kontiki.digital
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       kt-product-cat-slugs
 * Domain Path:       /languages
 * Network:           true
 *
 * @package KontikiDigital\ProductCategorySlugs
 */

declare( strict_types=1 );

namespace KontikiDigital\ProductCategorySlugs;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main plugin class.
 *
 * Handles removal of the product category base slug, custom rewrite rules,
 * request resolution and term link rewriting.
 *
 * All database lookups are memoized in a per-request in-memory cache to
 * avoid redundant queries when the same term hierarchy is resolved multiple
 * times during a single page load (e.g. menus, widgets, breadcrumbs).
 */
final class Product_Category_Slugs {

	/**
	 * Plugin version.
	 *
	 * @const string VERSION
	 */
	const VERSION = '1.1.0';

	/**
	 * Option name used to track whether rewrite rules need flushing.
	 *
	 * @const string FLUSH_OPTION
	 */
	const FLUSH_OPTION = 'kt_pcs_flush_rewrite';

	/**
	 * Prefix used for custom rewrite query vars.
	 *
	 * @const string VAR_PREFIX
	 */
	const VAR_PREFIX = 'kt_pcs_l';

	/**
	 * Maximum number of supported category hierarchy levels.
	 *
	 * @const int MAX_LEVELS
	 */
	const MAX_LEVELS = 3;

	/**
	 * Query var used to capture the product slug in deep product URLs
	 * (category with MAX_LEVELS levels + product slug = MAX_LEVELS+1 segments).
	 *
	 * @const string PRODUCT_VAR
	 */
	const PRODUCT_VAR = 'kt_pcs_product';

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Per-request cache for resolved term slugs.
	 *
	 * Keyed by a string built from the slug path (e.g. "l1/l2/l3").
	 * Stores the resolved deepest term slug or false when no match exists,
	 * so negative lookups are also cached and never re-queried.
	 *
	 * @var array<string, string|false>
	 */
	private array $term_slug_cache = array();

	/**
	 * Per-request cache for term ancestor slug chains.
	 *
	 * Keyed by term ID. Stores the ordered array of slugs from root to leaf.
	 *
	 * @var array<int, string[]>
	 */
	private array $ancestor_cache = array();

	/**
	 * Per-request cache of WooCommerce special page URI paths, keyed by page ID.
	 * Populated lazily on the first call to is_woocommerce_special_page().
	 *
	 * @var array<string, true>|null  Keys are page URI paths; null = not yet built.
	 */
	private ?array $wc_page_paths = null;

	/**
	 * Per-request cache for child term lookups.
	 *
	 * Keyed by "{parent_id}:{slug}". Stores the WP_Term or null.
	 *
	 * @var array<string, \WP_Term|null>
	 */
	private array $child_term_cache = array();

	/**
	 * Cached list of publicly queryable post types excluding 'product', used
	 * by reparse_with_wp_rules(). Built once on first use.
	 *
	 * @var string[]|null
	 */
	private ?array $public_post_types = null;

	/**
	 * Returns the singleton instance.
	 *
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor — use instance().
	 */
	private function __construct() {
		$this->init_hooks();
	}

	/**
	 * Registers all WordPress hooks.
	 *
	 * Hook priority rationale:
	 *  - woocommerce_taxonomy_args_product_cat: must fire before WooCommerce
	 *    registers the product_cat taxonomy on init:5. Hooking it directly
	 *    inside plugins_loaded (before init runs) guarantees correct ordering.
	 *  - query_vars: registered here so the vars are available as early as
	 *    possible, before parse_request fires.
	 *  - init:1 for add_rewrite_rules: must run before WP's own init at
	 *    default priority 10.
	 *  - init:1 for load_textdomain: ensures translations are available to
	 *    any other init callbacks that may reference this text domain.
	 *  - init:99 for maybe_flush_rewrite_rules: runs late in init, after all
	 *    rewrite rules from every plugin have been registered, so the flush
	 *    captures the complete final ruleset. Using init instead of
	 *    woocommerce_init ensures the flush fires on every request type
	 *    (front-end, admin, REST, AJAX) without depending on WooCommerce
	 *    bootstrapping fully.
	 *
	 * @return void
	 */
	private function init_hooks(): void {
		// Must fire before WooCommerce registers the taxonomy on init:5.
		add_filter( 'woocommerce_taxonomy_args_product_cat', array( $this, 'remove_category_base' ) );

		// Register query vars before parse_request.
		add_filter( 'query_vars', array( $this, 'register_query_vars' ) );

		// Register rewrite rules early in init, before WP default rules.
		add_action( 'init', array( $this, 'add_rewrite_rules' ), 1 );

		// Load text domain early so translations are available to all init callbacks.
		add_action( 'init', array( $this, 'load_textdomain' ), 1 );

		// Flush rewrite rules late in init, after all plugins have registered
		// their rules, and on every request type (not just WooCommerce pages).
		add_action( 'init', array( $this, 'maybe_flush_rewrite_rules' ), 99 );

		// Resolve the matched rewrite rule into the correct WooCommerce query vars.
		add_filter( 'request', array( $this, 'handle_category_request' ) );

		// Rewrite term links to remove the base and expose the full hierarchy.
		add_filter( 'term_link', array( $this, 'rewrite_term_link' ), 10, 3 );

		// Priority 9 — fires BEFORE WooCommerce (priority 10).
		// Only acts on products with NO category: replaces the unresolved
		// %product_cat% placeholder with a fallback slug so WooCommerce never
		// sees it and cannot append the product slug a second time.
		add_filter( 'post_type_link', array( $this, 'fix_uncategorized_product_permalink' ), 9, 2 );

		// Priority 20 — fires AFTER WooCommerce (priority 10).
		// Only acts on products WITH a category: our term_link filter returns a
		// full URL which causes WooCommerce to double the product slug at the end.
		// Detects /{slug}/{slug}/ and collapses it to /{slug}/.
		add_filter( 'post_type_link', array( $this, 'fix_duplicate_product_slug' ), 20, 2 );
	}

	// -------------------------------------------------------------------------
	// Taxonomy registration
	// -------------------------------------------------------------------------

	/**
	 * Sets the product_cat rewrite slug to an empty string so WooCommerce
	 * does not prepend any base segment to the term URLs.
	 *
	 * @param array<string, mixed> $args Taxonomy registration arguments.
	 * @return array<string, mixed>
	 */
	public function remove_category_base( array $args ): array {
		$args['rewrite'] = array(
			'slug'         => '',
			'with_front'   => false,
			'hierarchical' => true,
		);

		return $args;
	}

	// -------------------------------------------------------------------------
	// Rewrite rules
	// -------------------------------------------------------------------------

	/**
	 * Registers one rewrite rule per supported hierarchy level, from deepest
	 * to shallowest, at 'top' priority so they are evaluated before WordPress
	 * default rules.
	 *
	 * In a subdirectory multisite, WordPress strips the subsite path prefix
	 * (e.g. /en/) before applying rewrite rules, so no special handling is
	 * needed — each subsite sees only its own relative URL.
	 *
	 * In a subdomain or mapped-domain multisite, each subsite has its own
	 * home_url() and rewrite table, so rules are equally isolated.
	 *
	 * @return void
	 */
	public function add_rewrite_rules(): void {
		// A single rule per total segment count handles both category-only URLs
		// and category+product URLs. The last captured segment is always stored
		// in PRODUCT_VAR; handle_category_request decides at runtime whether it
		// is a deeper category level or a product slug by attempting to resolve
		// the full slug chain first as a product_cat term hierarchy, and only
		// treating the last segment as a product when the chain resolves.
		//
		// Having a separate category-only rule with the same N-segment pattern
		// as a category+product rule would cause a silent collision — WordPress
		// keeps only one rule per regex — so one rule per total segment count
		// avoids this entirely.
		//
		// Registered from deepest (MAX_LEVELS+1 segments) to shallowest (2
		// segments) so more-specific patterns are evaluated first.
		for ( $total = self::MAX_LEVELS + 1; $total >= 2; $total-- ) {
			$params     = array();
			$cat_levels = $total - 1;

			for ( $i = 1; $i <= $cat_levels; $i++ ) {
				$params[] = self::VAR_PREFIX . $i . '=$matches[' . $i . ']';
			}
			$params[] = self::PRODUCT_VAR . '=$matches[' . $total . ']';

			add_rewrite_rule(
				'^' . implode( '/', array_fill( 0, $total, '([^/]+)' ) ) . '/?$',
				'index.php?' . implode( '&', $params ),
				'top'
			);
		}

		// Single-segment rule: can only be a root-level product_cat term.
		// Products always appear under at least one category level, so a
		// single slug never needs PRODUCT_VAR.
		add_rewrite_rule(
			'^([^/]+)/?$',
			'index.php?' . self::VAR_PREFIX . '1=$matches[1]',
			'top'
		);
	}

	/**
	 * Registers the custom query vars so WordPress passes them through to
	 * the request handler.
	 *
	 * @param string[] $vars Existing public query vars.
	 * @return string[]
	 */
	public function register_query_vars( array $vars ): array {
		for ( $i = 1; $i <= self::MAX_LEVELS; $i++ ) {
			$vars[] = self::VAR_PREFIX . $i;
		}
		$vars[] = self::PRODUCT_VAR;
		return $vars;
	}

	// -------------------------------------------------------------------------
	// Request handling
	// -------------------------------------------------------------------------

	/**
	 * Intercepts the parsed request. When our custom query vars are present,
	 * resolves the slug hierarchy against the database. On a category match,
	 * sets the product_cat query var. On a product match, sets post_type and
	 * name. When nothing matches, delegates to reparse_with_wp_rules() so
	 * pages, posts and other CPTs are resolved correctly without interference.
	 *
	 * @param array<string, mixed> $query_vars Parsed query vars.
	 * @return array<string, mixed>
	 */
	public function handle_category_request( array $query_vars ): array {
		$first_var = self::VAR_PREFIX . '1';

		// Bail early when our vars are absent — nothing to do.
		if ( ! isset( $query_vars[ $first_var ] ) || '' === $query_vars[ $first_var ] ) {
			return $query_vars;
		}

		// Collect all non-empty category level vars.
		$cat_slugs = array();
		for ( $i = 1; $i <= self::MAX_LEVELS; $i++ ) {
			$var   = self::VAR_PREFIX . $i;
			$value = isset( $query_vars[ $var ] ) ? sanitize_title( $query_vars[ $var ] ) : '';
			if ( '' === $value ) {
				break;
			}
			$cat_slugs[] = $value;
		}

		$last_slug = isset( $query_vars[ self::PRODUCT_VAR ] )
			? sanitize_title( $query_vars[ self::PRODUCT_VAR ] )
			: '';

		// Remove all our custom vars before any return — WordPress must not see them.
		for ( $i = 1; $i <= self::MAX_LEVELS; $i++ ) {
			unset( $query_vars[ self::VAR_PREFIX . $i ] );
		}
		unset( $query_vars[ self::PRODUCT_VAR ] );

		// Build the full slug list for fallback resolution.
		$all_slugs = '' !== $last_slug
			? array_merge( $cat_slugs, array( $last_slug ) )
			: $cat_slugs;

		// Bail out immediately for any WooCommerce special page (shop, cart,
		// checkout, my-account, etc.) so WooCommerce handles them natively.
		// We check this before any DB lookup to keep the fast path fast.
		if ( $this->is_woocommerce_special_page( $all_slugs ) ) {
			return $this->reparse_with_wp_rules( $query_vars, $all_slugs );
		}

		// --- Single-segment rule: only PRODUCT_VAR is absent, cat_slugs has 1 item ---
		// This path is taken for /root-cat/ URLs (single-segment rule never sets
		// PRODUCT_VAR). Resolve as a root-level category only.
		if ( '' === $last_slug ) {
			$term_slug = $this->resolve_deepest_term( $cat_slugs );
			if ( null !== $term_slug ) {
				$query_vars['product_cat'] = $term_slug;
				return $query_vars;
			}
			return $this->reparse_with_wp_rules( $query_vars, $all_slugs );
		}

		// --- Multi-segment rule: PRODUCT_VAR holds the last URL segment ---
		//
		// Ambiguity: /a/b/ could be a 2-level category OR /cat-a/ + product /b/.
		// Resolution order:
		//   1. Try the full slug chain (cat_slugs + last_slug) as a category hierarchy.
		//      Handles deep category URLs like /cat/subcat/subsubcat/.
		//   2. Try cat_slugs as a category (or the fallback slug) + last_slug as a product.
		//   3. Otherwise hand off to WordPress native rules.

		// 1. Full chain as a category?
		$term_slug = $this->resolve_deepest_term( $all_slugs );
		if ( null !== $term_slug ) {
			$query_vars['product_cat'] = $term_slug;
			return $query_vars;
		}

		// 2. cat_slugs as category + last_slug as product, OR uncategorised fallback.
		//    Both cases resolve last_slug as a product slug. The difference is
		//    how we validate the category prefix:
		//      a) cat_slugs resolves to a real product_cat term, OR
		//      b) cat_slugs is exactly [fallback-slug] (e.g. ["uncategorized"]) for
		//         products that have no category assigned.
		$fallback      = _x( 'uncategorized', 'product permalink fallback slug', 'kt-product-cat-slugs' );
		$cat_term_slug = $this->resolve_deepest_term( $cat_slugs );
		$valid_cat     = null !== $cat_term_slug;
		$is_fallback   = ( 1 === count( $cat_slugs ) && $cat_slugs[0] === $fallback );

		if ( $valid_cat || $is_fallback ) {
			$posts = get_posts(
				array(
					'name'           => $last_slug,
					'post_type'      => 'product',
					'post_status'    => 'publish',
					'posts_per_page' => 1,
					'no_found_rows'  => true,
					'fields'         => 'ids',
				)
			);

			if ( ! empty( $posts ) ) {
				$query_vars['post_type'] = 'product';
				$query_vars['name']      = $last_slug;
				$query_vars['page']      = '';
				return $query_vars;
			}
		}

		// 3. Not ours — let WordPress resolve normally.
		return $this->reparse_with_wp_rules( $query_vars, $all_slugs );
	}

	/**
	 * Returns true when the given slug path matches a WooCommerce special page
	 * (shop, cart, checkout, my-account, terms).
	 *
	 * Results are cached in $wc_page_paths on the first call so subsequent
	 * invocations within the same request incur no database work.
	 *
	 * Checked before any category/product DB lookup so these pages are never
	 * accidentally intercepted and are always resolved by WordPress natively.
	 *
	 * @param string[] $slugs All URL segments for the current request.
	 * @return bool
	 */
	private function is_woocommerce_special_page( array $slugs ): bool {
		if ( empty( $slugs ) ) {
			return false;
		}

		// Build the path set once and reuse for every subsequent call.
		if ( null === $this->wc_page_paths ) {
			$this->wc_page_paths = array();

			$wc_page_options = array(
				'woocommerce_shop_page_id',
				'woocommerce_cart_page_id',
				'woocommerce_checkout_page_id',
				'woocommerce_myaccount_page_id',
				'woocommerce_terms_page_id',
			);

			foreach ( $wc_page_options as $option ) {
				$page_id = (int) get_option( $option );
				if ( $page_id <= 0 ) {
					continue;
				}
				// get_page_uri() returns the full hierarchical path (parent/child)
				// and is backed by WP object cache, so no extra DB hit after the
				// first call. It returns false for non-existent pages.
				$uri = get_page_uri( $page_id );
				if ( false !== $uri && '' !== $uri ) {
					$this->wc_page_paths[ rtrim( $uri, '/' ) ] = true;
				}
			}
		}

		return isset( $this->wc_page_paths[ implode( '/', $slugs ) ] );
	}

	/**
	 * Resolves a URL path that did not match any product category or product
	 * against WordPress native content types: pages (hierarchical), posts,
	 * and any other publicly queryable post type.
	 *
	 * Called as the final fallback in handle_category_request() when our own
	 * category and product lookups all fail. By using the WordPress API
	 * directly we avoid re-implementing WP::parse_request() and stay
	 * compatible with future WordPress changes.
	 *
	 * @param array<string, mixed> $query_vars Cleaned query vars (our custom vars already removed).
	 * @param string[]             $slugs      All URL segments in order, root to leaf.
	 * @return array<string, mixed>
	 */
	private function reparse_with_wp_rules( array $query_vars, array $slugs ): array {
		if ( empty( $slugs ) ) {
			return $query_vars;
		}

		$path = implode( '/', $slugs );

		// Build the post type list once per request. Includes all publicly
		// queryable types plus 'page' (which may not be publicly_queryable on
		// every install), minus 'product' (already handled by the caller).
		if ( null === $this->public_post_types ) {
			$types = array_values( get_post_types( array( 'publicly_queryable' => true ) ) );
			$types[] = 'page';
			$this->public_post_types = array_values(
				array_unique( array_diff( $types, array( 'product' ) ) )
			);
		}

		// get_page_by_path() resolves hierarchical slugs for pages and any
		// post type that supports page-like paths, including single-segment
		// posts when the site uses /%postname%/ permalinks.
		foreach ( $this->public_post_types as $post_type ) {
			$post = get_page_by_path( $path, OBJECT, $post_type );
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}

			if ( 'page' === $post->post_type ) {
				// Use page_id, not pagename. The request filter fires after
				// WP::parse_request() has already converted pagename→page_id,
				// so setting pagename here is too late. page_id is also what
				// WooCommerce's is_shop() relies on.
				$query_vars['page_id'] = $post->ID;
				$query_vars['page']    = '';
			} else {
				$query_vars['post_type'] = $post->post_type;
				$query_vars['name']      = $post->post_name;
				$query_vars['page']      = '';
			}

			return $query_vars;
		}

		// Nothing found — return vars unchanged; WordPress will show a 404.
		return $query_vars;
	}

	// -------------------------------------------------------------------------
	// Term link rewriting
	// -------------------------------------------------------------------------

	/**
	 * Rewrites the term link for product_cat terms to expose the full
	 * ancestor hierarchy without any base slug.
	 *
	 * Uses home_url() which in a multisite context always returns the correct
	 * URL for the current subsite, regardless of the network configuration
	 * (subdirectory, subdomain or mapped domain).
	 *
	 * @param string   $termlink The original term permalink.
	 * @param \WP_Term $term     The term object.
	 * @param string   $taxonomy The taxonomy slug.
	 * @return string
	 */
	public function rewrite_term_link( string $termlink, \WP_Term $term, string $taxonomy ): string {
		if ( 'product_cat' !== $taxonomy ) {
			return $termlink;
		}

		$slugs = $this->get_term_ancestor_slugs( $term );

		return trailingslashit( home_url( implode( '/', $slugs ) ) );
	}

	// -------------------------------------------------------------------------
	// Product permalink fix
	// -------------------------------------------------------------------------

	/**
	 * Replaces %product_cat% with a fallback slug for products with no category.
	 *
	 * Runs at priority 9, before WooCommerce's post_type_link handler at 10.
	 * If we let WooCommerce process a permalink that still contains %product_cat%
	 * it will substitute it and then append the product slug again, producing
	 * a doubled path. By replacing the placeholder here first, WooCommerce finds
	 * no placeholder to process and builds the URL correctly.
	 *
	 * Bails immediately unless the post is a product with no category terms.
	 *
	 * @param string   $permalink The permalink, possibly with %product_cat%.
	 * @param \WP_Post $post      The post object.
	 * @return string
	 */
	public function fix_uncategorized_product_permalink( string $permalink, \WP_Post $post ): string {
		if ( 'product' !== $post->post_type ) {
			return $permalink;
		}

		if ( ! str_contains( $permalink, '%product_cat%' ) ) {
			return $permalink;
		}

		$terms = get_the_terms( $post->ID, 'product_cat' );
		if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) {
			return $permalink;
		}

		return str_replace(
			'%product_cat%',
			_x( 'uncategorized', 'product permalink fallback slug', 'kt-product-cat-slugs' ),
			$permalink
		);
	}

	/**
	 * Removes the duplicated product slug that WooCommerce appends when our
	 * term_link filter returns a full URL for the category.
	 *
	 * Runs at priority 20, after WooCommerce's post_type_link handler at 10.
	 * WooCommerce uses the category term link (which our filter returns as a
	 * full URL including the product slug from the permalink structure) and then
	 * appends the product slug again, resulting in /cat/product-slug/product-slug/.
	 *
	 * Bails immediately unless the post is a product and the doubled slug is found.
	 *
	 * @param string   $permalink The permalink built by WooCommerce.
	 * @param \WP_Post $post      The post object.
	 * @return string
	 */
	public function fix_duplicate_product_slug( string $permalink, \WP_Post $post ): string {
		if ( 'product' !== $post->post_type ) {
			return $permalink;
		}

		$slug = $post->post_name;

		// preg_replace() returns the original string unchanged when there is no
		// match, so a separate preg_match() guard is unnecessary.
		return (string) preg_replace(
			'#/' . preg_quote( $slug, '#' ) . '/' . preg_quote( $slug, '#' ) . '/?$#',
			'/' . $slug . '/',
			$permalink
		);
	}

	// -------------------------------------------------------------------------
	// Term resolution helpers (all results memoized)
	// -------------------------------------------------------------------------

	/**
	 * Walks the product_cat hierarchy using the provided slug segments and
	 * returns the slug of the deepest matching term, or null if any level
	 * fails to resolve.
	 *
	 * Results — including negative lookups — are stored in $term_slug_cache
	 * so repeated calls with the same path within a single request never hit
	 * the database more than once.
	 *
	 * @param string[] $slugs Ordered slugs from root to leaf.
	 * @return string|null
	 */
	private function resolve_deepest_term( array $slugs ): ?string {
		if ( empty( $slugs ) ) {
			return null;
		}

		$cache_key = implode( '/', $slugs );

		if ( array_key_exists( $cache_key, $this->term_slug_cache ) ) {
			$cached = $this->term_slug_cache[ $cache_key ];
			return false !== $cached ? $cached : null;
		}

		$result = $this->do_resolve_deepest_term( $slugs );

		// Store false for negative lookups so they are never re-queried.
		$this->term_slug_cache[ $cache_key ] = null !== $result ? $result : false;

		return $result;
	}

	/**
	 * Internal resolution logic — called only on a cache miss.
	 *
	 * The top-level term is verified to have no parent (parent = 0) to prevent
	 * a deeper term whose slug happens to match from being resolved incorrectly
	 * as a root category.
	 *
	 * @param string[] $slugs Ordered slugs from root to leaf. Must not be empty.
	 * @return string|null
	 */
	private function do_resolve_deepest_term( array $slugs ): ?string {
		// Retrieve the root-level term, enforcing parent = 0.
		$root_terms = get_terms(
			array(
				'taxonomy'               => 'product_cat',
				'slug'                   => $slugs[0],
				'parent'                 => 0,
				'hide_empty'             => false,
				'number'                 => 1,
				'update_term_meta_cache' => false,
			)
		);

		if ( is_wp_error( $root_terms ) || empty( $root_terms ) || ! $root_terms[0] instanceof \WP_Term ) {
			return null;
		}

		$current = $root_terms[0];

		// Walk down the hierarchy validating each level as a direct child.
		for ( $i = 1, $total = count( $slugs ); $i < $total; $i++ ) {
			$child = $this->get_child_term_by_slug( $slugs[ $i ], $current->term_id );

			if ( null === $child ) {
				return null;
			}

			$current = $child;
		}

		return $current->slug;
	}

	/**
	 * Returns a product_cat term by slug that is a direct child of the given
	 * parent term ID. Results are memoized in $child_term_cache.
	 *
	 * @param string $slug      Term slug to search for.
	 * @param int    $parent_id Expected parent term ID.
	 * @return \WP_Term|null
	 */
	private function get_child_term_by_slug( string $slug, int $parent_id ): ?\WP_Term {
		$cache_key = $parent_id . ':' . $slug;

		if ( array_key_exists( $cache_key, $this->child_term_cache ) ) {
			return $this->child_term_cache[ $cache_key ];
		}

		$terms = get_terms(
			array(
				'taxonomy'               => 'product_cat',
				'slug'                   => $slug,
				'parent'                 => $parent_id,
				'hide_empty'             => false,
				'number'                 => 1,
				'update_term_meta_cache' => false,
			)
		);

		$term = ( ! is_wp_error( $terms ) && ! empty( $terms ) && $terms[0] instanceof \WP_Term )
			? $terms[0]
			: null;

		$this->child_term_cache[ $cache_key ] = $term;

		return $term;
	}

	/**
	 * Builds an ordered array of slugs from the root ancestor down to the
	 * given term (inclusive). Results are memoized in $ancestor_cache by
	 * term ID.
	 *
	 * Ancestors are collected in reverse order (leaf → root) and then
	 * reversed once, avoiding the O(n) cost of array_unshift() on each
	 * iteration.
	 *
	 * A depth guard (MAX_LEVELS iterations) prevents an infinite loop in the
	 * unlikely case of circular parent references in the database.
	 *
	 * @param \WP_Term $term The term whose ancestor chain is needed.
	 * @return string[]
	 */
	private function get_term_ancestor_slugs( \WP_Term $term ): array {
		if ( isset( $this->ancestor_cache[ $term->term_id ] ) ) {
			return $this->ancestor_cache[ $term->term_id ];
		}

		// Collect slugs from leaf to root, then reverse once.
		$slugs    = array( $term->slug );
		$ancestor = $term;
		$depth    = 0;

		while ( $ancestor->parent > 0 && $depth < self::MAX_LEVELS ) {
			// get_term() is backed by WP's persistent object cache automatically
			// (Redis / Memcached), so no manual caching is needed here.
			$parent = get_term( $ancestor->parent, 'product_cat' );

			if ( ! $parent instanceof \WP_Term ) {
				break;
			}

			$slugs[]  = $parent->slug;
			$ancestor = $parent;
			++$depth;
		}

		$slugs = array_reverse( $slugs );

		$this->ancestor_cache[ $term->term_id ] = $slugs;

		return $slugs;
	}

	// -------------------------------------------------------------------------
	// Maintenance
	// -------------------------------------------------------------------------

	/**
	 * Flushes rewrite rules once when the flush flag option is set.
	 *
	 * Hooked to init:99 so it fires after all plugins have registered their
	 * rewrite rules, ensuring the flushed ruleset is complete. Running on
	 * init (rather than woocommerce_init) guarantees execution on every
	 * request type: front-end, admin, REST API and AJAX.
	 *
	 * The flag is written on plugin activation (autoload disabled) and
	 * deleted immediately after flushing.
	 *
	 * @return void
	 */
	public function maybe_flush_rewrite_rules(): void {
		if ( get_option( self::FLUSH_OPTION, false ) ) {
			flush_rewrite_rules();
			delete_option( self::FLUSH_OPTION );
		}
	}

	/**
	 * Loads the plugin text domain for translations.
	 *
	 * WordPress auto-loads text domains since 6.1 when Text Domain is declared
	 * in the plugin header. This explicit call is kept to support custom
	 * language path overrides via the load_textdomain_mofile filter.
	 *
	 * @return void
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain(
			'kt-product-cat-slugs',
			false,
			dirname( plugin_basename( __FILE__ ) ) . '/languages'
		);
	}
}

// -------------------------------------------------------------------------
// Activation / deactivation hooks
// -------------------------------------------------------------------------

/**
 * Sets the flush flag on activation so rewrite rules are regenerated on the
 * next request.
 *
 * Autoload is explicitly set to false: this is a one-shot transient flag
 * that is deleted immediately after use and must not be loaded on every
 * request via the autoload cache.
 *
 * @return void
 */
function kt_pcs_activate(): void {
	update_option( Product_Category_Slugs::FLUSH_OPTION, true, false );
}
register_activation_hook( __FILE__, __NAMESPACE__ . '\kt_pcs_activate' );

/**
 * Flushes rewrite rules on deactivation to restore WordPress defaults.
 *
 * @return void
 */
function kt_pcs_deactivate(): void {
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, __NAMESPACE__ . '\kt_pcs_deactivate' );

// -------------------------------------------------------------------------
// Bootstrap
// -------------------------------------------------------------------------

/**
 * Initialises the plugin after all plugins are loaded to ensure WooCommerce
 * is available before we hook into its taxonomy registration.
 *
 * The woocommerce_taxonomy_args_product_cat filter is registered inside
 * plugins_loaded, which guarantees it fires before WooCommerce registers
 * the product_cat taxonomy on init:5.
 */
add_action(
	'plugins_loaded',
	static function (): void {
		if ( class_exists( 'WooCommerce' ) ) {
			Product_Category_Slugs::instance();
		}
	}
);

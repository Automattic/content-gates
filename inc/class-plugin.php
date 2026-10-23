<?php
/**
 * Plugin bootstrap.
 *
 * @package ContentGates
 */

declare( strict_types=1 );

namespace Automattic\VIP\ContentGates;

use Automattic\VIP\ContentGates\Admin\Edge_Notice;
use Automattic\VIP\ContentGates\Admin\Segment_Admin;
use Automattic\VIP\ContentGates\Admin\View_As;
use Automattic\VIP\ContentGates\CLI\Commands;
use Automattic\VIP\ContentGates\Editor\Editor_Assets;
use Automattic\VIP\ContentGates\Membership\Declared_Set;
use Automattic\VIP\ContentGates\Membership\Membership;
use Automattic\VIP\ContentGates\Platform\Platform;
use Automattic\VIP\ContentGates\Providers\Newspack_Bridge;
use Automattic\VIP\ContentGates\Rendering\Block_Gate;
use Automattic\VIP\ContentGates\Rendering\Content_Meta;
use Automattic\VIP\ContentGates\Rendering\Excerpt_Guard;
use Automattic\VIP\ContentGates\Rendering\Structured_Data;
use Automattic\VIP\ContentGates\REST\Segments_Controller;
use Automattic\VIP\ContentGates\Segments\Crawler_Config;
use Automattic\VIP\ContentGates\Segments\Segment_Registry;

/**
 * Wires plugin components to WordPress hooks.
 *
 * Holds no gating logic itself. Each component is constructed and registered
 * here so the boot order is visible in one place:
 *
 * 1. Platform: load `Vary_Cache` (platform copy, or the bundled copy in `local`).
 * 2. Segments: the `cg_segment` post type, lookup, crawler mirror.
 * 3. Membership: declared set, reads and writes, response headers.
 * 4. Rendering: Group enforcement, save-time meta, excerpt guard, JSON-LD.
 * 5. Surfaces: REST, admin, editor, WP-CLI, provider bridges.
 *
 * @since 0.1.0
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Whether boot() has run.
	 *
	 * @var bool
	 */
	private bool $booted = false;

	/**
	 * Site-wide purge requester.
	 *
	 * @var Cache_Purge
	 */
	private Cache_Purge $cache_purge;

	/**
	 * Platform loader.
	 *
	 * @var Platform
	 */
	private Platform $platform;

	/**
	 * Crawler configuration.
	 *
	 * @var Crawler_Config
	 */
	private Crawler_Config $crawlers;

	/**
	 * Segment registry.
	 *
	 * @var Segment_Registry
	 */
	private Segment_Registry $segments;

	/**
	 * Declared set for this response.
	 *
	 * @var Declared_Set
	 */
	private Declared_Set $declared;

	/**
	 * Membership reads and writes.
	 *
	 * @var Membership
	 */
	private Membership $membership;

	/**
	 * Group block enforcement.
	 *
	 * @var Block_Gate
	 */
	private Block_Gate $gate;

	/**
	 * Save-time declared set computation.
	 *
	 * @var Content_Meta
	 */
	private Content_Meta $content_meta;

	/**
	 * Excerpt guard.
	 *
	 * @var Excerpt_Guard
	 */
	private Excerpt_Guard $excerpt_guard;

	/**
	 * Paywall JSON-LD.
	 *
	 * @var Structured_Data
	 */
	private Structured_Data $structured_data;

	/**
	 * Admin-bar View As switcher.
	 *
	 * @var View_As
	 */
	private View_As $view_as;


	/**
	 * Return the shared instance.
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Register hooks. Safe to call more than once; only the first call acts.
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}

		$this->booted = true;

		$this->platform = new Platform();
		$this->platform->load();

		$this->crawlers = new Crawler_Config();
		$this->segments = new Segment_Registry( $this->crawlers );
		$this->segments->register();

		$this->declared = new Declared_Set( $this->segments );
		$this->declared->register();

		$this->membership = new Membership( $this->platform, $this->segments, $this->declared );
		$this->membership->register();

		$this->gate = new Block_Gate( $this->membership, $this->segments, $this->declared );
		$this->gate->register();

		$this->content_meta = new Content_Meta( $this->segments );
		$this->content_meta->register();

		$this->excerpt_guard = new Excerpt_Guard( $this->gate, $this->declared );
		$this->excerpt_guard->register();

		$this->structured_data = new Structured_Data( $this->gate );
		$this->structured_data->register();

		$this->cache_purge = new Cache_Purge();
		$this->cache_purge->register();

		( new Segments_Controller( $this->segments ) )->register();
		( new Segment_Admin( $this->segments, $this->crawlers ) )->register();
		( new Edge_Notice( $this->crawlers ) )->register();
		( new Editor_Assets() )->register();
		( new Newspack_Bridge( $this->membership ) )->register();

		$this->view_as = new View_As( $this->membership, $this->segments );
		$this->view_as->register();

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			Commands::register( $this );
		}

		/**
		 * Fires after Content Gates has registered its hooks.
		 *
		 * Providers should hook here to register themselves.
		 *
		 * @since 0.1.0
		 *
		 * @param Plugin $plugin The plugin instance.
		 */
		do_action( 'content_gates_loaded', $this );
	}

	/**
	 * Whether boot() has run.
	 */
	public function is_booted(): bool {
		return $this->booted;
	}

	/**
	 * Platform loader.
	 */
	public function platform(): Platform {
		return $this->platform;
	}

	/**
	 * Crawler configuration.
	 */
	public function crawlers(): Crawler_Config {
		return $this->crawlers;
	}

	/**
	 * Segment registry.
	 */
	public function segments(): Segment_Registry {
		return $this->segments;
	}

	/**
	 * Declared set for this response.
	 */
	public function declared(): Declared_Set {
		return $this->declared;
	}

	/**
	 * Membership reads and writes.
	 */
	public function membership(): Membership {
		return $this->membership;
	}

	/**
	 * Group block enforcement.
	 */
	public function gate(): Block_Gate {
		return $this->gate;
	}

	/**
	 * Save-time declared set computation.
	 */
	public function content_meta(): Content_Meta {
		return $this->content_meta;
	}

	/**
	 * Excerpt guard.
	 */
	public function excerpt_guard(): Excerpt_Guard {
		return $this->excerpt_guard;
	}

	/**
	 * Admin-bar View As switcher.
	 */
	public function view_as(): View_As {
		return $this->view_as;
	}

	/**
	 * Replace the per-request components. For tests, which run many requests in one process.
	 *
	 * @since 0.1.0
	 */
	public function reset_request_state(): void {
		remove_action( 'wp', array( $this->declared, 'finalize_page' ), PHP_INT_MAX );
		remove_filter( 'the_content', array( $this->declared, 'merge_rendering_post' ), 1 );
		remove_filter( 'rest_pre_serve_request', array( $this->declared, 'finalize_rest_if_served' ), PHP_INT_MAX );
		remove_filter( 'rest_pre_echo_response', array( $this->declared, 'finalize_rest' ), PHP_INT_MAX );
		remove_action( 'init', array( $this->membership, 'register_groups' ), 5 );
		remove_action( 'send_headers', array( $this->membership, 'close_writes' ), PHP_INT_MAX - 1 );
		remove_action( 'content_gates_rest_headers_sent', array( $this->membership, 'send_rest_headers' ) );
		remove_action( 'vip_vary_cache_did_send_headers', array( $this->membership, 'expire_empty_cookie' ), 10 );
		remove_filter( 'register_block_type_args', array( $this->gate, 'register_attributes' ), 10 );
		remove_filter( 'pre_render_block', array( $this->gate, 'withhold' ), 10 );
		remove_filter( 'render_block_data', array( $this->gate, 'stamp_index' ), 10 );
		remove_filter( 'render_block_' . Block_Gate::BLOCK, array( $this->gate, 'mark_for_crawlers' ), 10 );
		remove_filter( 'get_the_excerpt', array( $this->excerpt_guard, 'build' ), 9 );
		remove_filter( 'get_the_excerpt', array( $this->excerpt_guard, 'restore' ), 11 );
		remove_action( 'wp_head', array( $this->structured_data, 'print_json_ld' ), 10 );

		$this->segments->flush();
		$this->cache_purge->reset();

		$this->declared = new Declared_Set( $this->segments );
		$this->declared->register();

		$this->membership = new Membership( $this->platform, $this->segments, $this->declared );
		$this->membership->register();

		$this->gate = new Block_Gate( $this->membership, $this->segments, $this->declared );
		$this->gate->register();

		$this->excerpt_guard = new Excerpt_Guard( $this->gate, $this->declared );
		$this->excerpt_guard->register();

		$this->structured_data = new Structured_Data( $this->gate );
		$this->structured_data->register();

		$this->view_as->rebind( $this->membership );

		Headers::reset();
	}
}

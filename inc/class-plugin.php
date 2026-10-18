<?php
/**
 * Plugin bootstrap.
 *
 * @package ContentGates
 */

declare( strict_types=1 );

namespace ContentGates;

use ContentGates\Admin\Segment_Admin;
use ContentGates\Editor\Group_Block_Editor;
use ContentGates\Membership\Cache_Adapter_Factory;
use ContentGates\Membership\Consulted_Segments;
use ContentGates\Membership\Membership_Service;
use ContentGates\Rendering\Block_Enforcer;
use ContentGates\Rendering\Response_Finalizer;
use ContentGates\REST\Membership_Controller;
use ContentGates\Segments\Segment_Repository;

/**
 * Wires plugin components to WordPress hooks.
 *
 * Holds no gating logic itself. Each component (provider registry, segment
 * layer, render layer, admin UI) is registered here so the boot order is
 * visible in one place.
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
	 * Segment repository.
	 *
	 * @var Segment_Repository|null
	 */
	private ?Segment_Repository $segments = null;

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

		$segments = new Segment_Repository();
		$segments->register_hooks();
		$this->segments = $segments;

		$consulted  = new Consulted_Segments();
		$membership = new Membership_Service( $segments, Cache_Adapter_Factory::create(), $consulted );
		Membership_Service::set_instance( $membership );
		$membership->register_hooks();

		( new Segment_Admin( $segments ) )->register_hooks();
		( new Group_Block_Editor( $segments ) )->register_hooks();
		( new Block_Enforcer( $segments ) )->register_hooks();
		( new Response_Finalizer( $consulted ) )->register_hooks();
		( new Membership_Controller( $membership ) )->register_hooks();

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
	 * Return the shared segment repository.
	 *
	 * @return Segment_Repository|null Null before the plugin has booted.
	 */
	public function segments(): ?Segment_Repository {
		return $this->segments;
	}
}

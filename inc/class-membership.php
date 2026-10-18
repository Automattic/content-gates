<?php
/**
 * Versioned provider API and request-local read tracking.
 *
 * @package ContentGates
 */

declare( strict_types=1 );
namespace ContentGates;

use ContentGates\Cache\Transport;
use ContentGates\Cache\VIP_Adapter;

/** All rendering reads must pass through this service. */
final class Membership {
	/**
	 * Shared request service.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;
	/**
	 * Platform transport.
	 *
	 * @var Transport
	 */
	private Transport $transport;
	/**
	 * Registered groups.
	 *
	 * @var array<string, bool>
	 */
	private array $registered = array();
	/**
	 * Consulted values, including negative reads.
	 *
	 * @var array<string, string>
	 */
	private array $consulted = array();
	/**
	 * Registration/parse lifecycle completed.
	 *
	 * @var bool
	 */
	private bool $ready = false;
	/**
	 * Mutation window closed.
	 *
	 * @var bool
	 */
	private bool $closed = false;
	/**
	 * Report finalized.
	 *
	 * @var bool
	 */
	private bool $frozen = false;
	/**
	 * Diagnostic codes, never credentials.
	 *
	 * @var list<string>
	 */
	private array $problems = array();

	/**
	 * Explicit transport injection for tests; production uses VIP only.
	 *
	 * @param Transport $transport Platform transport.
	 */
	public function __construct( Transport $transport ) {
		$this->transport = $transport;
	}

	/** Get the current request service. */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self( new VIP_Adapter() );
		}
		return self::$instance;
	}

	/**
	 * Replace request state for isolated tests/long-lived workers.
	 *
	 * @param self|null $service Explicit service or default VIP adapter.
	 */
	public static function reset( ?self $service = null ): void {
		self::$instance = $service;
	}

	/** Register before VIP parses cookies at init priority 10. */
	public function register(): void {
		foreach ( Segments::all() as $segment ) {
			$group = Segments::group( $segment->ID );
			if ( ! isset( $this->registered[ $group ] ) && $this->transport->register( $group ) ) {
				$this->registered[ $group ] = true;
			}
		}
	}

	/** Open the provider phase after platform cookie parsing. */
	public function ready(): void {
		$this->ready = true;
		/**
		 * Providers may now refresh externally determined memberships.
		 *
		 * @since 0.1.0
		 * @param Membership $membership Request service (contract version 1).
		 */
		do_action( 'content_gates_provider_ready', $this );
	}

	/** Close mutations before send_headers. */
	public function close(): void {
		$this->closed = true;
	}

	/**
	 * Record and evaluate one membership read. Login grants nothing.
	 *
	 * @param int $id Site-local segment ID.
	 */
	public function contains( int $id ): bool {
		$group = Segments::group( $id );
		$post  = Segments::get( $id );
		if ( $this->frozen ) {
			$this->problem( 'late_read' );
			return false;
		}
		$member                    = $this->ready && null !== $post && 'publish' === $post->post_status && isset( $this->registered[ $group ] ) && $this->transport->contains( $group );
		$this->consulted[ $group ] = $member ? '1' : 'none';
		if ( null === $post ) {
			$this->problem( 'invalid_segment' );
		}
		return $member;
	}

	/**
	 * Assign membership for the current reader, never an arbitrary user ID.
	 *
	 * @param int  $id Segment ID.
	 * @param bool $member Grant or revoke.
	 * @param int  $version Independent API version.
	 * @return true|\WP_Error
	 */
	public function assign( int $id, bool $member = true, int $version = 1 ) {
		if ( 1 !== $version ) {
			return new \WP_Error( 'content_gates_version', 'Unsupported provider contract version.' );
		}
		if ( $this->closed || $this->frozen || ( 'cli' !== PHP_SAPI && headers_sent() ) ) {
			return new \WP_Error( 'content_gates_too_late', 'Membership must be assigned before response headers.' );
		}
		if ( ! $this->ready ) {
			return new \WP_Error( 'content_gates_not_ready', 'Wait for content_gates_provider_ready.' );
		}
		$post = Segments::get( $id );
		if ( null === $post ) {
			return new \WP_Error( 'content_gates_invalid_segment', 'Unknown segment.' );
		}
		if ( $member && 'publish' !== $post->post_status ) {
			return new \WP_Error( 'content_gates_inactive_segment', 'Inactive segments cannot be granted.' );
		}
		if ( ! $this->transport->available() ) {
			return new \WP_Error( 'content_gates_unavailable', 'VIP Vary_Cache is unavailable.' );
		}
		$group = Segments::group( $id );
		if ( ! isset( $this->registered[ $group ] ) ) {
			return new \WP_Error( 'content_gates_not_registered', 'New definitions become available on the next request.' );
		}
		if ( array_key_exists( $group, $this->consulted ) ) {
			return new \WP_Error( 'content_gates_already_read', 'Cannot change membership after rendering read it.' );
		}
		return $this->transport->assign( $group, $member );
	}

	/** Commit cookies on nonstandard response paths using VIP's writer. */
	public function send(): void {
		if ( ! $this->closed ) {
			$this->close();
			$this->transport->send();
		}
	}

	/**
	 * Flag incomplete lifecycle/integration. Origin is always no-store.
	 *
	 * @param string $code Safe diagnostic code.
	 */
	public function problem( string $code ): void {
		$this->problems[] = $code;
	}

	/**
	 * Diagnostic codes, never membership cookies.
	 *
	 * @return list<string>
	 */
	public function problems(): array {
		return array_values( array_unique( $this->problems ) );
	}

	/**
	 * Freeze the exact per-response projection once.
	 *
	 * @return array<string, string>
	 */
	public function finalize(): array {
		if ( $this->frozen ) {
			return $this->consulted;
		}
		$original = array_keys( $this->consulted );
		/**
		 * Add conservative dependencies; removing observed reads is invalid.
		 *
		 * @since 0.1.0
		 * @param list<string> $groups Consulted stable identities.
		 */
		$filtered = apply_filters( 'content_gates_consulted_segments', $original );
		if ( ! is_array( $filtered ) || count( array_filter( $filtered, 'is_string' ) ) !== count( $filtered ) || array_diff( $original, $filtered ) ) {
			$this->problem( 'invalid_consulted_filter' );
		} else {
			foreach ( $filtered as $group ) {
				if ( ! is_string( $group ) || ! isset( $this->registered[ $group ] ) ) {
					$this->problem( 'invalid_consulted_filter' );
					continue;
				}
				if ( ! isset( $this->consulted[ $group ] ) ) {
					$this->consulted[ $group ] = $this->transport->contains( $group ) ? '1' : 'none';
				}
			}
		}
		ksort( $this->consulted );
		$this->frozen = true;
		/**
		 * Observes final dependency names, not a claim of edge integration.
		 *
		 * @since 0.1.0
		 * @param list<string> $groups Stable names consulted by this response.
		 * @param array<string,string> $projection Exact boolean projection.
		 */
		do_action( 'content_gates_finalize_variance', array_keys( $this->consulted ), $this->consulted );
		return $this->consulted;
	}
}

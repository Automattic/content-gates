<?php
/**
 * Newspack access-rule bridge.
 *
 * @package ContentGates
 */

declare( strict_types=1 );

namespace Automattic\VIP\ContentGates\Providers;

use Automattic\VIP\ContentGates\Membership\Membership;

/**
 * Maps Newspack access-rule evaluations to segment membership.
 *
 * Newspack decides eligibility (`Newspack\Access_Rules::evaluate_rule()`);
 * this bridge turns each decision into an assignment or removal on requests
 * the edge did not narrow, which in practice means logged-in requests and
 * Newspack's own POST flows (login, checkout, logout). Wiring it to those
 * events instead of `init` is the next step (docs/providers.md). Sites
 * configure the mapping through a filter:
 *
 *     add_filter( 'content_gates_newspack_rules', fn() => array(
 *         array( 'segment' => 'subscriber', 'rule' => 'subscription', 'value' => array( 123 ) ),
 *     ) );
 *
 * `evaluate_rule()` returns `true` for a rule that is not registered, so
 * the bridge checks `get_rule()` first and assigns nothing for a mapping
 * whose rule is missing. A failed registration must never open the gate.
 *
 * The bridge is inert when Newspack is not loaded. It has not run against
 * a live Newspack install yet; see docs/providers.md.
 *
 * @since 0.1.0
 */
final class Newspack_Bridge {

	public const RULES_CLASS = '\Newspack\Access_Rules';

	/**
	 * Constructor.
	 *
	 * @param Membership $membership Membership writes.
	 */
	public function __construct( private Membership $membership ) {}

	/**
	 * Add hooks.
	 *
	 * @since 0.1.0
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'sync' ), 20 );
	}

	/**
	 * Evaluate every mapped rule and assign or remove membership. Action callback for `init` 20.
	 *
	 * Priority 20 is after `Vary_Cache` parsed the cookie (10) and before
	 * `send_headers`, inside the write window.
	 *
	 * @since 0.1.0
	 */
	public function sync(): void {
		if ( ! class_exists( self::RULES_CLASS ) || Membership::request_is_narrowed() ) {
			return;
		}

		/**
		 * Filters the Newspack rule to segment mappings.
		 *
		 * Each item: `segment` (key or ID), `rule` (Newspack rule slug), and
		 * optional `value` (the rule's configured value, passed to the callback).
		 *
		 * @since 0.1.0
		 *
		 * @param array<int, array{segment: int|string, rule: string, value?: mixed}> $mappings Mappings.
		 */
		$mappings = apply_filters( 'content_gates_newspack_rules', array() );
		if ( ! is_array( $mappings ) || array() === $mappings ) {
			return;
		}

		$user_id = get_current_user_id();
		$rules   = self::RULES_CLASS;

		foreach ( $mappings as $mapping ) {
			if ( ! is_array( $mapping ) || ! isset( $mapping['segment'], $mapping['rule'] ) || ! is_string( $mapping['rule'] ) ) {
				continue;
			}

			$rule = $rules::get_rule( $mapping['rule'] );
			if ( empty( $rule ) ) {
				/**
				 * Fires when a mapped Newspack rule is not registered.
				 *
				 * Nothing is assigned for it: an unregistered rule evaluates to true in Newspack.
				 *
				 * @since 0.1.0
				 *
				 * @param string $slug Rule slug.
				 */
				do_action( 'content_gates_newspack_rule_missing', $mapping['rule'] );
				continue;
			}

			$eligible = (bool) $rules::evaluate_rule( $mapping['rule'], $mapping['value'] ?? null, $user_id );

			if ( $eligible ) {
				$this->membership->assign( $mapping['segment'] );
			} else {
				$this->membership->remove( $mapping['segment'] );
			}
		}
	}
}

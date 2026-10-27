<?php
/**
 * Newspack bridge tests.
 *
 * @package ContentGates
 */

declare( strict_types=1 );

namespace Automattic\VIP\ContentGates\Tests\Unit;

use Automattic\VIP\Cache\Vary_Cache;
use Automattic\VIP\ContentGates\Membership\Membership;
use Automattic\VIP\ContentGates\Providers\Newspack_Bridge;

/**
 * Newspack rule evaluations become segment assignments; missing rules assign nothing.
 */
final class Test_Newspack_Bridge extends Content_Gates_Test_Case {

	/**
	 * Load the fake Access_Rules once.
	 */
	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		require_once __DIR__ . '/fake-newspack-access-rules.php';
	}

	/**
	 * Reset the fake.
	 */
	public function set_up(): void {
		parent::set_up();
		\Newspack\Access_Rules::reset();
	}

	/**
	 * A registered rule that evaluates true assigns; false removes.
	 */
	public function test_rule_result_drives_membership(): void {
		$this->create_segment( 'Subscriber' );
		\Newspack\Access_Rules::register_rule(
			array(
				'id'       => 'subscription',
				'callback' => static fn( int $user_id, mixed $value ): bool => in_array( 42, (array) $value, true ),
			)
		);
		add_filter(
			'content_gates_newspack_rules',
			static fn(): array => array(
				array(
					'segment' => 'subscriber',
					'rule'    => 'subscription',
					'value'   => array( 42 ),
				),
			)
		);

		( new Newspack_Bridge( $this->plugin()->membership() ) )->sync();
		$this->assertSame( '1', Vary_Cache::get_groups()['subscriber'] );

		remove_all_filters( 'content_gates_newspack_rules' );
		add_filter(
			'content_gates_newspack_rules',
			static fn(): array => array(
				array(
					'segment' => 'subscriber',
					'rule'    => 'subscription',
					'value'   => array( 7 ),
				),
			)
		);
		( new Newspack_Bridge( $this->plugin()->membership() ) )->sync();
		$this->assertSame( '', Vary_Cache::get_groups()['subscriber'], 'A false evaluation removes membership.' );
	}

	/**
	 * On a narrowed request the bridge does not even evaluate rules.
	 */
	public function test_narrowed_request_is_left_alone(): void {
		$this->create_segment( 'Subscriber' );
		$evaluated = false;
		\Newspack\Access_Rules::register_rule(
			array(
				'id'       => 'subscription',
				'callback' => static function () use ( &$evaluated ): bool {
					$evaluated = true;
					return true;
				},
			)
		);
		add_filter(
			'content_gates_newspack_rules',
			static fn(): array => array(
				array(
					'segment' => 'subscriber',
					'rule'    => 'subscription',
				),
			) 
		);
		$_SERVER[ Membership::LOOKUP_HEADER ] = 'narrowed';

		( new Newspack_Bridge( $this->plugin()->membership() ) )->sync();

		unset( $_SERVER[ Membership::LOOKUP_HEADER ] );
		$this->assertFalse( $evaluated );
		$this->assertSame( '', Vary_Cache::get_groups()['subscriber'] ?? '' );
	}

	/**
	 * An unregistered rule assigns nothing, even though Newspack would evaluate it true.
	 */
	public function test_missing_rule_assigns_nothing(): void {
		$this->create_segment( 'Subscriber' );
		$this->assertTrue( \Newspack\Access_Rules::evaluate_rule( 'ghost', null, 0 ), 'Newspack opens the gate for unknown rules.' );
		add_filter(
			'content_gates_newspack_rules',
			static fn(): array => array(
				array(
					'segment' => 'subscriber',
					'rule'    => 'ghost',
				),
			)
		);
		$missing = array();
		add_action(
			'content_gates_newspack_rule_missing',
			static function ( string $slug ) use ( &$missing ): void {
				$missing[] = $slug;
			}
		);

		( new Newspack_Bridge( $this->plugin()->membership() ) )->sync();

		$this->assertSame( '', Vary_Cache::get_groups()['subscriber'] );
		$this->assertSame( array( 'ghost' ), $missing );
	}
}

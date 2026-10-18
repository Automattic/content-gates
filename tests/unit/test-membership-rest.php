<?php
/**
 * Membership REST endpoint tests.
 *
 * @package ContentGates
 */

declare( strict_types=1 );

namespace ContentGates\Tests\Unit;

use ContentGates\Membership\Consulted_Set;
use ContentGates\Membership\Local_Cookie_Adapter;
use ContentGates\Membership\Membership;
use ContentGates\Plugin;
use ContentGates\Rendering\Headers;
use ContentGates\Rendering\Response_Finalizer;
use ContentGates\REST\Membership_Controller;
use ContentGates\Segments\Segment_Registry;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_UnitTestCase;

/**
 * `content-gates/v1/membership` reads, writes, flushes and refuses.
 *
 * The controller under test gets a fresh `Membership` built on a fresh
 * `Local_Cookie_Adapter` and the booted plugin's real segment registry.
 * A successful POST flushes the adapter, and a flushed adapter reports
 * `did_send_headers()` for the rest of the PHP process; sharing the
 * booted adapter would make every later assignment `content_gates_too_late`.
 * `Test_Functions` covers the delegation from the public functions to
 * the booted singleton.
 */
final class Test_Membership_Rest extends WP_UnitTestCase {

	private const ROUTE    = '/content-gates/v1/membership';
	private const CODE_KEY = 'rest_newsletter';

	/**
	 * Adapter behind the controller under test.
	 *
	 * @var Local_Cookie_Adapter
	 */
	private Local_Cookie_Adapter $adapter;

	/**
	 * Membership behind the controller under test.
	 *
	 * @var Membership
	 */
	private Membership $membership;

	/**
	 * The booted plugin's registry.
	 *
	 * @var Segment_Registry
	 */
	private Segment_Registry $registry;

	/**
	 * Active CPT segment ("Subscribers", key `subscribers`).
	 *
	 * @var int
	 */
	private int $cpt_id;

	/**
	 * Inactive CPT segment ("Archived", key `archived`).
	 *
	 * @var int
	 */
	private int $inactive_id;

	/**
	 * Controller under test.
	 *
	 * @var Membership_Controller
	 */
	private Membership_Controller $controller;

	/**
	 * Saved segmentation request header.
	 *
	 * @var mixed
	 */
	private mixed $saved_header = null;

	/**
	 * Build a fresh REST server and controller around a fresh membership.
	 */
	public function set_up(): void {
		parent::set_up();

		Headers::reset();
		Plugin::instance()->membership()->consulted_set()->reset();

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Test setup saves raw request state to restore it later.
		$this->saved_header = $_SERVER[ Local_Cookie_Adapter::HEADER_NAME ] ?? null;
		unset( $_SERVER[ Local_Cookie_Adapter::HEADER_NAME ] );

		$this->registry = Plugin::instance()->segments();
		$this->registry->invalidate_cache();

		$cpt_id = $this->registry->create( 'Subscribers' );
		$this->assertIsInt( $cpt_id );
		$this->cpt_id = $cpt_id;

		$inactive_id = $this->registry->create( 'Archived' );
		$this->assertIsInt( $inactive_id );
		$this->inactive_id = $inactive_id;
		$this->assertTrue( $this->registry->set_active( $inactive_id, false ) );

		if ( null === $this->registry->find( self::CODE_KEY ) ) {
			$this->assertTrue( $this->registry->register_key( self::CODE_KEY ) );
		}

		$this->adapter    = new Local_Cookie_Adapter();
		$this->membership = new Membership( $this->adapter, $this->registry, new Consulted_Set() );
		$this->membership->register_groups();

		$GLOBALS['wp_rest_server'] = new WP_REST_Server(); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Resetting WordPress's own REST server global, as core's REST tests do.

		// Routes must be registered inside rest_api_init or core raises
		// _doing_it_wrong(). Priority 5 puts this controller's handlers
		// ahead of the booted controller's for the same route, and the
		// server dispatches to the first handler that matches.
		$this->controller = new Membership_Controller( $this->membership, $this->registry );
		add_action( 'rest_api_init', array( $this->controller, 'register_routes' ), 5 );
		add_filter( 'rest_request_after_callbacks', array( $this->controller, 'make_uncacheable' ), 10, 3 );
		do_action( 'rest_api_init', $GLOBALS['wp_rest_server'] ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals -- Firing WordPress's own action on its own global, as rest_get_server() does.
	}

	/**
	 * Restore request state and drop the registry cache for rolled-back posts.
	 */
	public function tear_down(): void {
		unset( $_SERVER[ Local_Cookie_Adapter::HEADER_NAME ] );
		if ( null !== $this->saved_header ) {
			$_SERVER[ Local_Cookie_Adapter::HEADER_NAME ] = $this->saved_header;
		}

		$GLOBALS['wp_rest_server'] = null; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Resetting WordPress's own REST server global, as core's REST tests do.
		$this->registry->invalidate_cache();
		Headers::reset();

		parent::tear_down();
	}

	/**
	 * Dispatch a request against the route.
	 *
	 * @param string               $method HTTP method.
	 * @param array<string, mixed> $body   JSON body for POST.
	 */
	private function dispatch( string $method, array $body = array() ): WP_REST_Response {
		$request = new WP_REST_Request( $method, self::ROUTE );
		if ( array() !== $body ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $body ) );
		}

		return rest_get_server()->dispatch( $request );
	}

	/**
	 * Simulate an incoming request that already carries membership.
	 *
	 * @param string $cookie Cookie value in the Vary_Cache format.
	 */
	private function arrive_with( string $cookie ): void {
		$_SERVER[ Local_Cookie_Adapter::HEADER_NAME ] = $cookie;
		$this->adapter->parse_request();
	}

	/**
	 * Grant every POST, as a provider would.
	 */
	private function allow_writes(): void {
		add_filter( 'content_gates_rest_membership_permission', '__return_true' );
	}

	/**
	 * Response body as an array.
	 *
	 * @param WP_REST_Response $response Response.
	 * @return array<string, mixed>
	 */
	private function data( WP_REST_Response $response ): array {
		$data = $response->get_data();
		$this->assertIsArray( $data );

		return $data;
	}

	/**
	 * Every response from the route is uncacheable and carries no variance
	 * signal.
	 *
	 * @param WP_REST_Response $response Response.
	 */
	private function assert_uncacheable( WP_REST_Response $response ): void {
		$headers = $response->get_headers();

		$this->assertSame( 'no-store, no-cache, must-revalidate, max-age=0', $headers['Cache-Control'] ?? null );
		$this->assertSame( 'Cookie', $headers['Vary'] ?? null );
		$this->assertArrayNotHasKey( Response_Finalizer::VARY_HEADER, $headers, 'The endpoint records no reads.' );
		$this->assertArrayNotHasKey( Response_Finalizer::KEY_HEADER, $headers, 'The endpoint records no reads.' );
	}

	/**
	 * The booted plugin registers the route on rest_api_init.
	 */
	public function test_route_is_registered_on_boot(): void {
		remove_action( 'rest_api_init', array( $this->controller, 'register_routes' ), 5 );
		$GLOBALS['wp_rest_server'] = null; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Resetting WordPress's own REST server global, as core's REST tests do.

		// rest_get_server() builds a new server and fires rest_api_init with
		// only the booted plugin's hooks left.
		$routes = rest_get_server()->get_routes();

		$this->assertArrayHasKey( self::ROUTE, $routes );
		$this->assertCount( 2, $routes[ self::ROUTE ], 'One GET handler and one POST handler from the booted controller.' );
	}

	/**
	 * GET reports contract, membership from the request, and every segment.
	 */
	public function test_get_returns_contract_membership_and_segments(): void {
		$this->arrive_with( 'vc-v1__' . self::CODE_KEY . '_--_1---__subscribers_--_gold' );

		$response = $this->dispatch( 'GET' );
		$data     = $this->data( $response );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( '1', $data['contract'] );
		// Key order follows adapter registration order (CPT keys first); only
		// the key => value pairs are contractual.
		$this->assertEquals(
			array(
				self::CODE_KEY => '1',
				'subscribers'  => 'gold',
			),
			$data['membership']
		);

		$by_key = array();
		foreach ( $data['segments'] as $segment ) {
			$by_key[ $segment['key'] ] = $segment;
		}

		$this->assertSame(
			array(
				'id'     => $this->cpt_id,
				'key'    => 'subscribers',
				'name'   => 'Subscribers',
				'active' => true,
			),
			$by_key['subscribers']
		);
		$this->assertSame(
			array(
				'id'     => $this->inactive_id,
				'key'    => 'archived',
				'name'   => 'Archived',
				'active' => false,
			),
			$by_key['archived']
		);
		$this->assertSame(
			array(
				'id'     => null,
				'key'    => self::CODE_KEY,
				'name'   => self::CODE_KEY,
				'active' => true,
			),
			$by_key[ self::CODE_KEY ]
		);
		$this->assertArrayHasKey( Segment_Registry::CRAWLER_KEY, $by_key );

		$this->assert_uncacheable( $response );
		$this->assertSame( array(), $this->membership->consulted(), 'GET records no reads.' );
		$this->assertFalse( $this->adapter->did_send_headers(), 'GET does not flush.' );
		$this->assertNull( $this->adapter->pending_cookie() );
	}

	/**
	 * GET without any membership reports an empty JSON object, not `[]`:
	 * the contract documents `membership: { key: value }` and clients must
	 * not see the JSON type change for an anonymous reader.
	 */
	public function test_get_without_membership_is_empty(): void {
		$data = $this->data( $this->dispatch( 'GET' ) );

		$this->assertInstanceOf( \stdClass::class, $data['membership'] );
		$this->assertSame( array(), (array) $data['membership'] );
		$this->assertStringContainsString( '"membership":{}', (string) wp_json_encode( $data ) );
	}

	/**
	 * A successful POST that ends with no membership (remove the last key)
	 * also reports an empty JSON object.
	 */
	public function test_post_remove_last_reports_empty_object(): void {
		$this->allow_writes();
		$this->arrive_with( 'vc-v1__' . self::CODE_KEY . '_--_1' );

		$response = $this->dispatch(
			'POST',
			array(
				'segment' => self::CODE_KEY,
				'action'  => 'remove',
			)
		);
		$this->assertSame( 200, $response->get_status() );
		$this->assertStringContainsString( '"membership":{}', (string) wp_json_encode( $response->get_data() ) );
	}

	/**
	 * Without a provider granting the request, POST is forbidden and
	 * nothing is written.
	 */
	public function test_post_without_provider_is_forbidden(): void {
		$this->assertFalse(
			has_filter( 'content_gates_rest_membership_permission' ),
			'No provider may be active under PHPUnit; the e2e test provider mu-plugin must stay out of the test library.'
		);

		$response = $this->dispatch( 'POST', array( 'segment' => self::CODE_KEY ) );
		$data     = $this->data( $response );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'content_gates_forbidden', $data['code'] );
		$this->assert_uncacheable( $response );

		$this->assertSame( array(), $this->membership->membership() );
		$this->assertFalse( $this->adapter->did_send_headers() );
		$this->assertNull( $this->adapter->pending_cookie() );
	}

	/**
	 * A provider's WP_Error is returned as-is, status included.
	 */
	public function test_post_returns_provider_error_as_is(): void {
		add_filter(
			'content_gates_rest_membership_permission',
			static fn (): WP_Error => new WP_Error( 'provider_says_no', 'Log in first.', array( 'status' => 401 ) )
		);

		$response = $this->dispatch( 'POST', array( 'segment' => self::CODE_KEY ) );
		$data     = $this->data( $response );

		$this->assertSame( 401, $response->get_status() );
		$this->assertSame( 'provider_says_no', $data['code'] );
		$this->assert_uncacheable( $response );
	}

	/**
	 * A provider returning something other than true or WP_Error is a refusal.
	 */
	public function test_post_with_non_boolean_grant_is_forbidden(): void {
		add_filter( 'content_gates_rest_membership_permission', static fn (): string => 'yes' );

		$response = $this->dispatch( 'POST', array( 'segment' => self::CODE_KEY ) );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'content_gates_forbidden', $this->data( $response )['code'] );
	}

	/**
	 * The filter receives the request so a provider can inspect the params.
	 */
	public function test_permission_filter_receives_request(): void {
		$seen = null;
		add_filter(
			'content_gates_rest_membership_permission',
			static function ( mixed $allowed, WP_REST_Request $request ) use ( &$seen ): bool {
				$seen = $request->get_param( 'segment' );
				return true;
			},
			10,
			2
		);

		$this->dispatch( 'POST', array( 'segment' => (string) $this->cpt_id ) );

		$this->assertSame( $this->cpt_id, $seen, 'Numeric strings are sanitized to IDs before the permission check.' );
	}

	/**
	 * A granted assignment writes the cookie onto this response.
	 */
	public function test_post_assign_flushes_cookie_onto_response(): void {
		$this->allow_writes();

		$response = $this->dispatch(
			'POST',
			array(
				'segment' => self::CODE_KEY,
				'action'  => 'assign',
			)
		);
		$data     = $this->data( $response );

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $data['changed'] );
		$this->assertSame( '1', $data['contract'] );
		$this->assertSame( array( self::CODE_KEY => '1' ), $data['membership'] );
		$this->assertSame(
			array(
				'name'  => 'vip-go-seg',
				'value' => 'vc-v1__' . self::CODE_KEY . '_--_1',
			),
			$data['cookie']
		);
		$this->assert_uncacheable( $response );

		$cookie = $this->adapter->pending_cookie();
		$this->assertNotNull( $cookie, 'The adapter was flushed.' );
		$this->assertSame( 'vc-v1__' . self::CODE_KEY . '_--_1', $cookie['value'] );
		$this->assertTrue( $this->adapter->did_send_headers() );
		$this->assertSame( 'local', Headers::sent()['X-Content-Gates-Simulation'] ?? null );
		$this->assertSame( array(), $this->membership->consulted(), 'Writes record no reads.' );
	}

	/**
	 * Action defaults to assign; segment accepts a post ID; value is stored.
	 */
	public function test_post_assign_by_id_with_value(): void {
		$this->allow_writes();

		$response = $this->dispatch(
			'POST',
			array(
				'segment' => $this->cpt_id,
				'value'   => 'gold',
			)
		);
		$data     = $this->data( $response );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'subscribers' => 'gold' ), $data['membership'] );
		$this->assertSame( 'vc-v1__subscribers_--_gold', $data['cookie']['value'] );
	}

	/**
	 * Removal drops the key and the cookie reflects what remains.
	 */
	public function test_post_remove_drops_key(): void {
		$this->allow_writes();
		$this->arrive_with( 'vc-v1__' . self::CODE_KEY . '_--_1---__subscribers_--_1' );

		$response = $this->dispatch(
			'POST',
			array(
				'segment' => 'subscribers',
				'action'  => 'remove',
			)
		);
		$data     = $this->data( $response );

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $data['changed'] );
		$this->assertSame( array( self::CODE_KEY => '1' ), $data['membership'] );
		$this->assertSame( 'vc-v1__' . self::CODE_KEY . '_--_1', $data['cookie']['value'] );
	}

	/**
	 * Removing the last membership clears the cookie.
	 */
	public function test_post_remove_last_clears_cookie(): void {
		$this->allow_writes();
		$this->arrive_with( 'vc-v1__subscribers_--_1' );

		$data = $this->data(
			$this->dispatch(
				'POST',
				array(
					'segment' => 'subscribers',
					'action'  => 'remove',
				)
			)
		);

		$this->assertInstanceOf( \stdClass::class, $data['membership'], 'Empty membership is a JSON object.' );
		$this->assertSame( array(), (array) $data['membership'] );
		$this->assertSame( '', $data['cookie']['value'] );

		$cookie = $this->adapter->pending_cookie();
		$this->assertNotNull( $cookie );
		$this->assertLessThan( time(), $cookie['expires'] );
	}

	/**
	 * Unknown segments are a 400 with the membership layer's code; nothing
	 * is flushed.
	 */
	public function test_post_unknown_segment_is_bad_request(): void {
		$this->allow_writes();

		$response = $this->dispatch( 'POST', array( 'segment' => 'nope' ) );
		$data     = $this->data( $response );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'content_gates_invalid_segment', $data['code'] );
		$this->assert_uncacheable( $response );
		$this->assertFalse( $this->adapter->did_send_headers(), 'A failed write does not flush.' );
		$this->assertNull( $this->adapter->pending_cookie() );

		$response = $this->dispatch( 'POST', array( 'segment' => 999999 ) );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'content_gates_invalid_segment', $this->data( $response )['code'] );
	}

	/**
	 * Numeric-looking strings that are not all digits are keys, and
	 * unknown keys are refused. `is_numeric` would have coerced them to
	 * an ID the caller never named.
	 *
	 * @dataProvider numeric_looking_keys
	 *
	 * @param string $segment Segment argument.
	 */
	public function test_post_numeric_looking_key_is_not_an_id( string $segment ): void {
		$this->allow_writes();

		$response = $this->dispatch( 'POST', array( 'segment' => $segment ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'content_gates_invalid_segment', $this->data( $response )['code'] );
		$this->assertNull( $this->adapter->pending_cookie() );
	}

	/**
	 * Strings `is_numeric()` accepts that must not become IDs.
	 *
	 * @return array<string, array{string}>
	 */
	public function numeric_looking_keys(): array {
		return array(
			'exponent'           => array( '1e3' ),
			'float'              => array( '12.5' ),
			'leading whitespace' => array( ' 12' ),
		);
	}

	/**
	 * Inactive segments fail closed on write.
	 */
	public function test_post_inactive_segment_is_bad_request(): void {
		$this->allow_writes();

		$response = $this->dispatch( 'POST', array( 'segment' => $this->inactive_id ) );
		$data     = $this->data( $response );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'content_gates_inactive_segment', $data['code'] );
		$this->assertFalse( $this->adapter->did_send_headers() );

		$response = $this->dispatch(
			'POST',
			array(
				'segment' => 'archived',
				'action'  => 'remove',
			)
		);
		$this->assertSame( 'content_gates_inactive_segment', $this->data( $response )['code'] );
	}

	/**
	 * Values outside the cookie alphabet are rejected.
	 */
	public function test_post_invalid_value_is_bad_request(): void {
		$this->allow_writes();

		$response = $this->dispatch(
			'POST',
			array(
				'segment' => self::CODE_KEY,
				'value'   => 'bad value',
			)
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'content_gates_invalid_value', $this->data( $response )['code'] );
	}

	/**
	 * Schema validation rejects an unknown action before the permission check.
	 */
	public function test_post_invalid_action_is_rejected_by_schema(): void {
		$response = $this->dispatch(
			'POST',
			array(
				'segment' => self::CODE_KEY,
				'action'  => 'poke',
			)
		);
		$data     = $this->data( $response );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'rest_invalid_param', $data['code'] );
		$this->assert_uncacheable( $response );
	}

	/**
	 * The segment argument is required.
	 */
	public function test_post_without_segment_is_rejected(): void {
		$this->allow_writes();

		$response = $this->dispatch( 'POST', array( 'action' => 'assign' ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'rest_missing_callback_param', $this->data( $response )['code'] );
	}

	/**
	 * A second write on the same request is too late: one response, one
	 * Set-Cookie.
	 */
	public function test_second_post_on_same_request_is_too_late(): void {
		$this->allow_writes();

		$this->assertSame( 200, $this->dispatch( 'POST', array( 'segment' => self::CODE_KEY ) )->get_status() );

		$response = $this->dispatch( 'POST', array( 'segment' => 'subscribers' ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'content_gates_too_late', $this->data( $response )['code'] );
	}

	/**
	 * The no-cache filter leaves other routes alone.
	 */
	public function test_other_routes_are_untouched(): void {
		$controller = new Membership_Controller( $this->membership, $this->registry );
		$request    = new WP_REST_Request( 'GET', '/wp/v2/posts' );
		$request->set_route( '/wp/v2/posts' );

		$response = new WP_REST_Response( array() );
		$this->assertSame( $response, $controller->make_uncacheable( $response, array(), $request ) );
		$this->assertSame( array(), $response->get_headers() );

		$error = new WP_Error( 'x', 'y' );
		$this->assertSame( $error, $controller->make_uncacheable( $error, array(), $request ) );
	}

	/**
	 * Segment reference sanitization: only a string of digits is an ID.
	 */
	public function test_sanitize_segment_ref(): void {
		$this->assertSame( 12, Membership_Controller::sanitize_segment_ref( 12 ) );
		$this->assertSame( 12, Membership_Controller::sanitize_segment_ref( '12' ) );
		$this->assertSame( '1e3', Membership_Controller::sanitize_segment_ref( '1e3' ), 'Exponent notation is numeric but not an ID.' );
		$this->assertSame( '12.5', Membership_Controller::sanitize_segment_ref( '12.5' ), 'A float string is not an ID.' );
		$this->assertSame( '12', Membership_Controller::sanitize_segment_ref( ' 12' ), 'Leading whitespace means key, which sanitize_text_field then trims; it is never coerced to an ID.' );
		$this->assertSame( 'newsletter', Membership_Controller::sanitize_segment_ref( 'newsletter' ) );
		$this->assertSame( 'news', Membership_Controller::sanitize_segment_ref( "<b>news</b>\n" ) );
		$this->assertSame( '', Membership_Controller::sanitize_segment_ref( '<script>alert(1)</script>' ), 'Script elements are stripped whole.' );
		$this->assertSame( '', Membership_Controller::sanitize_segment_ref( array( 'x' ) ) );
	}
}

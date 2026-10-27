<?php
/**
 * Origin's side of the edge contract.
 *
 * @package ContentGates
 */

declare( strict_types=1 );

namespace Automattic\VIP\ContentGates\Tests\Unit;

use Automattic\VIP\Cache\Vary_Cache;
use Automattic\VIP\ContentGates\Headers;
use Automattic\VIP\ContentGates\Membership\Declared_Set;
use Automattic\VIP\ContentGates\Segments\Crawler_Config;
use ReflectionMethod;

/**
 * The formats in tests/fixtures/edge-contract.json, as origin emits and parses them.
 *
 * The Edge Worker's side is edge-workers/tests/contract.test.ts. A change
 * to a format fails one of the two until both sides move.
 */
final class Test_Edge_Contract extends Content_Gates_Test_Case {

	/**
	 * The shared fixture.
	 *
	 * @return array<string, mixed>
	 */
	private function contract(): array {
		$json = file_get_contents( dirname( __DIR__ ) . '/fixtures/edge-contract.json' ); // phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown -- Local fixture.
		$this->assertIsString( $json );
		$data = json_decode( $json, true );
		$this->assertIsArray( $data );

		return $data;
	}

	/**
	 * The header names are the fixture's.
	 */
	public function test_header_names(): void {
		$headers = $this->contract()['headers'];

		$this->assertSame( $headers['declared'], Declared_Set::HEADER_DECLARED );
		$this->assertSame( $headers['meter'], Declared_Set::HEADER_METER );
		$this->assertSame( 'HTTP_' . strtoupper( str_replace( '-', '_', $headers['edge_version'] ) ), Crawler_Config::EDGE_HEADER );
	}

	/**
	 * The declared header lists the declared groups sorted, comma and space, and is empty for none.
	 */
	public function test_declared_header_format(): void {
		foreach ( $this->contract()['declared'] as $case ) {
			foreach ( $case['groups'] as $key ) {
				$this->create_segment( ucfirst( $key ) );
			}
			$this->new_request();
			$post_id = array() === $case['groups']
				? $this->create_post( array( 'post_content' => $this->paragraph( 'free' ) ) )
				: $this->create_gated_post( $case['groups'] );

			$this->request_post( $post_id );

			$this->assertSame( $case['header'], Headers::last( Declared_Set::HEADER_DECLARED ) );
			$this->new_request();
		}
	}

	/**
	 * The meter header names the group, the post, and the settings.
	 */
	public function test_meter_header_format(): void {
		$meter = $this->contract()['meter'];
		$this->create_meter( 'Metered articles', (int) $meter['limit'], (int) $meter['period'] );
		$post_id = $this->create_gated_post( array( $meter['group'] ) );

		$this->request_post( $post_id );

		$expected = str_replace( 'id=' . $meter['post_id'], 'id=' . $post_id, $meter['header'] );
		$this->assertSame( array( $expected ), Headers::values( Declared_Set::HEADER_METER ) );
	}

	/**
	 * `Vary_Cache` parses the fixture's cookie value into those groups, and serializes them back to the same bytes.
	 */
	public function test_cookie_format_round_trips_through_vary_cache(): void {
		$cookie = $this->contract()['cookie'];
		$this->create_segment( 'Subscriber' );
		$this->create_segment( 'Newsletter' );
		$this->create_meter( 'Metered articles', 5, 2592000 );
		$this->assertSame( 'vip-go-seg', Vary_Cache::COOKIE_SEGMENT );
		$this->assertSame( $cookie['name'], Vary_Cache::COOKIE_SEGMENT );

		$_COOKIE[ $cookie['name'] ] = $cookie['value'];
		Vary_Cache::parse_cookies();

		$groups = Vary_Cache::get_groups();
		foreach ( $cookie['groups'] as $name => $value ) {
			$this->assertSame( $value, $groups[ $name ] ?? null, "Group {$name} parsed." );
		}

		// The upstream serializer is private; the vendor copy is verbatim, so
		// reflection is the only way to pin its output without editing it.
		$stringify = new ReflectionMethod( Vary_Cache::class, 'stringify_groups' );
		$this->assertSame( $cookie['value'], $stringify->invoke( null ) );
	}
}

<?php
/**
 * Reader for config/crawlers.json.
 *
 * @package ContentGates
 */

declare( strict_types=1 );

namespace Automattic\VIP\ContentGates\Segments;

/**
 * The VIP-maintained crawler configuration shared with the Edge Worker.
 *
 * `config/crawlers.json` lists every crawler segment with its key, label,
 * and user-agent substrings. The Edge Worker build compiles the same file in,
 * so the two sides agree by construction. `version` increments on every
 * change and is compared with the value the worker sends in
 * `X-WPVIP-Content-Gates-Edge`.
 *
 * @since 0.1.0
 */
final class Crawler_Config {

	/**
	 * Request header the Edge Worker sends with its configuration version.
	 */
	public const EDGE_HEADER = 'HTTP_X_WPVIP_CONTENT_GATES_EDGE';

	/**
	 * Parsed file, or null when unreadable.
	 *
	 * @var array{version: int, segments: array<string, array{label: string, user_agents: string[]}>}|null
	 */
	private ?array $data = null;

	/**
	 * Constructor.
	 *
	 * @param string $path Path to the JSON file.
	 */
	public function __construct( private string $path = CONTENT_GATES_PATH . 'config/crawlers.json' ) {}

	/**
	 * Configuration version, 0 when the file is unreadable.
	 *
	 * @since 0.1.0
	 */
	public function version(): int {
		return $this->data()['version'];
	}

	/**
	 * Crawler segments keyed by segment key.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{label: string, user_agents: string[]}>
	 */
	public function segments(): array {
		return $this->data()['segments'];
	}

	/**
	 * The version the Edge Worker reported on this request, or null when absent.
	 *
	 * @since 0.1.0
	 */
	public function edge_version(): ?int {
		if ( ! isset( $_SERVER[ self::EDGE_HEADER ] ) ) {
			return null;
		}

		$raw = sanitize_text_field( wp_unslash( $_SERVER[ self::EDGE_HEADER ] ) );

		return is_numeric( $raw ) ? (int) $raw : null;
	}

	/**
	 * Load and validate the file once.
	 *
	 * @return array{version: int, segments: array<string, array{label: string, user_agents: string[]}>}
	 */
	private function data(): array {
		if ( null !== $this->data ) {
			return $this->data;
		}

		$this->data = array(
			'version'  => 0,
			'segments' => array(),
		);

		if ( ! is_readable( $this->path ) ) {
			return $this->data;
		}

		// phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown -- Plugin-bundled file, not remote.
		$raw     = (string) file_get_contents( $this->path );
		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) || ! isset( $decoded['segments'] ) || ! is_array( $decoded['segments'] ) ) {
			return $this->data;
		}

		$this->data['version'] = isset( $decoded['version'] ) && is_numeric( $decoded['version'] ) ? (int) $decoded['version'] : 0;

		foreach ( $decoded['segments'] as $key => $segment ) {
			if ( ! is_string( $key ) || 1 !== preg_match( Segment::TOKEN_PATTERN, $key ) || strlen( $key ) > Segment::KEY_MAX_LENGTH || ! is_array( $segment ) ) {
				continue;
			}

			$agents = array();
			foreach ( (array) ( $segment['user_agents'] ?? array() ) as $agent ) {
				if ( is_string( $agent ) && '' !== $agent ) {
					$agents[] = $agent;
				}
			}

			$this->data['segments'][ $key ] = array(
				'label'       => is_string( $segment['label'] ?? null ) ? $segment['label'] : $key,
				'user_agents' => $agents,
			);
		}

		return $this->data;
	}
}

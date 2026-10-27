/**
 * Crypto injection point.
 *
 * The pure modules never import a hash implementation. The worker entry
 * passes the SDK's host-executed `sha256` / `hmacSha256Str`, and the tests
 * pass `node:crypto`. Both produce lowercase hex.
 *
 * Portability rules: see segmentation.ts. Function-typed fields are the one
 * form of "callback" both runtimes accept without closures.
 */

export class Crypto {
	sha256Hex: ( input: string ) => string;
	hmacSha256Hex: ( secret: string, message: string ) => string;

	constructor(
		sha256Hex: ( input: string ) => string,
		hmacSha256Hex: ( secret: string, message: string ) => string
	) {
		this.sha256Hex = sha256Hex;
		this.hmacSha256Hex = hmacSha256Hex;
	}
}

/**
 * Compare two strings in time that depends only on their lengths.
 *
 * Used for cookie signatures. A plain `==` returns at the first differing
 * character, which can leak how many leading signature bytes an attacker
 * guessed right.
 *
 * @param a First string.
 * @param b Second string.
 * @return True when equal.
 */
export function constantTimeEquals( a: string, b: string ): bool {
	if ( a.length != b.length ) {
		return false;
	}
	let diff: i32 = 0;
	for ( let i = 0; i < a.length; i++ ) {
		// eslint-disable-next-line no-bitwise -- accumulate without branching.
		diff = diff | ( a.charCodeAt( i ) ^ b.charCodeAt( i ) );
	}
	return diff == 0;
}

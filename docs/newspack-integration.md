# Newspack bridge: inspected contract, not a deployed adapter

This is a worked integration example, not an enabled production adapter. It
connects an externally decided eligibility result to this plugin's membership
assignment, then lets a Newspack access rule consult that membership. It does not
implement billing, identity, eligibility, Newspack metering, or edge caching.

## Source inspected

On 2026-10-17, only these files in the existing local Newspack checkout were read:

- `/Users/max/Projects/newspack-gating-test/plugins/newspack-plugin/includes/content-gate/class-access-rules.php`
- `/Users/max/Projects/newspack-gating-test/plugins/newspack-plugin/includes/content-gate/class-metering.php`

The access-rule file declares **`Newspack\Access_Rules`**, despite living in a
`content-gate` directory. It does not declare `Newspack\Content_Gate\Access_Rules`.
The inspected method is `public static function register_rule( $config )`, with
one configuration array and a `void|WP_Error` result. Required entries are `id`
and a callable `callback`; duplicate IDs return `rule_already_registered`.
Callbacks receive `($user_id, $args)` and return whether the rule allows access.
This checkout's `evaluate_rule()` denies anonymous evaluation unless the rule
sets `supports_anonymous` true. Missing rules currently return true, so a failed
registration must be treated as an integration failure, not silently ignored.

This is evidence about the **local checkout**, not proof of an upstream release,
merged patch, or compatibility across Newspack versions. Reverify these signatures
and anonymous support against the exact Newspack version being deployed.

## Worked bridge

The following site-integration function takes an existing active segment ID, an
external eligibility resolver, and an error reporter. The resolver must return a
boolean for the **current reader**, using the provider's verified identity and
entitlement state. Neither the resolver nor the reporter is a platform API. The
site supplies them. Register this function during plugin loading, before `init`.
Do not implement the resolver by evaluating this same Newspack rule: that would
create a circular dependency.

```php
function content_gates_example_install_newspack_bridge(
    int $segment_id,
    callable $resolve_external_eligibility,
    callable $report_failure
): void {
    add_action(
        'content_gates_provider_ready',
        static function () use ( $segment_id, $resolve_external_eligibility, $report_failure ): void {
            // Provider owns eligibility. Only an explicit boolean true grants.
            $member = true === $resolve_external_eligibility();
            $result = content_gates_assign_reader( $segment_id, $member, 1 );
            if ( is_wp_error( $result ) ) {
                $report_failure( $result );
            }
        }
    );

    add_action(
        'init',
        static function () use ( $segment_id, $report_failure ): void {
            if ( ! class_exists( '\\Newspack\\Access_Rules' ) ) {
                $report_failure( new WP_Error( 'newspack_missing', 'Newspack Access_Rules is unavailable.' ) );
                return;
            }
            $result = \Newspack\Access_Rules::register_rule(
                array(
                    'id'                 => 'content_gates_example_segment',
                    'name'               => 'Content Gates segment',
                    'description'        => 'Membership assigned by the external entitlement provider.',
                    'default'            => true,
                    'is_boolean'         => true,
                    'supports_anonymous' => true,
                    'callback'           => static function ( $user_id, $args ) use ( $segment_id ): bool {
                        // The membership API checks this request's reader, never an arbitrary user.
                        if ( (int) $user_id !== get_current_user_id() ) {
                            return false;
                        }
                        return content_gates_is_reader_in_segment( $segment_id );
                    },
                )
            );
            if ( is_wp_error( $result ) ) {
                $report_failure( $result );
            }
        },
        30
    );
}
```

This example maps one rule to one fixed site-local segment. Configure that rule
in the intended Newspack gate; registration alone does not attach a rule to a
gate. The stored rule argument is not used to select arbitrary segment IDs.
Use the plugin's Group assignment UI for Group-based withholding; the example
does not replace it with Newspack's gate builder.

`content_gates_provider_ready` is this plugin's early provider boundary, after
registry initialization and VIP cookie parsing, normally at `init` priority 20.
Assignment is contract version 1, returns `true|WP_Error`, and must occur before
headers. `false` revokes rather than writing a negative membership token.
Report errors and keep production enablement blocked until registration and
assignment work; never translate a failed assignment into success. No cookie is
hand-written by this example. The real transport owns cookie serialization.

A production resolver must account for consent, authentication readiness,
revocation and expiry. It must not call an external entitlement API for every
cached page view; the early bridge runs only when origin PHP runs. Warm edge hits
do not execute it. Prove the provider's refresh lifecycle and actual cookie round
trip separately. Login alone never grants membership.

## Metering is not integrated or suppressed

The inspected `Metering` class registers `enqueue_scripts()` and
`render_frontend_metering_gate()` on `wp_footer`. Its frontend-metering predicate
uses the actual filters `newspack_content_gate_metering_short_circuit` and
`newspack_content_gate_is_frontend_metering`. These are source-observed candidate
extension points, **not a verified suppression recipe**. Their interactions with
restriction checks, excerpts, reader activation and gate layouts still need
integration testing. This prototype does not attach either filter or disable the
client-side metering reveal. Do not enable this example and assume Newspack can
no longer deliver gated bytes through its own paths.

A complete integration needs supported upstream extension points, raw-response
leak checks, and a concrete upstream patch where those points are insufficient.
No patch was opened by this documentation task. No Newspack/WooCommerce/Reader
Activation dependency-isolation run, live Newspack entitlement round trip, edge
meter advancement, or live VIP cache/purge behavior is claimed. The Group UI's
local pass is independent evidence, not a Newspack integration test.

## Pre-release hook names

Use the current plural-prefix names, deliberately renamed from the draft to
follow the project's coding standards:

- `content_gates_consulted_segments`: filter receiving the list of stable group
  identities actually consulted by this response. It may add registered
  dependencies conservatively; it must not remove observed reads. Invalid values
  mark the request unsafe. It does not assign membership.
- `content_gates_finalize_variance`: action receiving the final stable identity
  list and the corresponding `group => '1'|'none'` projection. Both positive and
  negative reads are represented. It observes finalization; it is not a purge or
  an edge-cache integration by itself.

The older draft spellings are not compatibility aliases. These are pre-release
names; providers should use the implementation contract rather than assume the
draft froze a public API. A restriction callback must call
`content_gates_is_reader_in_segment()` so its reads enter this response's
consulted set; reading the VIP cookie directly would bypass that accounting.

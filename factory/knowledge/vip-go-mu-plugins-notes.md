# vip-go-mu-plugins notes

## From run 20261012-cmp-integrations-research-2fb5 (codebase-researcher)

PHP mu-plugins; entry `000-vip-init.php`/`001-core.php`; integrations framework in `vip-integrations.php` + `integrations/` (`Integration` base, `Integrations` registry, `IntegrationVipConfig` reads `config/integrations-config/<slug>-config.php`). Vendor plugins live outside this repo under `vip-integrations/` (mu-plugins-ext) and are version-loaded via `get_available_versions`. Tests PHPUnit in `tests/` (`bin/test.sh`), e2e in `__tests__/e2e/`; lint `npm run phplint`, `npm run phpcs`. Guide: `AGENTS.md`, `docs/architecture.md`, `docs/testing.md`. Default branch: not confirmed.

## From run 20261012-sourcepoint-cmp-45cd (codebase-researcher)

Loader-only integrations are supported: `load()` may be empty (connector-controls); loaders self-deactivate when the vendor plugin dir is absent. PHPUnit runs in Docker via `CI=1 ./bin/test.sh --filter <Test>` and needs `composer install` + `git submodule update --init --recursive` first; `Constant_Mocker` is cleared per test.

# Edge workers

AssemblyScript edge workers for your VIP environment. Each worker lives in its
own folder under `workers/` and is compiled to a `.wasm` binary that
runs at the edge.

## Getting started

```sh
npm install                       # install the SDK + compiler
vip edge-workers new my-worker    # scaffold a new worker
# edit workers/my-worker/assembly/index.ts
vip @my-site.develop edge-workers deploy my-worker
```

Commit the generated `package-lock.json` after `npm install` so installs use the
reviewed dependency tree in local development and automation.

Shared AssemblyScript modules go in `lib/` and can be imported from any worker.

## This project

| Path | What it is |
| --- | --- |
| `workers/content-gates/` | The Content Gates worker: `worker.json` and the SDK entry `assembly/index.ts`. The only code that talks to the SDK. |
| `lib/` | The worker's logic as pure modules that compile under AssemblyScript **and** run under Node. `crawlers-config.ts` is generated from `../config/crawlers.json`. |
| `sim/` | A local page-cache emulator around the same modules (`npm run edge:sim` from the plugin root). |
| `tests/` | `node:test` suites for the modules and the simulator (`npm run test:edge`). |
| `scripts/crawlers-config.mjs` | Regenerates `lib/crawlers-config.ts`; `npm run edge:build` runs it before `vip edge-workers build`. |

`worker.json` has no `location`, so the worker runs on every request: phase 1
must see every request to narrow the cache key. `on_failure` is `error`
because a worker that fails after phase 1 narrowed the key could otherwise
let a response be stored unchecked. See `../docs/edge-worker.md`.

## Parsing JSON

To work with JSON in a worker, install [json-as](https://www.npmjs.com/package/json-as)
(`npm install --save-dev json-as@^1.3.4`); the build enables its compiler
transform automatically when the package is present.

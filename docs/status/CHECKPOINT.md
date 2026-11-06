# OptiStash development checkpoints

Rule: develop locally, commit a checkpoint after every verified step, and start the next step from the latest checkpoint
(`git log --oneline`). Nothing is pushed until the owner asks.

Order (follows the product document): foundation -> 1 Governance & Master Data -> 2 Inbound & QC -> 3 Inventory ->
4 Outbound -> 5 QA/CAPA/ISO audit trail -> 6 IoT gateway & integration orchestrator.

| Checkpoint | Content | State |
|---|---|---|
| CP0 | Foundation cloned from OptiDeli layers: tenancy, session auth, OptiNexus OIDC + permission pull, platform admin, generic master engine, access control with WMS permission catalog and role matrix. 26 backend tests. | done (backend); frontend adaptation next |
| CP0b | Frontend shell (login, navigation, access, master screens, supplier home) with OptiStash branding; Deli screens removed; build + vitest green | done |
| CP1 | Module 1: warehouses with granularity limit, storage hierarchy (create, bulk generate, block/unblock with reason, deactivate), UoM, categories, SKU with FEFO/FIFO/LIFO and shelf-life rules, packaging, partners, supplier portal accounts (never reach internal screens), transport integration mode (OptiDeli / third-party API / manual). 33 backend tests, frontend build green. Known gap: supplier account picker lists the first 50 suppliers. | done |
| CP2 | Module 2: ASN (internal and supplier portal), dock booking with overlap check, gate-in/out, receiving by pallet/carton with LPN and label data, lot/expiry/shelf-life enforcement, ISO 8.7 quarantine with non-conformance register and QC decisions, system-directed putaway with capacity reservation and reasoned override, append-only stock movement ledger. 39 backend tests, frontend build green. | done |
| CP3 | Module 3: stock map with roll-up and drill-down, stock summary, lock/unlock (unit or whole location) with reasons, cycle count with frozen expected quantities, variance reasons, recount and four-eyes approval (adjustments in the movement ledger), analysis ABC/FSN/VED/HML/SDE/XYZ/EOQ as stored snapshots with configurable thresholds and combined matrices. 45 backend tests, frontend build green. IoT tag binding and telemetry alerts move to CP6 with the IoT gateway. | done |
| CP4 | Module 4: outbound orders, allocation by FEFO/FIFO/LIFO with reservations (locked, quarantined and blocked stock excluded), WAVE/BATCH/CLUSTER picking with LPN scan and reasoned short picks, pack verification by scan, partial shipments only with wave.approve, shipments with transport mode from settings, dispatch writing the manifest to the outbox, printable manifest. 49 backend tests, frontend build green. Delivery adapters for OptiDeli / third-party API are CP6 (contract needs owner decision). | done |
| CP5 | Module 5: manual non-conformances, CAPA (corrective/preventive) with 5-Why or Fishbone root cause, actions, effectiveness verification by someone other than the owner, calibration registry that blocks unusable instruments from receiving chilled/frozen goods, controlled documents with checksum and independent approval, audit trail append-only by database trigger (also the stock ledger) with search and CSV export, live ISO 9001:2015 clause map. 56 backend tests, frontend build green. | done |
| CP6 | Module 6: IoT gateways with hashed bearer tokens (REST and MQTT bridge), devices bound to locations or stock units, telemetry with limits, one alert per breach series, auto-lock of bound stock with a non-conformance, stale-device alerts, orchestrator with standalone toggle, force sync, signed delivery of shipment/outbound events to a third-party API with retry/back-off (docs/architecture/INTEGRATION_ORCHESTRATOR.md). 62 backend tests. OptiDeli delivery waits for the owner-approved contract. | done |

## Decisions (owner)
- Stack: Laravel + PostgreSQL + React/TS. Order: as the document.

## Test runner
`php vendor/bin/phpunit` in `backend/` against a scratch PostgreSQL database (`optistash_test`).

## Open items and owner decisions needed
Decided by the owner on 2026-11-06 and done in CP7: the OptiDeli pickup contract (warehouse place + goods, sent through the OptiNexus broker as `optistash.pickup.requested`); supplier accounts by one-time email invitation (72 h) or created directly by the tenant administrator (mail through SMTP, Postmark like the other products); the supplier portal keeps showing the whole active product catalog; manifest, event and module catalog registered in OptiNexus (`optistash.manifest.json`, `OptiStashEventCatalogSeeder`), `stash` product in `deploy/optiecosystem`.

1. OptiDeli does not consume `optistash.pickup.requested` yet (it ignores unknown events), so no subscription is registered. Needs a decision on what a pickup becomes in OptiDeli.
2. A real SMTP provider has to be set in `.state/stash.env` (`MAIL_*`) before invitations work outside `APP_ENV=local`.
3. SaaS use: create the OIDC client and service account for `optistash` in OptiNexus (scopes `authorization.check`, `commercial.read`, `event.write`) and put the secrets in `.state/stash.env`.
5. Verified 2026-11-06: both images build (`optistash-backend:local`, `optistash-ui:local`); bootstrap (migrations, roles, platform admin, demo data), API, scheduler, web and console run in Podman on the ecosystem network (API 8400, console 5210); login and a write through the console proxy work. `podman compose` could not reach the daemon pipe in that session, so the stack was started with `podman run` from the rendered compose config; run `optiecosystem.ps1 up -Products stash` once the pipe is back. Not verified: browser QA (postponed by the owner), load behaviour of telemetry ingestion.
6. Known limits: supplier-account picker and partner pickers read the first 50 partners; location picker for IoT binding lists a warehouse tree; no barcode label printer integration (label data and a browser print view only); stock analysis uses `ISSUE` movements written by picking.

## Test status
Backend: 68 tests, 839 assertions (PostgreSQL scratch database `optistash_test`). Frontend: type-check, oxlint, 2 unit tests, production build. OptiNexus: 653 tests OK.

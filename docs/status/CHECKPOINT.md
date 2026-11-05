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
| CP5 | Module 5: non-conformance, CAPA, calibration, immutable audit trail, SOP registry | planned |
| CP6 | Module 6: IoT gateway (MQTT/REST), orchestrator, standalone toggle, force sync | planned |

## Decisions (owner)
- Stack: Laravel + PostgreSQL + React/TS. Order: as the document.

## Test runner
`php vendor/bin/phpunit` in `backend/` against a scratch PostgreSQL database (`optistash_test`).

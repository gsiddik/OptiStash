# OptiStash development checkpoints

Rule: develop locally, commit a checkpoint after every verified step, and start the next step from the latest checkpoint
(`git log --oneline`). Nothing is pushed until the owner asks.

Order (follows the product document): foundation -> 1 Governance & Master Data -> 2 Inbound & QC -> 3 Inventory ->
4 Outbound -> 5 QA/CAPA/ISO audit trail -> 6 IoT gateway & integration orchestrator.

| Checkpoint | Content | State |
|---|---|---|
| CP0 | Foundation cloned from OptiDeli layers: tenancy, session auth, OptiNexus OIDC + permission pull, platform admin, generic master engine, access control with WMS permission catalog and role matrix. 26 backend tests. | done (backend); frontend adaptation next |
| CP0b | Frontend shell (login, navigation, access, platform) with Stash branding, TMS screens removed | next |
| CP1 | Module 1: warehouses, storage hierarchy (Zone/Aisle/Rack/Shelf/Bin, per-warehouse granularity), categories, UoM, SKU + packaging, partners, supplier provisioning, transport integration mode | planned |
| CP2 | Module 2: ASN, dock booking/gate-in, receiving, ISO 8.7 quarantine, LPN labels, putaway | planned |
| CP3 | Module 3: stock ledger, stock map, lock/unlock, analysis (FSN/ABC/VED/HML/SDE/XYZ/EOQ), cycle count | planned |
| CP4 | Module 4: allocation FEFO/FIFO/LIFO, wave/batch/cluster picking, pack verification, dispatch, manifest | planned |
| CP5 | Module 5: non-conformance, CAPA, calibration, immutable audit trail, SOP registry | planned |
| CP6 | Module 6: IoT gateway (MQTT/REST), orchestrator, standalone toggle, force sync | planned |

## Decisions (owner)
- Stack: Laravel + PostgreSQL + React/TS. Order: as the document.

## Test runner
`php vendor/bin/phpunit` in `backend/` against a scratch PostgreSQL database (`optistash_test`).

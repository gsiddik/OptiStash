# Integration orchestrator and IoT gateway (Module 6)

## Events out
Every business change writes an audit event and an outbox event in one transaction. The orchestrator decides where `shipment.*` and
`outbound.*` events go:

| Setting | Behaviour |
|---|---|
| Standalone on (default for a standalone installation) | Nothing leaves OptiStash; events stay queued. |
| Transport MANUAL | Nothing to deliver. |
| Transport THIRD_PARTY_API | Each event is POSTed as JSON to the https address of the tenant, signed like OptiNexus webhooks: `Opti-Webhook-Id`, `Opti-Webhook-Timestamp`, `Opti-Webhook-Signature: v1=hex(HMAC-SHA256(secret, "<id>.<timestamp>.<body>"))`. Secret: `OPTISTASH_OUTBOX_SECRET`. A failure is retried with back-off (2^n minutes, at most 60) up to 8 attempts; "force sync" retries at once. |
| Transport OPTIDELI | Each dispatched shipment is reported to the OptiNexus event broker (`POST /api/v1/events`, service account scope `event.write`) as `optistash.pickup.requested`; other shipment events are closed without sending. The tenant must be linked to OptiNexus (`optinexus_tenant_id`), else nothing leaves. Retry and back-off as above; the outbox id is the event id, so a retry never makes a second request. |

### OptiDeli pickup contract (owner decision, 2026-11-06)
OptiDeli needs only where to collect and what to collect. `optistash.pickup.requested`, `aggregate_type` `shipment`, payload:

```json
{ "shipment_number": "SHP-000012",
  "warehouse": { "code": "WH1", "name": "Gudang Cikarang", "address": "Jl. ...", "city": "Bekasi" },
  "items": [ { "product_code": "SKU-1", "product_name": "Susu UHT", "quantity": 30, "uom": "PCS" } ] }
```

The warehouse is the place only, never its zones, aisles, racks or bins. Quantities are the packed quantities summed per product in the base
unit. Customers, prices and stock units never leave OptiStash. The event is registered in the OptiNexus event catalog
(`OptiStashEventCatalogSeeder`). OptiDeli does not consume it yet: the subscription `ecosystem:register-event-subscription optideli "optistash.pickup.*" --url=<deli-api>/api/v1/integration/optinexus/events` is added when OptiDeli can turn it into a pickup job.

`php artisan optistash:outbox:deliver` runs every minute from the scheduler; `POST /api/v1/integration/sync` is the manual force sync.

## IoT ingestion
`POST /api/v1/integration/iot/readings` with `Authorization: Bearer <gateway token>` and body
`{"readings":[{"device":"<external id>","value":-18.2,"at":"2026-11-11T08:00:00Z"}, {"topic":"wh1/coldroom/temp","value":-17}]}`
(max 500 per call). The token is shown once at gateway creation; only its SHA-256 is stored. MQTT is bridged: a broker bridge
(Mosquitto, Node-RED, ...) posts the messages to this endpoint with the MQTT topic, and the device's `mqtt_topic` maps it.
Readings older than 7 days or more than 5 minutes in the future are rejected.

A reading outside a device's limits opens one alert per breach series. With *auto lock* on and a bound location or stock unit,
the stock is locked and a non-conformance (source IOT) is raised. A device silent longer than its `stale_after_minutes` raises a STALE
alert (`optistash:iot:check-stale`, every 5 minutes), which closes by itself when the device reports again.

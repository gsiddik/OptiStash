# OptiStash and OptiNexus

Same adapter as OptiDeli (OIDC code flow with PKCE, back-channel logout, permissions pulled from OptiNexus with a cache of at most
300 s and fail-closed, module entitlement filtering, tenant provisioning from `cgo.tenant.application_assigned`).

- Application code: `optistash`. Permission key in OptiNexus = `optistash.<permission code>` (see `PermissionCatalog`).
- Sellable modules (capability = `optistash.<module>`): `analysis`, `quality`, `iot`, `supplier_portal`.
- Not delegable from OptiNexus: `supplier.portal`, `access.role.manage`, `access.user.manage`.
- Manifest for an OptiNexus operator: `php artisan optistash:nexus:manifest`.
- Standalone: `OPTISTASH_DEPLOYMENT_MODE=standalone`, `OPTISTASH_IDENTITY_MODE=local` (users and roles live in OptiStash).

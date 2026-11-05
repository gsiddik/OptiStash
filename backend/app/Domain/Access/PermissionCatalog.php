<?php

namespace App\Domain\Access;

/**
 * Tenant permission registry (code = resource.action) and the system role templates of OptiStash. Authorization checks permissions
 * only; the templates are plain permission collections that follow the role matrix of the product specification (Warehouse Manager,
 * Inbound Operator, Outbound Operator, Supplier, QC & QA Inspector, ISO Quality Auditor, System Administrator). Add permissions here
 * additively and run `php artisan optistash:sync-access`.
 */
final class PermissionCatalog
{
    /** The baseline of every internal (non-supplier) user: without it the warehouse screens answer 403. */
    public const ACCESS = 'wms.access';

    public const DEFINITIONS = [
        'Warehouse' => [
            'wms.access' => 'Use the warehouse application (baseline of every internal user)',
        ],
        'Master data' => [
            'master.view' => 'View warehouses, storage topology, products, packaging and partners',
            'master.manage' => 'Create and change warehouses, storage topology, products, packaging and partners',
            'location.block' => 'Block and unblock storage locations (a location with stock needs a reason)',
            'partner.provision' => 'Provision supplier portal accounts',
        ],
        'Inbound' => [
            'inbound.view' => 'View ASNs, dock bookings, receipts and putaway tasks',
            'asn.manage' => 'Create, change and upload ASNs (also for a supplier)',
            'asn.approve' => 'Confirm and schedule ASNs and approve receiving exceptions',
            'dock.manage' => 'Book docks and record gate-in and gate-out of trucks',
            'receiving.execute' => 'Receive goods against an ASN (scan, lot, expiry, labels)',
            'putaway.execute' => 'Confirm putaway tasks and override a recommended location',
            'qc.inspect' => 'Inspect received goods and decide conforming, quarantine or reject',
        ],
        'Inventory' => [
            'inventory.view' => 'View stock, locations content and the stock map',
            'inventory.lock' => 'Lock and unlock stock (audit hold, quality quarantine)',
            'inventory.analysis.view' => 'View inventory analysis results (FSN, ABC, VED, HML, SDE, XYZ, EOQ)',
            'inventory.analysis.configure' => 'Configure and run inventory analysis and combined matrices',
            'stocktake.manage' => 'Plan and execute cycle counts and stock takes',
            'stocktake.approve' => 'Approve count discrepancies and stock adjustments (never your own)',
        ],
        'Outbound' => [
            'outbound.view' => 'View outbound orders, waves, pick tasks and dispatches',
            'outbound.manage' => 'Create outbound orders, run allocation and plan waves',
            'outbound.execute' => 'Pick, pack and dispatch',
            'wave.approve' => 'Approve waves and partial shipments',
        ],
        'Quality' => [
            'quality.view' => 'View non-conformances, CAPA, calibration and controlled documents',
            'capa.manage' => 'Register non-conformances and manage CAPA with root-cause analysis',
            'calibration.manage' => 'Manage measuring equipment and its calibration',
            'audit.view' => 'Search the immutable audit trail',
            'sop.manage' => 'Manage controlled SOP documents and their versions',
        ],
        'Integration' => [
            'integration.view' => 'View integration status (OptiNexus, TMS, IoT)',
            'integration.manage' => 'Configure transport integration, standalone mode and force a sync',
            'iot.manage' => 'Manage IoT gateways, devices and telemetry alerts',
        ],
        'Supplier portal' => [
            'supplier.portal' => 'Use the supplier portal (requires a supplier-bound membership)',
        ],
        'Access' => [
            'access.role.view' => 'View roles and permissions',
            'access.role.manage' => 'Create, change and delete custom roles',
            'access.user.manage' => 'Assign roles to members',
        ],
    ];

    /**
     * Sellable modules for OptiNexus (capability code = "<application>.<module>"). Permissions not listed here belong to the core
     * that comes with the application. In OptiNexus mode a permission of a module the tenant is not entitled to is dropped.
     */
    public const MODULES = [
        'analysis' => ['name' => 'Inventory analysis', 'description' => 'FSN, ABC, VED, HML, SDE, XYZ and EOQ analysis with combined matrices.', 'permissions' => ['inventory.analysis.view', 'inventory.analysis.configure']],
        'quality' => ['name' => 'Quality management (ISO 9001)', 'description' => 'Non-conformance, CAPA, calibration control, audit trail and controlled documents.', 'permissions' => ['quality.view', 'capa.manage', 'calibration.manage', 'audit.view', 'sop.manage']],
        'iot' => ['name' => 'IoT gateway', 'description' => 'Telemetry ingestion, environmental monitoring and sensor binding.', 'permissions' => ['iot.manage']],
        'supplier_portal' => ['name' => 'Supplier portal', 'description' => 'External supplier self-service for purchase orders and ASNs.', 'permissions' => ['supplier.portal', 'partner.provision']],
    ];

    /** Permissions an administrator may not hand out from OptiNexus (a supplier needs a supplier-bound membership; access is governed centrally). */
    public const NOT_DELEGABLE = ['supplier.portal', 'access.role.manage', 'access.user.manage'];

    /** Module (without application prefix) of a permission, or null for the core. */
    public static function moduleOf(string $code): ?string
    {
        foreach (self::MODULES as $module => $definition) {
            if (in_array($code, $definition['permissions'], true)) {
                return $module;
            }
        }

        return null;
    }

    /** System role templates: name => permission codes. `tenant_administrator` is the System Administrator of the role matrix. */
    public const SYSTEM_ROLES = [
        'viewer' => ['wms.access', 'master.view', 'inbound.view', 'inventory.view', 'outbound.view'],
        'warehouse_manager' => [
            'wms.access', 'master.view', 'master.manage', 'location.block', 'partner.provision',
            'inbound.view', 'asn.manage', 'asn.approve', 'dock.manage', 'receiving.execute', 'putaway.execute', 'qc.inspect',
            'inventory.view', 'inventory.lock', 'inventory.analysis.view', 'inventory.analysis.configure', 'stocktake.manage', 'stocktake.approve',
            'outbound.view', 'outbound.manage', 'outbound.execute', 'wave.approve',
            'quality.view', 'capa.manage', 'calibration.manage', 'sop.manage', 'audit.view', 'integration.view', 'integration.manage', 'iot.manage',
        ],
        'inbound_operator' => ['wms.access', 'master.view', 'inbound.view', 'asn.manage', 'dock.manage', 'receiving.execute', 'putaway.execute', 'inventory.view', 'quality.view'],
        'outbound_operator' => ['wms.access', 'master.view', 'outbound.view', 'outbound.execute', 'inventory.view', 'quality.view'],
        'supplier' => ['supplier.portal'],
        'qc_inspector' => ['wms.access', 'master.view', 'inbound.view', 'qc.inspect', 'inventory.view', 'inventory.lock', 'outbound.view', 'quality.view', 'capa.manage', 'calibration.manage'],
        'quality_auditor' => ['wms.access', 'master.view', 'inbound.view', 'inventory.view', 'inventory.analysis.view', 'outbound.view', 'quality.view', 'capa.manage', 'audit.view', 'integration.view'],
        'tenant_administrator' => ['*'],
    ];

    /** @return list<string> */
    public static function codes(): array
    {
        return array_merge(...array_map('array_keys', array_values(self::DEFINITIONS)));
    }

    /** @return list<array{code:string,group:string,description:string}> */
    public static function rows(): array
    {
        $rows = [];
        foreach (self::DEFINITIONS as $group => $permissions) {
            foreach ($permissions as $code => $description) {
                $rows[] = compact('code', 'group', 'description');
            }
        }

        return $rows;
    }

    /** @return list<string> */
    public static function templateCodes(string $role): array
    {
        $codes = self::SYSTEM_ROLES[$role] ?? [];

        return $codes === ['*'] ? array_values(array_diff(self::codes(), ['supplier.portal'])) : $codes;
    }
}

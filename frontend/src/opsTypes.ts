export type Trip = {
  id: string;
  order_id: string;
  plan_id: string;
  vehicle_id: string;
  driver_id: string;
  vendor_id: string | null;
  contract_reference: string | null;
  status: string;
  active: boolean;
  created_at: string;
  started_at: string | null;
  completed_at: string | null;
  order?: {
    number: string;
    origin: string;
    destination: string;
    sell_minor: number;
  };
  plan?: {
    vendor_base_minor: number;
    vendor_accessorial_minor: number;
    cost_minor: number;
  };
  legs?: Leg[];
  pods?: Pod[];
  claims?: Claim[];
};
export type Leg = {
  id: string;
  sequence: number;
  origin: string;
  destination: string;
  planned_eta: string;
  status: string;
  started_at: string | null;
  arrived_at: string | null;
};
export type Pod = {
  id: string;
  trip_id: string;
  receiver_name: string;
  outcome: string;
  delivered_units: number;
  status: string;
  submitted_by: number;
};
export type Claim = {
  id: string;
  trip_id: string;
  category: string;
  amount_minor: number;
  reason: string;
  evidence_reference: string;
  status: string;
  submitted_by: number;
};
export type Vendor = {
  id: string;
  name: string;
  code: string;
  active: boolean;
};
export type Dock = { id: string; name: string; hub: string };
export type Appointment = {
  id: string;
  trip_id: string;
  dock_id: string;
  starts_at: string;
  ends_at: string;
  status: string;
  gate_in_at: string | null;
  dock_in_at: string | null;
  dock_out_at: string | null;
  gate_out_at: string | null;
};
export type Dispute = {
  id: string;
  invoice_id: string;
  message: string;
  status: string;
  vendor_response: string | null;
  resolution: string | null;
  system_key: string | null;
};
export type Invoice = {
  id: string;
  trip_id: string;
  vendor_id: string;
  number: string;
  base_minor: number;
  accessorial_minor: number;
  rounding_minor: number;
  total_minor: number;
  status: string;
  hold_reason: string;
  approval_tier: string;
  version: number;
  submitted_by: number;
  trip?: Trip;
  plan?: { vendor_base_minor: number; vendor_accessorial_minor: number };
  claims?: Claim[];
  disputes?: Dispute[];
  approvals?: { authority: string; actor_id: number; note: string }[];
};
/** Capability checks for the UI. The backend enforces every one of them; these only hide what would be refused. */
type Perms = { permissions: string[] };
export const can = (s: Perms, permission: string) =>
  s.permissions.includes(permission);
export const canOperate = (s: Perms) => can(s, "operations.manage");
export const canFinance = (s: Perms) => can(s, "finance.access");
export const canVerify = (s: Perms) => can(s, "execution.verify");
export const isVendorSession = (s: Perms) => can(s, "vendor.portal");
export const isCustomerSession = (s: Perms) => can(s, "customer.portal");
export const dispatchApprovalLevel = (s: Perms) =>
  [3, 2, 1].find((level) => can(s, `dispatch.approve.level${level}`)) ?? 0;
const tierPermissions: Record<string, string[]> = {
  FINANCE: ["freight.approve.finance"],
  DUAL: [
    "freight.approve.finance_supervisor",
    "freight.approve.logistics_manager",
  ],
  CFO: ["freight.approve.cfo"],
};
export const canApproveTier = (s: Perms, tier: string) =>
  (tierPermissions[tier] ?? []).some((p) => can(s, p));

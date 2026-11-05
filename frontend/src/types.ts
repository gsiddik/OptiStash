export type Session = {
  id: number;
  name: string;
  role: string;
  permissions: string[];
  tenant: { name: string; slug: string };
  supplier?: boolean;
  deployment_mode: string;
  identity_mode?: string;
};
export type Page<T> = {
  data: T[];
  current_page: number;
  last_page: number;
  total: number;
};
/** Display names of the system roles (role matrix of the product specification). */
export const roles: Record<string, string> = {
  viewer: "Peninjau",
  warehouse_manager: "Warehouse Manager",
  inbound_operator: "Inbound Operator",
  outbound_operator: "Outbound Operator",
  supplier: "Pemasok (portal eksternal)",
  qc_inspector: "QC & QA Inspector",
  quality_auditor: "ISO Quality Auditor",
  tenant_administrator: "System Administrator",
};

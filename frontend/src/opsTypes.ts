/** Capability checks for the UI. The backend enforces every one of them; these only hide what would be refused. */
type Perms = { permissions: string[] };
export const can = (s: Perms, permission: string) =>
  s.permissions.includes(permission);
export const isSupplierSession = (s: Perms) => can(s, "supplier.portal");

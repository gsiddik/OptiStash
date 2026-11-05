import { describe, expect, it } from "vitest";
import { can, isSupplierSession } from "./opsTypes";
import { roles } from "./types";

describe("capability checks", () => {
  it("only hides what the session lacks", () => {
    const s = { permissions: ["wms.access", "master.view"] };
    expect(can(s, "master.view")).toBe(true);
    expect(can(s, "master.manage")).toBe(false);
    expect(isSupplierSession(s)).toBe(false);
    expect(isSupplierSession({ permissions: ["supplier.portal"] })).toBe(true);
  });
  it("names every system role of the role matrix", () => {
    expect(Object.keys(roles)).toEqual(
      expect.arrayContaining(["warehouse_manager", "inbound_operator", "outbound_operator", "supplier", "qc_inspector", "quality_auditor", "tenant_administrator"]),
    );
  });
});

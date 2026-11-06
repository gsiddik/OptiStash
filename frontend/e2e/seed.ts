import { request } from "@playwright/test";
import type { APIRequestContext } from "@playwright/test";

/**
 * A small warehouse written through the API, so that the pages are opened with data in them: master data, storage locations, an ASN that is
 * received and put away, an outbound order that is allocated, picked, packed and shipped, plus a CAPA, an instrument, a document, a stock take
 * and an IoT gateway. Local mode only (password sign-in of the demo tenant).
 */
export async function seed(base: string, password: string) {
  const ctx: APIRequestContext = await request.newContext({ baseURL: base });
  let token = "";
  const refresh = async () => {
    token = (await (await ctx.get("/api/v1/csrf")).json()).token;
  };
  const call = async (
    method: "GET" | "POST" | "PUT",
    path: string,
    data?: unknown,
  ) => {
    const res = await ctx.fetch("/api/v1/" + path, {
      method,
      data,
      headers: { Accept: "application/json", "X-CSRF-TOKEN": token },
    });
    const text = await res.text();
    if (!res.ok())
      throw new Error(
        `${method} ${path} -> ${res.status()}: ${text.slice(0, 300)}`,
      );
    return text ? JSON.parse(text) : {};
  };
  await refresh();
  await call("POST", "auth/login", {
    tenant: "demo",
    email: "warehouse_manager@optistash.test",
    password,
  });
  await refresh();
  const me = (await call("GET", "auth/me")).data;

  const id = async (path: string, body: unknown) =>
    (await call("POST", path, body)).data.id as string;
  const wh = await id("master/warehouses", {
    code: "WH1",
    name: "Gudang Utama",
    granularity: "BIN",
    city: "Bekasi",
    address: "Jl. Industri 1",
  });
  const pcs = await id("master/uoms", { code: "PCS", name: "Pieces" });
  const rice = await id("master/products", {
    code: "RICE",
    name: "Beras 5 kg",
    base_uom_id: pcs,
    rotation_policy: "FIFO",
    track_lot: false,
    track_expiry: false,
    temperature_class: "AMBIENT",
    weight_g: 5000,
    barcode: "BC-RICE",
  });
  const milk = await id("master/products", {
    code: "MILK",
    name: "Susu UHT",
    base_uom_id: pcs,
    rotation_policy: "FEFO",
    track_lot: true,
    track_expiry: true,
    shelf_life_days: 365,
    min_remaining_shelf_life_days: 30,
    temperature_class: "AMBIENT",
    barcode: "BC-MILK",
  });
  const supplier = await id("master/partners", {
    code: "SUP1",
    name: "PT Pemasok Satu",
    type: "SUPPLIER",
    email: "sup@example.test",
  });
  const customer = await id("master/partners", {
    code: "CUS1",
    name: "PT Pelanggan Satu",
    type: "CUSTOMER",
  });
  const dock = await id("master/docks", {
    code: "D1",
    name: "Dock 1",
    warehouse_id: wh,
  });

  const zone = await id(`warehouses/${wh}/locations`, {
    code: "Z1",
    name: "Zona 1",
  });
  const aisle = await id(`warehouses/${wh}/locations`, {
    parent_id: zone,
    code: "Z1-A1",
    name: "Lorong 1",
  });
  const rack = await id(`warehouses/${wh}/locations`, {
    parent_id: aisle,
    code: "Z1-A1-R1",
    name: "Rak 1",
  });
  const shelf = await id(`warehouses/${wh}/locations`, {
    parent_id: rack,
    code: "Z1-A1-R1-S1",
    name: "Tingkat 1",
  });
  await call("POST", `warehouses/${wh}/locations/generate`, {
    parent_id: shelf,
    prefix: "B",
    count: 6,
  });

  // Inbound: ASN, dock booking, gate in, receive, put away.
  const asn = (
    await call("POST", "asns", {
      supplier_id: supplier,
      warehouse_id: wh,
      expected_date: new Date(Date.now() + 86400000).toISOString().slice(0, 10),
      reference: "PO-QA-1",
      lines: [
        { product_id: rice, expected_qty: 100 },
        { product_id: milk, expected_qty: 48 },
      ],
    })
  ).data;
  await call("POST", `asns/${asn.id}/submit`);
  await call("POST", `asns/${asn.id}/confirm`);
  const at = (h: number) => {
    const d = new Date(Date.now() + 86400000);
    d.setUTCHours(h, 0, 0, 0);
    return d.toISOString();
  };
  const booking = (
    await call("POST", "dock-bookings", {
      dock_id: dock,
      asn_id: asn.id,
      slot_start: at(1),
      slot_end: at(3),
    })
  ).data;
  await call("POST", `dock-bookings/${booking.id}/gate-in`, {
    truck_plate: "B 1234 XY",
  });
  const lines = (await call("GET", `asns/${asn.id}`)).data.lines as {
    id: string;
    product_id: string;
  }[];
  const riceLine = lines.find((l) => l.product_id === rice)!.id;
  const milkLine = lines.find((l) => l.product_id === milk)!.id;
  await call("POST", `asn-lines/${riceLine}/receive`, { quantity: 60 });
  await call("POST", `asn-lines/${riceLine}/receive`, { quantity: 40 });
  await call("POST", `asn-lines/${milkLine}/receive`, {
    quantity: 48,
    lot_no: "L-QA-1",
    expiry_date: new Date(Date.now() + 200 * 86400000)
      .toISOString()
      .slice(0, 10),
  });
  const tasks = (await call("GET", "putaway-tasks")).data as {
    id: string;
    status: string;
  }[];
  for (const t of tasks.filter((x) => x.status !== "DONE"))
    await call("POST", `putaway-tasks/${t.id}/complete`, {});
  await call("POST", `asns/${asn.id}/complete`, {});

  // Outbound: order, allocation, wave, picking, packing, shipment.
  await call("PUT", "integration/transport", { mode: "OPTIDELI" });
  const order = (
    await call("POST", "outbound-orders", {
      customer_id: customer,
      warehouse_id: wh,
      required_date: new Date(Date.now() + 86400000).toISOString().slice(0, 10),
      reference: "PO-C-QA",
      ship_to: "Jl. Pelanggan 1, Jakarta",
      lines: [
        { product_id: rice, ordered_qty: 30 },
        { product_id: milk, ordered_qty: 12 },
      ],
    })
  ).data;
  await call("POST", `outbound-orders/${order.id}/release`);
  await call("POST", `outbound-orders/${order.id}/allocate`);
  const wave = (
    await call("POST", "waves", { type: "WAVE", order_ids: [order.id] })
  ).data;
  const waveDetail = (await call("GET", `waves/${wave.id}`)).data;
  for (const t of (waveDetail.tasks ?? []) as {
    id: string;
    lpn: string;
    quantity: number;
  }[])
    await call("POST", `pick-tasks/${t.id}/pick`, {
      lpn: t.lpn,
      quantity: t.quantity,
    });
  const pkg = (await call("POST", `outbound-orders/${order.id}/packages`)).data;
  await call("POST", `packages/${pkg.id}/items`, {
    scan: "BC-RICE",
    quantity: 30,
  });
  await call("POST", `packages/${pkg.id}/items`, {
    scan: "BC-MILK",
    quantity: 12,
  });
  await call("POST", `packages/${pkg.id}/close`);
  await call("POST", `outbound-orders/${order.id}/pack-complete`, {});
  const ship = (
    await call("POST", "shipments", {
      warehouse_id: wh,
      driver_name: "Joko",
      truck_plate: "B 9999 QA",
    })
  ).data;
  await call("POST", `shipments/${ship.id}/orders`, { order_id: order.id });
  await call("POST", `shipments/${ship.id}/dispatch`);
  // A second shipment that is still being loaded.
  await call("POST", "shipments", { warehouse_id: wh });

  // Quality, inventory control, integration.
  const capa = (
    await call("POST", "capas", {
      type: "PREVENTIVE",
      title: "Latih operator forklift",
      description: "Pelatihan keselamatan",
      owner_id: me.id,
      due_date: new Date(Date.now() + 14 * 86400000).toISOString().slice(0, 10),
    })
  ).data.id as string;
  await call("POST", "instruments", {
    code: "TH-01",
    name: "Termometer dock",
    kind: "THERMOMETER",
    interval_days: 180,
  });
  const document = (
    await call("POST", "documents", {
      code: "SOP-001",
      title: "Penerimaan barang",
      category: "SOP",
      content: "Langkah 1. Periksa segel.",
      change_summary: "Versi awal",
    })
  ).data.id as string;
  const stocktake = (await call("POST", "stocktakes", { warehouse_id: wh }))
    .data.id as string;
  await call("POST", "iot/gateways", { name: "Gateway QA", protocol: "REST" });
  await ctx.dispose();
  return {
    asn: asn.id as string,
    order: order.id as string,
    wave: wave.id as string,
    capa,
    document,
    stocktake,
  };
}

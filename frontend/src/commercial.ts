/** IDR input to minor units without floating-point arithmetic. */
export function minor(value: string): number {
  if (!/^\d{1,11}(?:[.,]\d{1,2})?$/.test(value.trim()))
    throw new Error(
      "Masukkan nominal positif, maksimal 11 digit dan 2 desimal.",
    );
  const [whole, fraction = ""] = value.trim().replace(",", ".").split(".");
  const result = BigInt(whole) * 100n + BigInt(fraction.padEnd(2, "0"));
  if (result > 1000000000000n) throw new Error("Nominal melampaui batas.");
  return Number(result);
}
export function approvalLevel(
  sell: number,
  cost: number,
  threshold: number,
): number {
  if (sell <= 0) return 3;
  const contribution = BigInt(sell - cost) * 10000n;
  if (contribution >= BigInt(sell) * BigInt(threshold)) return 0;
  if (contribution >= BigInt(sell) * 1000n) return 1;
  if (contribution >= BigInt(sell) * 500n) return 2;
  return 3;
}
export const money = (value: number) =>
  new Intl.NumberFormat("id-ID", {
    style: "currency",
    currency: "IDR",
    minimumFractionDigits: 2,
  }).format(value / 100);
export const percentage = (sell: number, cost: number) =>
  sell > 0
    ? `${(((sell - cost) / sell) * 100).toLocaleString("id-ID", { maximumFractionDigits: 2 })}%`
    : "—";
export const dateTime = (value: string) =>
  new Intl.DateTimeFormat("id-ID", {
    dateStyle: "medium",
    timeStyle: "short",
  }).format(new Date(value));

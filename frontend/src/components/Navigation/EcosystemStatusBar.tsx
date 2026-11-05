import React, { useState, useEffect } from "react";
import { api } from "../../api";
import type { EcosystemContext } from "../../types/ecosystem";

export const EcosystemStatusBar: React.FC = () => {
  const [context, setContext] = useState<EcosystemContext | null>(null);

  useEffect(() => {
    api<{ data: EcosystemContext }>("ecosystem/context")
      .then((data) => setContext(data.data))
      .catch((err) => console.error(err));
  }, []);

  if (!context) return null;

  const isSaas = context.deployment_mode === "saas";

  return (
    <div className="flex flex-wrap items-center justify-between border-b border-slate-800 bg-slate-950 px-4 py-2 text-xs text-slate-300">
      <div className="flex items-center gap-3">
        <span
          className={`rounded px-2 py-0.5 font-bold uppercase tracking-wider ${
            isSaas
              ? "border border-cyan-800 bg-cyan-950/70 text-cyan-400"
              : "border border-amber-800 bg-amber-950/70 text-amber-400"
          }`}
        >
          {isSaas ? "Cloud SaaS (OptiNexus SSO)" : "Standalone (Air-Gapped)"}
        </span>
        <span className="font-mono text-slate-400">
          Tenant:{" "}
          <span className="text-slate-200">{context.identity.tenant_id}</span>
        </span>
        <span className="text-slate-500">|</span>
        <span className="text-slate-400">
          User:{" "}
          <span className="text-slate-200">{context.identity.user_name}</span> (
          {context.identity.role})
        </span>
      </div>

      <div className="flex items-center gap-2 text-[11px]">
        <span className="text-slate-500">Adapters:</span>
        <span
          className={`rounded px-1.5 py-0.5 border ${
            context.integrations.optiradar
              ? "border-cyan-800 text-cyan-400 bg-cyan-950/40"
              : "border-slate-800 text-slate-400"
          }`}
        >
          Radar: {context.active_drivers.telematics}
        </span>
        <span
          className={`rounded px-1.5 py-0.5 border ${
            context.integrations.optifleet
              ? "border-cyan-800 text-cyan-400 bg-cyan-950/40"
              : "border-slate-800 text-slate-400"
          }`}
        >
          Fleet: {context.active_drivers.fleet}
        </span>
        <span
          className={`rounded px-1.5 py-0.5 border ${
            context.integrations.optientry
              ? "border-cyan-800 text-cyan-400 bg-cyan-950/40"
              : "border-slate-800 text-slate-400"
          }`}
        >
          Accounting: {context.active_drivers.accounting}
        </span>
      </div>
    </div>
  );
};

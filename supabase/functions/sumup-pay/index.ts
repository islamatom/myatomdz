// ATOM Europe — paiement en ligne SumUp (Hosted Checkout)
// Secrets à mettre dans Supabase (Edge Functions → Secrets) : SUMUP_API_KEY, SUMUP_MERCHANT_CODE
// La clé SumUp reste ici, côté serveur : elle n'est jamais dans le site ni sur GitHub.
import { createClient } from "https://esm.sh/@supabase/supabase-js@2.45.4";

const CORS = {
  "Access-Control-Allow-Origin": "https://atomdz.com",
  "Access-Control-Allow-Headers": "authorization, apikey, content-type, x-client-info",
  "Access-Control-Allow-Methods": "POST, OPTIONS",
};
const API = "https://api.sumup.com/v0.1/checkouts";
const KEY = Deno.env.get("SUMUP_API_KEY") ?? "";
const MERCHANT = Deno.env.get("SUMUP_MERCHANT_CODE") ?? "";
const db = createClient(Deno.env.get("SUPABASE_URL")!, Deno.env.get("SUPABASE_SERVICE_ROLE_KEY")!);
const json = (b: unknown, s = 200) => new Response(JSON.stringify(b), { status: s, headers: { ...CORS, "Content-Type": "application/json" } });

// Demande à SumUp l'état réel du paiement, puis marque la commande payée si c'est le cas
async function sync(checkoutId: string) {
  const r = await fetch(`${API}/${checkoutId}`, { headers: { Authorization: `Bearer ${KEY}` } });
  if (!r.ok) return false;
  const c = await r.json();
  if (c.status !== "PAID") return false;
  const { data: o } = await db.from("site_orders").select("id, note, status, paid_at").eq("sumup_id", checkoutId).maybeSingle();
  if (o && !o.paid_at) {
    await db.from("site_orders").update({
      paid_at: new Date().toISOString(),
      status: o.status === "new" ? "confirmed" : o.status,
      status_changed_at: new Date().toISOString(),
      note: ("✅ PAYÉ en ligne (SumUp) · " + (o.note ?? "")).slice(0, 250),
    }).eq("id", o.id);
  }
  return true;
}

Deno.serve(async (req) => {
  if (req.method === "OPTIONS") return new Response("ok", { headers: CORS });
  if (!KEY || !MERCHANT) return json({ error: "not_configured" }, 503);
  const url = new URL(req.url);
  let body: Record<string, unknown> = {};
  try { body = await req.json(); } catch (_) { /* vide */ }

  // Appel de SumUp quand l'état d'un paiement change
  if (url.searchParams.get("hook") === "1") {
    if (typeof body.id === "string") await sync(body.id);
    return new Response("ok");
  }

  const orderNo = String(body.order_no ?? "");
  const phone = String(body.phone ?? "").replace(/[^0-9+]/g, "");
  if (!/^AT-\d{1,8}$/.test(orderNo) || phone.length < 8) return json({ error: "bad" }, 400);
  const { data: o } = await db.from("site_orders").select("id, order_no, phone, total_da, currency, paid_at, sumup_id, sumup_url, items")
    .eq("order_no", orderNo).eq("phone", phone).maybeSingle();
  if (!o || o.currency !== "EUR") return json({ error: "not_found" }, 404);

  if (body.action === "check") {
    const paid = !!o.paid_at || (o.sumup_id ? await sync(o.sumup_id) : false);
    return json({ paid, url: paid ? null : o.sumup_url });
  }

  // création du paiement (une seule fois par commande)
  if (o.paid_at) return json({ paid: true });
  if (o.sumup_url) return json({ url: o.sumup_url });
  const items = (o.items ?? []) as { name: string; qty: number }[];
  const fnUrl = `${Deno.env.get("SUPABASE_URL")}/functions/v1/sumup-pay?hook=1`;
  const r = await fetch(API, {
    method: "POST",
    headers: { "Content-Type": "application/json", Authorization: `Bearer ${KEY}` },
    body: JSON.stringify({
      checkout_reference: `${o.order_no}-${o.id.slice(0, 8)}`,
      amount: Number(o.total_da),
      currency: "EUR",
      merchant_code: MERCHANT,
      description: `ATOM ${o.order_no} · ${items.map((i) => `${i.qty}× ${i.name}`).join(", ")}`.slice(0, 250),
      redirect_url: `https://atomdz.com/eu/?cmd=${encodeURIComponent(o.order_no)}`,
      return_url: fnUrl,
      hosted_checkout: { enabled: true },
    }),
  });
  const c = await r.json().catch(() => ({}));
  if (!r.ok || !c.hosted_checkout_url) return json({ error: "sumup", detail: c?.message ?? c?.error_code ?? r.status }, 502);
  await db.from("site_orders").update({ sumup_id: c.id, sumup_url: c.hosted_checkout_url }).eq("id", o.id);
  return json({ url: c.hosted_checkout_url });
});

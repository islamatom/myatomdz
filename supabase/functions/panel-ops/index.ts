// ATOM — actions du panel qui parlent à l'extérieur : DHD (colis Algérie) et e-mail « commande envoyée » (Europe).
// Secrets Supabase : DHD_TOKEN (ECOTRACK DHD), BREVO_API_KEY, BREVO_SENDER (adresse expéditeur validée dans Brevo).
// Chaque appel doit donner le code du panel (patron ou Salima) : sans bon code, rien ne se passe.
import { createClient } from "https://esm.sh/@supabase/supabase-js@2.45.4";

const CORS = {
  "Access-Control-Allow-Origin": "https://atomdz.com",
  "Access-Control-Allow-Headers": "authorization, apikey, content-type, x-client-info",
  "Access-Control-Allow-Methods": "POST, OPTIONS",
};
const DHD = "https://platform.dhd-dz.com/api/v1";
const DHD_TOKEN = Deno.env.get("DHD_TOKEN") ?? "";
const BREVO = Deno.env.get("BREVO_API_KEY") ?? "";
const SENDER = Deno.env.get("BREVO_SENDER") ?? "";
const db = createClient(Deno.env.get("SUPABASE_URL")!, Deno.env.get("SUPABASE_SERVICE_ROLE_KEY")!);
const json = (b: unknown, s = 200) => new Response(JSON.stringify(b), { status: s, headers: { ...CORS, "Content-Type": "application/json" } });
const esc = (s: unknown) => String(s ?? "").replace(/[&<>"]/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;" }[c]!));
const isEU = (o: any) => o.currency === "EUR" || String(o.wilaya ?? "").startsWith("🇪🇺");
const FINAL = ["paye_et_archive", "retour_archive", "annule"];

async function dhd(path: string, method = "GET") {
  const r = await fetch(`${DHD}${path}`, { method, headers: { Authorization: `Bearer ${DHD_TOKEN}`, Accept: "application/json" } });
  const t = await r.text();
  let j: any = null; try { j = JSON.parse(t); } catch (_) { j = { raw: t.slice(0, 300) }; }
  return { ok: r.ok, status: r.status, j };
}
async function setting(key: string) { const { data } = await db.from("site_settings").select("val").eq("key", key).maybeSingle(); return data?.val ?? null; }

async function mailCustomer(o: any, subject: string, title: string, text: string) {
  if (!BREVO || !SENDER || !o.email) return false;
  const items = ((o.items ?? []) as any[]).map((i) => `<li>${i.qty} × ${esc(i.name)}</li>`).join("");
  const html = `<div style="font-family:Arial,sans-serif;font-size:15px;color:#111;max-width:560px">
    <h2 style="margin:0 0 10px">${title}</h2><p>Bonjour ${esc(String(o.name).split(" ")[0])},</p><p>${text}</p>
    <p><b>Commande ${esc(o.order_no)}</b></p><ul>${items}</ul>
    <p>Total payé : <b>${esc(o.total_da)} € TVA comprise</b></p>
    <p>Une question ? Répondez à cet e-mail ou écrivez-nous sur WhatsApp : <a href="https://wa.me/32499374830">+32 499 37 48 30</a></p>
    <p style="color:#666">ATOM — atomdz.com/eu</p></div>`;
  const r = await fetch("https://api.brevo.com/v3/smtp/email", {
    method: "POST", headers: { "api-key": BREVO, "Content-Type": "application/json", accept: "application/json" },
    body: JSON.stringify({ sender: { name: "ATOM", email: SENDER }, to: [{ email: o.email, name: o.name }], subject, htmlContent: html }),
  }).catch(() => null);
  return !!r && r.ok;
}

Deno.serve(async (req) => {
  if (req.method === "OPTIONS") return new Response("ok", { headers: CORS });
  let b: any = {}; try { b = await req.json(); } catch (_) { /* vide */ }
  const { data: role } = await db.rpc("panel_whoami", { p_code: String(b.code ?? "") });
  if (role !== "admin" && role !== "staff") return json({ error: "code" }, 401);
  const act = String(b.action ?? "");

  // ---------- Europe : commande envoyée → e-mail au client ----------
  if (act === "eu_ship") {
    const { data: o } = await db.from("site_orders").select("*").eq("id", b.id).maybeSingle();
    if (!o || !isEU(o)) return json({ error: "not_found" }, 404);
    await db.from("site_orders").update({ status: "delivered", status_changed_at: new Date().toISOString() }).eq("id", o.id);
    const mailed = await mailCustomer(o, `📦 Votre commande ${o.order_no} est en route`, "📦 Votre commande est en route !",
      "Bonne nouvelle : votre colis vient de partir. Vous le recevrez dans <b>2 à 3 jours</b>.");
    return json({ ok: true, mailed });
  }

  if (!DHD_TOKEN) return json({ error: "dhd_not_configured" }, 503);

  // ---------- DHD : produits et stock ----------
  if (act === "dhd_stock") {
    const r = await dhd("/get/products/list");
    if (!r.ok) return json({ error: "dhd", detail: r.j }, 502);
    return json({ products: r.j?.products ?? [], map: (await setting("dhd_map")) ?? {} });
  }
  if (act === "dhd_map") {
    if (role !== "admin") return json({ error: "admin" }, 403);
    await db.from("site_settings").upsert({ key: "dhd_map", val: b.map ?? {} });
    return json({ ok: true });
  }

  // ---------- DHD : créer le colis (pris dans le stock chez DHD) ----------
  if (act === "dhd_send") {
    const { data: o } = await db.from("site_orders").select("*").eq("id", b.id).maybeSingle();
    if (!o || isEU(o)) return json({ error: "not_found" }, 404);
    if (o.dhd_tracking) return json({ tracking: o.dhd_tracking });
    const map = ((await setting("dhd_map")) ?? {}) as Record<string, string>;
    const lines = ((o.items ?? []) as any[]).filter((i) => map[i.key]);
    if (!lines.length) return json({ error: "no_product", detail: "Aucun produit de cette commande n'est relié au stock DHD (réglage 📦 Stock DHD)." }, 400);
    const wil = parseInt(String(o.wilaya ?? "").match(/\d+/)?.[0] ?? "0", 10);
    const p = new URLSearchParams({
      reference: o.order_no, nom_client: o.name, telephone: String(o.phone ?? "").replace(/\D/g, ""),
      adresse: o.address || o.commune || "-", commune: o.commune || "", code_wilaya: String(wil),
      montant: String(o.total_da), remarque: String(o.note ?? "").slice(0, 250), type: "1",
      stop_desk: o.ship_type === "office" ? "1" : "0", stock: "1",
      produit: lines.map((i) => map[i.key]).join(","), quantite: lines.map((i) => String(i.qty || 1)).join(","),
    });
    if (o.phone2) p.set("telephone_2", String(o.phone2).replace(/\D/g, ""));
    const r = await dhd(`/create/order?${p.toString()}`, "POST");
    const tracking = r.j?.tracking ?? r.j?.data?.tracking ?? null;
    if (!r.ok || !tracking) return json({ error: "dhd", detail: r.j?.errors ?? r.j?.message ?? r.j }, 400);
    await db.from("site_orders").update({ dhd_tracking: tracking, dhd_status: "prete_a_expedier", status: o.status === "new" ? "confirmed" : o.status, status_changed_at: new Date().toISOString() }).eq("id", o.id);
    return json({ tracking });
  }

  // ---------- DHD : mettre à jour les statuts ----------
  if (act === "dhd_sync") {
    const { data: rows } = await db.from("site_orders").select("id, dhd_tracking, dhd_status, status").not("dhd_tracking", "is", null).eq("deleted", false).limit(300);
    const todo = (rows ?? []).filter((x: any) => !FINAL.includes(x.dhd_status ?? ""));
    let changed = 0;
    for (let i = 0; i < todo.length; i += 100) {
      const part = todo.slice(i, i + 100);
      const r = await dhd(`/get/orders/status?api_token=${encodeURIComponent(DHD_TOKEN)}&trackings=${part.map((x: any) => x.dhd_tracking).join(",")}&status=all`);
      const data = r.j?.data ?? {};
      for (const x of part) {
        const st = data[x.dhd_tracking]?.status;
        if (!st || st === x.dhd_status) continue;
        const delivered = /^(livre|encaisse|paiement|paye)/.test(st);
        await db.from("site_orders").update({ dhd_status: st, ...(delivered && x.status !== "delivered" ? { status: "delivered", status_changed_at: new Date().toISOString() } : {}) }).eq("id", x.id);
        changed++;
      }
    }
    return json({ ok: true, changed });
  }
  return json({ error: "action" }, 400);
});

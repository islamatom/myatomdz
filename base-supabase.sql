-- =====================================================================
--  MyAtomDZ — base de données (site + panel des commandes)
--  À coller UNE FOIS dans Supabase → SQL Editor → Run
--  Projet séparé "myatomdz" (ne rien mélanger avec les autres apps)
-- =====================================================================
create extension if not exists pgcrypto;

-- ---------- produits (prix lus par le site) ----------
create table if not exists site_catalog (
  key text primary key,
  name text not null,
  price_da integer not null check (price_da >= 0),
  kind text not null check (kind in ('device','accessory','subscription')),
  sort_order integer not null default 0,
  active boolean not null default true
);
insert into site_catalog (key, name, price_da, kind, sort_order) values
  ('atom_s3',       'ATOM S3',                          22500, 'device',       1),
  ('remote',        'Télécommande ATOM',                 2500, 'accessory',    2),
  ('promax_bronze', 'ATOM Pro Max 4K · Bronze (1 an)',   6500, 'subscription', 3),
  ('promax_silver', 'ATOM Pro Max 4K · Silver (1 an)',   8500, 'subscription', 4),
  ('promax_gold',   'ATOM Pro Max 4K · Gold (1 an)',    12500, 'subscription', 5)
on conflict (key) do nothing;

-- ---------- commandes ----------
create sequence if not exists site_order_no start 1201;
create table if not exists site_orders (
  id uuid primary key default gen_random_uuid(),
  order_no text not null unique default ('AT-' || nextval('site_order_no')),
  created_at timestamptz not null default now(),
  status text not null default 'new' check (status in ('new','confirmed','postponed','noanswer','cancelled','delivered')),
  status_changed_at timestamptz,
  name text not null,
  phone text not null,
  phone2 text,
  wilaya text not null,
  commune text,
  address text,
  note text,
  items jsonb not null,
  ship_type text check (ship_type in ('home','office')),
  ship_fee integer not null default 0,
  device text check (device in ('box','other')),
  renew boolean not null default false,
  total_da integer not null,
  deleted boolean not null default false
);
create index if not exists site_orders_created on site_orders (created_at desc);

-- ---------- statistiques de visite (sans données personnelles) ----------
create table if not exists site_events (
  id bigint generated always as identity primary key,
  at timestamptz not null default now(),
  visitor text, event text, item text, source text, device text, lang text
);

-- ---------- code du panel (gardé chiffré) + anti-essais ----------
create table if not exists panel_settings (id int primary key default 1 check (id = 1), code_hash text not null);
create table if not exists panel_attempts (at timestamptz not null default now(), ok boolean not null);

-- personne ne lit les tables directement : tout passe par les fonctions
alter table site_catalog   enable row level security;
alter table site_orders    enable row level security;
alter table site_events    enable row level security;
alter table panel_settings enable row level security;
alter table panel_attempts enable row level security;
drop policy if exists "catalogue visible" on site_catalog;
create policy "catalogue visible" on site_catalog for select to anon using (active);

-- ---------- vérifier le code du panel ----------
create or replace function panel_check(p_code text) returns boolean
language plpgsql security definer set search_path = public, extensions as $$
declare bad int; ok boolean;
begin
  select count(*) into bad from panel_attempts where not panel_attempts.ok and at > now() - interval '15 minutes';
  if bad >= 10 then return false; end if;   -- trop d'essais : bloqué 15 minutes
  select (code_hash = crypt(coalesce(p_code,''), code_hash)) into ok from panel_settings where id = 1;
  insert into panel_attempts (ok) values (coalesce(ok, false));
  delete from panel_attempts where at < now() - interval '1 day';
  return coalesce(ok, false);
end $$;

-- ---------- le site envoie une commande ----------
create or replace function submit_site_order(
  p_name text, p_phone text, p_wilaya text, p_commune text, p_address text, p_items jsonb, p_note text,
  p_phone2 text default null, p_ship_type text default null, p_ship_fee integer default 0, p_device text default null, p_renew boolean default false
) returns json language plpgsql security definer set search_path = public as $$
declare it jsonb; c site_catalog; lines jsonb := '[]'::jsonb; sub int := 0; q int; fee int; o site_orders;
begin
  if length(trim(coalesce(p_name,''))) < 2 then raise exception 'name'; end if;
  if coalesce(p_phone,'') !~ '^0[2-7][0-9]{8}$' then raise exception 'phone'; end if;
  if coalesce(p_wilaya,'') = '' then raise exception 'wilaya'; end if;
  if (select count(*) from site_orders where phone = p_phone and created_at > now() - interval '1 hour') >= 3 then raise exception 'too many orders'; end if;
  for it in select * from jsonb_array_elements(coalesce(p_items,'[]'::jsonb)) loop
    select * into c from site_catalog where key = it->>'key' and active;
    if not found then continue; end if;
    q := greatest(1, least(10, coalesce((it->>'qty')::int, 1)));
    lines := lines || jsonb_build_object('key', c.key, 'name', c.name, 'qty', q, 'price', c.price_da);
    sub := sub + c.price_da * q;
  end loop;
  if jsonb_array_length(lines) = 0 then raise exception 'empty'; end if;
  fee := case when p_ship_type in ('home','office') then greatest(0, least(2500, coalesce(p_ship_fee,0))) else 0 end;
  insert into site_orders (name, phone, phone2, wilaya, commune, address, note, items, ship_type, ship_fee, device, renew, total_da)
  values (left(trim(p_name),80), p_phone, nullif(left(p_phone2,16),''), left(p_wilaya,60), left(p_commune,60), left(p_address,200), left(p_note,300),
          lines, case when p_ship_type in ('home','office') then p_ship_type end, fee, case when p_device in ('box','other') then p_device end, coalesce(p_renew,false), sub + fee)
  returning * into o;
  return json_build_object('order_no', o.order_no, 'total_da', o.total_da);
end $$;

-- ---------- statistiques ----------
create or replace function track_site_event(p_visitor text, p_event text, p_item text, p_source text, p_device text, p_lang text)
returns void language sql security definer set search_path = public as $$
  insert into site_events (visitor, event, item, source, device, lang)
  values (left(p_visitor,40), left(p_event,20), left(p_item,40), left(p_source,40), left(p_device,10), left(p_lang,4));
$$;

-- ---------- le panel ----------
create or replace function panel_orders(p_code text) returns json
language plpgsql security definer set search_path = public as $$
begin
  if not panel_check(p_code) then return json_build_object('error', 'bad code'); end if;
  return coalesce((select json_agg(json_build_object(
    'id', id, 'no', order_no, 'date', created_at, 'status', status, 'changed', status_changed_at,
    'name', name, 'phone', phone, 'phone2', phone2, 'wilaya', wilaya, 'commune', commune, 'address', address,
    'note', note, 'items', items, 'ship', ship_type, 'fee', ship_fee, 'device', device, 'renew', renew, 'total', total_da
  ) order by created_at desc) from site_orders where not deleted), '[]'::json);
end $$;

create or replace function panel_set_status(p_code text, p_id uuid, p_status text) returns boolean
language plpgsql security definer set search_path = public as $$
begin
  if not panel_check(p_code) then return false; end if;
  update site_orders set status = p_status, status_changed_at = now() where id = p_id;
  return true;
end $$;

create or replace function panel_delete(p_code text, p_id uuid) returns boolean
language plpgsql security definer set search_path = public as $$
begin
  if not panel_check(p_code) then return false; end if;
  update site_orders set deleted = true where id = p_id;
  return true;
end $$;

grant usage on schema public to anon;
grant select on site_catalog to anon;
revoke all on table site_orders, site_events, panel_settings, panel_attempts from anon, authenticated;
revoke all on function panel_check(text) from public, anon, authenticated;
grant execute on function submit_site_order(text,text,text,text,text,jsonb,text,text,text,integer,text,boolean) to anon;
grant execute on function track_site_event(text,text,text,text,text,text) to anon;
grant execute on function panel_orders(text) to anon;
grant execute on function panel_set_status(text,uuid,text) to anon;
grant execute on function panel_delete(text,uuid) to anon;

-- ---------- TON CODE DU PANEL : remplace 1234 par ton code secret à 4 chiffres ----------
insert into panel_settings (id, code_hash) values (1, extensions.crypt('1234', extensions.gen_salt('bf')))
on conflict (id) do update set code_hash = excluded.code_hash;

-- ---------- v9.30 : changer le code depuis le panel (bouton 🔑) ----------
create or replace function panel_change_code(p_code text, p_new text) returns boolean
language plpgsql security definer set search_path = public, extensions as $$
begin
  if not panel_check(p_code) then return false; end if;
  if coalesce(p_new,'') !~ '^[0-9]{6}$' then return false; end if;
  update panel_settings set code_hash = crypt(p_new, gen_salt('bf')) where id = 1;
  return true;
end $$;
grant execute on function panel_change_code(text,text) to anon;

-- ---------- v9.42 : portail Europe (atomdz.com/eu) ----------
alter table site_orders add column if not exists currency text not null default 'DA';
create table if not exists site_catalog_eu (key text primary key, name text not null, price_eur integer not null check (price_eur >= 0), sort_order int not null default 0, active boolean not null default true);
insert into site_catalog_eu (key, name, price_eur, sort_order) values
  ('eu_s3','ATOM S3',99,1), ('eu_s2','ATOM S2',89,2), ('eu_ax4','ATOM AX4',109,3), ('eu_m10','ATOM M10',119,4)
on conflict (key) do update set price_eur = excluded.price_eur, name = excluded.name;
alter table site_catalog_eu enable row level security;
drop policy if exists "catalogue eu visible" on site_catalog_eu;
create policy "catalogue eu visible" on site_catalog_eu for select to anon using (active);
grant select on site_catalog_eu to anon;

create or replace function submit_eu_order(p_name text, p_phone text, p_country text, p_city text, p_zip text, p_address text, p_items jsonb, p_ship integer default 0, p_email text default null)
returns json language plpgsql security definer set search_path = public as $$
declare it jsonb; c site_catalog_eu; lines jsonb := '[]'::jsonb; sub int := 0; q int; fee int; o site_orders; ph text;
begin
  ph := regexp_replace(coalesce(p_phone,''), '[^0-9+]', '', 'g');
  if length(trim(coalesce(p_name,''))) < 2 then raise exception 'name'; end if;
  if ph !~ '^\+?[0-9]{8,15}$' then raise exception 'phone'; end if;
  if coalesce(p_country,'') = '' or coalesce(p_address,'') = '' then raise exception 'address'; end if;
  if (select count(*) from site_orders where phone = ph and created_at > now() - interval '1 hour') >= 3 then raise exception 'too many orders'; end if;
  for it in select * from jsonb_array_elements(coalesce(p_items,'[]'::jsonb)) loop
    select * into c from site_catalog_eu where key = it->>'key' and active;
    if not found then continue; end if;
    q := greatest(1, least(10, coalesce((it->>'qty')::int, 1)));
    lines := lines || jsonb_build_object('key', c.key, 'name', c.name, 'qty', q, 'price', c.price_eur);
    sub := sub + c.price_eur * q;
  end loop;
  if jsonb_array_length(lines) = 0 then raise exception 'empty'; end if;
  fee := greatest(0, least(50, coalesce(p_ship,0)));
  insert into site_orders (name, phone, wilaya, commune, address, note, items, ship_type, ship_fee, total_da, currency)
  values (left(trim(p_name),80), left(ph,16), '🇪🇺 ' || left(p_country,40), left(p_city,60), left(coalesce(p_zip,'') || ' ' || p_address,200),
          nullif('💳 SumUp' || coalesce(' · ' || left(p_email,80),''),''), lines, 'home', fee, sub + fee, 'EUR')
  returning * into o;
  return json_build_object('order_no', o.order_no, 'total', o.total_da);
end $$;
grant execute on function submit_eu_order(text,text,text,text,text,text,jsonb,integer,text) to anon;
-- (le panel reconnaît les commandes Europe par « 🇪🇺 » dans le pays : panel_orders inchangé)

-- ---------- v9.43 : frais de livraison modifiables depuis le panel ----------
create or replace function panel_set_fee(p_code text, p_id uuid, p_fee integer) returns boolean
language plpgsql security definer set search_path = public as $$
declare f int := greatest(0, least(100000, coalesce(p_fee, 0)));
begin
  if not panel_check(p_code) then return false; end if;
  update site_orders set total_da = total_da - coalesce(ship_fee, 0) + f, ship_fee = f, ship_type = coalesce(ship_type, 'home') where id = p_id;
  return true;
end $$;
grant execute on function panel_set_fee(text,uuid,integer) to anon;

-- v9.45 : Mutant Inferno ProMax (portail Europe)
insert into site_catalog_eu (key, name, price_eur, sort_order) values ('eu_inferno','Mutant Inferno ProMax',99,5)
on conflict (key) do update set price_eur = excluded.price_eur, name = excluded.name;
-- ---------- v9.47 : produits Europe gérés depuis le panel ----------
alter table site_catalog_eu add column if not exists slug text;
alter table site_catalog_eu add column if not exists descr text not null default '';
alter table site_catalog_eu add column if not exists specs jsonb not null default '[]'::jsonb;
alter table site_catalog_eu add column if not exists inbox jsonb not null default '[]'::jsonb;
alter table site_catalog_eu add column if not exists imgs jsonb not null default '[]'::jsonb;

create table if not exists site_img (id uuid primary key default gen_random_uuid(), data text not null, created_at timestamptz not null default now());
alter table site_img enable row level security;
drop policy if exists "photos visibles" on site_img;
create policy "photos visibles" on site_img for select to anon using (true);
grant select on site_img to anon;

create table if not exists site_settings (key text primary key, val jsonb);
alter table site_settings enable row level security;
drop policy if exists "reglages visibles" on site_settings;
create policy "reglages visibles" on site_settings for select to anon using (true);
grant select on site_settings to anon;
insert into site_settings (key, val) values ('eu_ship', 'null'::jsonb) on conflict (key) do nothing;

create or replace function panel_eu_list(p_code text) returns json
language plpgsql security definer set search_path = public as $$
begin
  if not panel_check(p_code) then return 'false'::json; end if;
  return json_build_object('ship', (select val from site_settings where key = 'eu_ship'),
    'items', coalesce((select json_agg(c order by c.sort_order, c.key) from site_catalog_eu c), '[]'::json));
end $$;
grant execute on function panel_eu_list(text) to anon;

create or replace function panel_eu_save(p_code text, p_key text, p_name text, p_price integer, p_descr text, p_specs jsonb, p_inbox jsonb, p_imgs jsonb, p_active boolean, p_sort integer)
returns boolean language plpgsql security definer set search_path = public as $$
begin
  if not panel_check(p_code) then return false; end if;
  if p_key !~ '^eu_[a-z0-9_]{1,40}$' or length(trim(coalesce(p_name,''))) < 2 then raise exception 'bad'; end if;
  insert into site_catalog_eu (key, slug, name, price_eur, descr, specs, inbox, imgs, active, sort_order)
  values (p_key, substr(p_key, 4), left(trim(p_name), 60), greatest(0, least(100000, coalesce(p_price, 0))), left(coalesce(p_descr,''), 160),
          coalesce(p_specs,'[]'), coalesce(p_inbox,'[]'), coalesce(p_imgs,'[]'), coalesce(p_active, true), coalesce(p_sort, 99))
  on conflict (key) do update set name = excluded.name, price_eur = excluded.price_eur, descr = excluded.descr, specs = excluded.specs,
    inbox = excluded.inbox, imgs = excluded.imgs, active = excluded.active, sort_order = excluded.sort_order, slug = coalesce(site_catalog_eu.slug, excluded.slug);
  return true;
end $$;
grant execute on function panel_eu_save(text,text,text,integer,text,jsonb,jsonb,jsonb,boolean,integer) to anon;

create or replace function panel_eu_delete(p_code text, p_key text) returns boolean
language plpgsql security definer set search_path = public as $$
begin
  if not panel_check(p_code) then return false; end if;
  delete from site_catalog_eu where key = p_key; return true;
end $$;
grant execute on function panel_eu_delete(text,text) to anon;

create or replace function panel_img_add(p_code text, p_data text) returns text
language plpgsql security definer set search_path = public as $$
declare i uuid;
begin
  if not panel_check(p_code) then return null; end if;
  if p_data !~ '^data:image/(jpeg|png|webp);base64,' or length(p_data) > 600000 then raise exception 'bad image'; end if;
  insert into site_img (data) values (p_data) returning id into i; return i::text;
end $$;
grant execute on function panel_img_add(text,text) to anon;

create or replace function panel_set_setting(p_code text, p_key text, p_val jsonb) returns boolean
language plpgsql security definer set search_path = public as $$
begin
  if not panel_check(p_code) then return false; end if;
  if p_key not in ('eu_ship') then raise exception 'bad'; end if;
  insert into site_settings (key, val) values (p_key, p_val) on conflict (key) do update set val = excluded.val; return true;
end $$;
grant execute on function panel_set_setting(text,text,jsonb) to anon;

-- livraison Europe : le serveur prend le tarif du panel (le site ne décide plus)
create or replace function submit_eu_order(p_name text, p_phone text, p_country text, p_city text, p_zip text, p_address text, p_items jsonb, p_ship integer default 0, p_email text default null)
returns json language plpgsql security definer set search_path = public as $$
declare it jsonb; c site_catalog_eu; lines jsonb := '[]'::jsonb; sub int := 0; q int; fee int; o site_orders; ph text; sv jsonb;
begin
  ph := regexp_replace(coalesce(p_phone,''), '[^0-9+]', '', 'g');
  if length(trim(coalesce(p_name,''))) < 2 then raise exception 'name'; end if;
  if ph !~ '^\+?[0-9]{8,15}$' then raise exception 'phone'; end if;
  if coalesce(p_country,'') = '' or coalesce(p_address,'') = '' then raise exception 'address'; end if;
  if (select count(*) from site_orders where phone = ph and created_at > now() - interval '1 hour') >= 3 then raise exception 'too many orders'; end if;
  for it in select * from jsonb_array_elements(coalesce(p_items,'[]'::jsonb)) loop
    select * into c from site_catalog_eu where key = it->>'key' and active;
    if not found then continue; end if;
    q := greatest(1, least(10, coalesce((it->>'qty')::int, 1)));
    lines := lines || jsonb_build_object('key', c.key, 'name', c.name, 'qty', q, 'price', c.price_eur);
    sub := sub + c.price_eur * q;
  end loop;
  if jsonb_array_length(lines) = 0 then raise exception 'empty'; end if;
  select val into sv from site_settings where key = 'eu_ship';
  fee := case when jsonb_typeof(sv) = 'number' then greatest(0, (sv)::text::int) else 0 end;
  insert into site_orders (name, phone, wilaya, commune, address, note, items, ship_type, ship_fee, total_da, currency)
  values (left(trim(p_name),80), left(ph,16), '🇪🇺 ' || left(p_country,40), left(p_city,60), left(coalesce(p_zip,'') || ' ' || p_address,200),
          nullif('💳 SumUp' || coalesce(' · ' || left(p_email,80),''),''), lines, 'home', fee, sub + fee, 'EUR')
  returning * into o;
  return json_build_object('order_no', o.order_no, 'total', o.total_da);
end $$;

-- fiches actuelles
update site_catalog_eu set slug='s3', descr='3ᵉ génération · Android 14 · Wi-Fi 6 · BT 6.0',
  specs='["Processeur S905Y4 quatre cœurs (le plus récent)","Android 14","4K Ultra HD · HDR","Wi-Fi 6 · Bluetooth 6.0","HDMI"]',
  imgs='["img/s3.jpg","img/s3-1.jpg","img/s3-2.jpg","img/s3-3.jpg"]' where key='eu_s3';
update site_catalog_eu set slug='s2', descr='4K HDR · Dolby Atmos · Wi-Fi 6',
  specs='["4K HDR","Android 13","Dolby Atmos","Wi-Fi 6"]',
  imgs='["img/s2.jpg","img/s2-1.jpg","img/s2-2.jpg","img/s2-3.jpg","img/s2-4.jpg"]' where key='eu_s2';
update site_catalog_eu set slug='ax4', name='ATOM AX4 Ultra', descr='Amlogic S905X4 · 4 Go DDR4 · 64 Go · Wi-Fi 5G · HDMI 2.1',
  specs='["Processeur Amlogic S905X4","4 Go de RAM DDR4","64 Go de stockage","Wi-Fi 5 GHz","HDMI 2.1"]',
  imgs='["img/ax4-1.jpg","img/ax4-2.jpg","img/ax4-3.jpg","img/ax4-4.jpg"]' where key='eu_ax4';
update site_catalog_eu set slug='m10', descr='8K · Amlogic S905X3 · Wi-Fi 5,8 GHz · LAN Gigabit',
  specs='["Image jusqu''à 8K (7680 × 4320) · 4K à 75 i/s","Processeur Amlogic S905X3 quatre cœurs jusqu''à 1,91 GHz","GPU Mali-G31 MP2 (OpenGL ES 3.2, Vulkan)","2 Go de RAM · 16 Go eMMC","Android 9.0 · Smart TV Online 2","Wi-Fi double bande 5,8 GHz 2×2 AC · Bluetooth 4.2","Ethernet Gigabit 1000 Mbit","Décodage 8K@30 i/s, 4K@75 i/s · H.265, H.264, MPEG, VC-1","105 × 105 × 19,5 mm · noir · LED blanche","Alimentation USB-C 5 V / 2 A · max. 12 W · veille 0,5 W"]',
  inbox='["Boîtier M10 8K","Télécommande rétroéclairée","Alimentation 5 V 2 A","Câble USB-C","Câble HDMI 8K","2 piles AAA","Notice en 6 langues (dont FR)"]',
  imgs='["img/m10-1.jpg","img/m10-2.jpg","img/m10-3.jpg"]' where key='eu_m10';
update site_catalog_eu set slug='inferno', descr='8K · Amlogic S905X3 · 4 Go DDR4 · 32 Go · Wi-Fi 2,4/5 GHz · Bluetooth',
  specs='["Image jusqu''à 8K","Processeur Amlogic S905X3 · GPU Mali-G31","4 Go de RAM DDR4 · 32 Go de stockage","Wi-Fi 2,4 / 5 GHz · Bluetooth · Ethernet","Écran en façade (heure, Wi-Fi, réseau)","Arrière : HDMI, Ethernet, sortie optique, AV · USB 3.0 + USB 2.0 · micro SD","Télécommande Mutant (touches TV, Live, Films, Séries, YouTube)"]',
  imgs='["img/inferno-1.jpg","img/inferno-2.jpg"]' where key='eu_inferno';
insert into site_catalog_eu (key, slug, name, price_eur, sort_order, active, descr, specs, imgs) values
  ('eu_telecommande','telecommande','Télécommande ATOM',0,6,false,'Télécommande Bluetooth de rechange','["Bluetooth","Touches Netflix, YouTube, Prime Video","Compatible ATOM S3 et S2"]','["img/remote-1.jpg","img/remote-2.jpg","img/remote-3.jpg"]')
on conflict (key) do nothing;

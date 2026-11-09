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

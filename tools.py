"""
Herramientas de diagnóstico y mantenimiento WooCommerce <-> Odoo.

Uso:
  python tools.py duplicates              Lista productos duplicados en Woo por nombre
  python tools.py find "texto"            Busca productos en Woo por nombre
  python tools.py odoo-dups               Cruza duplicados Woo vs Odoo (origen del dup)
  python tools.py odoo-product 2293 ...   Muestra productos Odoo por ID
  python tools.py delete 71869 71864      Manda productos Woo a la papelera
  python tools.py cleanup [--apply]       Borra duplicados basura (simula sin --apply)
"""

import os
import sys
import argparse
from collections import defaultdict
from dotenv import load_dotenv
import requests
import urllib3

urllib3.disable_warnings(urllib3.exceptions.InsecureRequestWarning)
load_dotenv()
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

from odoo_client import OdooClient

WOO_URL = os.getenv("WOO_URL", "").rstrip("/")
CK = os.getenv("WOO_CONSUMER_KEY", "")
CS = os.getenv("WOO_CONSUMER_SECRET", "")
VERIFY = os.getenv("WOO_VERIFY_SSL", "True").lower() in ("true", "1", "yes")


def woo_get(path, params=None):
    p = {"consumer_key": CK, "consumer_secret": CS}
    if params:
        p.update(params)
    return requests.get(f"{WOO_URL}/wp-json/wc/v3/{path}", params=p,
                        verify=VERIFY, timeout=60)


def woo_delete(pid):
    return requests.delete(
        f"{WOO_URL}/wp-json/wc/v3/products/{pid}",
        params={"consumer_key": CK, "consumer_secret": CS},
        verify=VERIFY, timeout=30,
    )


def fetch_all_woo():
    products, page = [], 1
    while True:
        res = woo_get("products", {"per_page": 100, "page": page})
        if res.status_code != 200:
            break
        batch = res.json()
        if not batch:
            break
        products.extend(batch)
        if page >= int(res.headers.get("X-WP-TotalPages", 1)):
            break
        page += 1
    return products


def woo_by_name(products):
    d = defaultdict(list)
    for p in products:
        n = (p.get("name") or "").strip().lower()
        if n:
            d[n].append(p)
    return d


def odoo_connect():
    odoo = OdooClient()
    if not odoo.connect():
        print("ERROR: no se pudo conectar a Odoo")
        sys.exit(1)
    return odoo


def fetch_odoo_products(odoo):
    return odoo.get_products(
        domain=[("type", "=", "product")],
        fields=["id", "name", "default_code", "barcode", "active", "sale_ok",
                "qty_available", "categ_id", "create_date"],
        include_archived=True,
    )


def print_woo(p):
    cats = ", ".join(c.get("name", "?") for c in p.get("categories", [])) or "Sin categorizar"
    print(f"   ID={p.get('id')}  SKU={p.get('sku') or '(vacío)'}  "
          f"stock={p.get('stock_quantity')} ({p.get('stock_status')})  "
          f"cats=[{cats}]  date={str(p.get('date_created'))[:10]}")


# ─── Subcomandos ──────────────────────────────────────────────────────────────

def cmd_duplicates(args):
    woo = fetch_all_woo()
    by_name = woo_by_name(woo)
    dups = {n: ps for n, ps in by_name.items() if len(ps) >= 2}
    print(f"Total productos en Woo: {len(woo)}")
    print(f"Nombres duplicados: {len(dups)}\n")
    for name, ps in sorted(dups.items(), key=lambda x: -len(x[1]))[:40]:
        print(f"■ {ps[0].get('name')}")
        for p in ps:
            print_woo(p)
        print()


def cmd_find(args):
    res = woo_get("products", {"search": args.term, "per_page": 50})
    if res.status_code != 200:
        print(f"Error HTTP {res.status_code}: {res.text[:300]}")
        sys.exit(1)
    products = res.json()
    print(f"{len(products)} producto(s) para '{args.term}':\n")
    for p in products:
        print(f"ID={p.get('id')}  SKU={p.get('sku') or '(vacío)'}")
        print(f"   Nombre: {p.get('name')}")
        print(f"   Stock: {p.get('stock_quantity')} ({p.get('stock_status')})  Precio: {p.get('price')}")
        print(f"   Creado: {str(p.get('date_created'))[:19]}  Modificado: {str(p.get('date_modified'))[:19]}")
        print()


def cmd_odoo_dups(args):
    woo = fetch_all_woo()
    woo_by_name_map = woo_by_name(woo)
    woo_dups = {n: ps for n, ps in woo_by_name_map.items() if len(ps) >= 2}
    print(f"Woo: {len(woo)} productos, {len(woo_dups)} nombres duplicados")

    odoo = odoo_connect()
    odoo_products = fetch_odoo_products(odoo)
    odoo_by_name_map = defaultdict(list)
    for p in odoo_products:
        n = (p.get("name") or "").strip().lower()
        if n:
            odoo_by_name_map[n].append(p)
    odoo_dups = {n: ps for n, ps in odoo_by_name_map.items() if len(ps) >= 2}
    print(f"Odoo: {len(odoo_products)} productos, {len(odoo_dups)} nombres duplicados\n")

    en_ambos, parcial, solo_woo = [], [], []
    for name, wps in woo_dups.items():
        ops = odoo_by_name_map.get(name, [])
        if len(ops) >= 2:
            en_ambos.append((name, wps, ops))
        elif len(ops) == 1:
            parcial.append((name, wps, ops))
        else:
            solo_woo.append((name, wps))

    print(f"Duplicados en Odoo Y Woo (limpiar en Odoo): {len(en_ambos)}")
    print(f"Duplicados creados por sync (Woo dup, Odoo único): {len(parcial)}")
    print(f"Duplicados sin producto en Odoo: {len(solo_woo)}\n")

    for title, groups in (("=== EN ODOO Y WOO ===", en_ambos),
                          ("=== CREADOS POR SYNC ===", parcial),
                          ("=== SIN ODOO ===", solo_woo)):
        print(title)
        for name, wps, *rest in groups:
            ops = rest[0] if rest else []
            print(f"■ {wps[0].get('name')}")
            for o in ops:
                print(f"   Odoo id={o['id']} code={o.get('default_code')} barcode={o.get('barcode')}")
            for p in wps:
                print(f"   Woo ID={p.get('id')} SKU={p.get('sku') or '(vacío)'} stock={p.get('stock_quantity')}")
            print()


def cmd_odoo_product(args):
    ids = [int(a) for a in args.ids if a.isdigit()]
    if not ids:
        print("Uso: python tools.py odoo-product <id1> <id2> ...")
        sys.exit(1)
    odoo = odoo_connect()
    products = odoo.execute_kw(
        "product.product", "search_read", [("id", "in", ids)],
        fields=["id", "name", "default_code", "barcode", "active", "sale_ok",
                "qty_available", "categ_id", "create_date"],
    )
    for p in products:
        categ = p.get("categ_id")
        categ_name = categ[1] if isinstance(categ, (list, tuple)) and len(categ) > 1 else "?"
        print(f"ID={p['id']}  activo={p.get('active')}  venta={p.get('sale_ok')}")
        print(f"   Nombre: {p.get('name')}")
        print(f"   default_code={p.get('default_code')}  barcode={p.get('barcode')}")
        print(f"   stock={p.get('qty_available')}  categoría={categ_name}")
        print(f"   creado={str(p.get('create_date'))[:19]}\n")


def cmd_delete(args):
    ids = [a for a in args.ids if a.isdigit()]
    if not ids:
        print("Uso: python tools.py delete <id1> <id2> ...")
        sys.exit(1)
    for pid in ids:
        res = woo_delete(pid)
        if res.status_code == 200:
            d = res.json()
            print(f"OK  ID={pid} '{d.get('name')}' -> {d.get('status')}")
        else:
            print(f"ERR ID={pid} HTTP {res.status_code}: {res.text[:200]}")


def cmd_cleanup(args):
    woo = fetch_all_woo()
    woo_by_name_map = woo_by_name(woo)
    dup_names = {n: ps for n, ps in woo_by_name_map.items() if len(ps) >= 2}
    print(f"Woo: {len(woo)} productos, {len(dup_names)} nombres duplicados")

    odoo = odoo_connect()
    odoo_products = fetch_odoo_products(odoo)

    global_skus = set()
    odoo_by_name_map = defaultdict(list)
    for p in odoo_products:
        n = (p.get("name") or "").strip().lower()
        if n:
            odoo_by_name_map[n].append(p)
        for f in ("default_code", "barcode"):
            v = (p.get(f) or "").strip()
            if v:
                global_skus.add(v)

    def expected_skus(odoo_p):
        sku = (odoo_p.get("default_code") or "").strip() or \
              (odoo_p.get("barcode") or "").strip() or f"ODOO-{odoo_p['id']}"
        return {sku}

    to_delete, skipped = [], []
    for name, wps in dup_names.items():
        ops = odoo_by_name_map.get(name, [])
        expected = set()
        for o in ops:
            expected |= expected_skus(o)
        for wp in wps:
            sku = (wp.get("sku") or "").strip()
            if sku in expected:
                continue
            if sku and sku in global_skus:
                skipped.append((wp, "SKU pertenece a otro producto Odoo"))
                continue
            to_delete.append(wp)

    print(f"\nA borrar: {len(to_delete)} | Omitidos (ambiguos): {len(skipped)}\n")
    for wp in to_delete:
        print(f"  DELETE ID={wp['id']} SKU={wp.get('sku') or '(vacío)'}  {wp.get('name')}")
    for wp, why in skipped:
        print(f"  SKIP   ID={wp['id']} SKU={wp.get('sku')}  {wp.get('name')}  ({why})")

    if not args.apply:
        print("\n[SIMULACIÓN] Usa --apply para borrar de verdad.")
        return

    ok = err = 0
    for wp in to_delete:
        res = woo_delete(wp["id"])
        if res.status_code == 200:
            ok += 1
        else:
            err += 1
            print(f"  ERROR ID={wp['id']}: HTTP {res.status_code} {res.text[:150]}")
    print(f"\nEliminados: {ok} | Errores: {err}")


# ─── Main ─────────────────────────────────────────────────────────────────────

def main():
    parser = argparse.ArgumentParser(description="Herramientas Woo <-> Odoo")
    sub = parser.add_subparsers(dest="cmd", required=True)

    sub.add_parser("duplicates", help="Duplicados en Woo por nombre")

    p_find = sub.add_parser("find", help="Buscar productos en Woo")
    p_find.add_argument("term")

    sub.add_parser("odoo-dups", help="Cruzar duplicados Woo vs Odoo")

    p_odoo = sub.add_parser("odoo-product", help="Ver productos Odoo por ID")
    p_odoo.add_argument("ids", nargs="+")

    p_del = sub.add_parser("delete", help="Borrar productos Woo (papelera)")
    p_del.add_argument("ids", nargs="+")

    p_cl = sub.add_parser("cleanup", help="Borrar duplicados basura")
    p_cl.add_argument("--apply", action="store_true")

    args = parser.parse_args()
    {
        "duplicates": cmd_duplicates,
        "find": cmd_find,
        "odoo-dups": cmd_odoo_dups,
        "odoo-product": cmd_odoo_product,
        "delete": cmd_delete,
        "cleanup": cmd_cleanup,
    }[args.cmd](args)


if __name__ == "__main__":
    main()

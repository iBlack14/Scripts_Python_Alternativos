import argparse
import os
import ssl
import sys
import xmlrpc.client

from dotenv import load_dotenv


def env(name, default=""):
    return os.getenv(name, default).strip()


def make_proxy(url, path, verify_ssl=True):
    context = None if verify_ssl else ssl._create_unverified_context()
    return xmlrpc.client.ServerProxy(f"{url.rstrip('/')}{path}", context=context)


def call(models, db, uid, password, model, method, *args, **kwargs):
    return models.execute_kw(db, uid, password, model, method, list(args), kwargs)


def ok(message):
    print(f"[OK] {message}")


def warn(message):
    print(f"[WARN] {message}")


def fail(message):
    print(f"[FAIL] {message}")


def check_count(models, db, uid, password, model, domain=None, label=None):
    domain = domain or []
    label = label or model
    try:
        count = call(models, db, uid, password, model, "search_count", domain)
        ok(f"{label}: {count}")
        return count
    except Exception as exc:
        fail(f"{label}: {exc}")
        return None


def main():
    load_dotenv()

    parser = argparse.ArgumentParser(description="Diagnostico Odoo XML-RPC")
    parser.add_argument("--url", default=env("ODOO_URL"))
    parser.add_argument("--db", default=env("ODOO_DB"))
    parser.add_argument("--user", default=env("ODOO_USER"))
    parser.add_argument("--password", default=env("ODOO_PASSWORD"))
    parser.add_argument("--no-verify-ssl", action="store_true")
    args = parser.parse_args()

    missing = [name for name, value in {
        "url": args.url,
        "db": args.db,
        "user": args.user,
        "password": args.password,
    }.items() if not value]
    if missing:
        fail("Faltan datos: " + ", ".join(missing))
        print("Ejemplo:")
        print("python diagnose_odoo.py --url https://grupoflores.oz-solutions.com --db grupo_flores --user admin_flores --password TU_API_KEY --no-verify-ssl")
        return 1

    verify_ssl = not args.no_verify_ssl

    print("== Diagnostico Odoo ==")
    print(f"URL: {args.url}")
    print(f"DB: {args.db}")
    print(f"Usuario: {args.user}")
    print(f"SSL verify: {verify_ssl}")

    try:
        common = make_proxy(args.url, "/xmlrpc/2/common", verify_ssl=verify_ssl)
        version = common.version()
        ok(f"Servidor responde: {version.get('server_version')}")
    except ssl.SSLCertVerificationError as exc:
        fail(f"SSL invalido o vencido: {exc}")
        warn("Renovar certificado SSL. Para diagnostico temporal usa --no-verify-ssl.")
        return 2
    except Exception as exc:
        fail(f"No responde XML-RPC common: {exc}")
        return 2

    uid = common.authenticate(args.db, args.user, args.password, {})
    if not uid:
        fail("Autenticacion fallida. Revisa DB, usuario y API key/password.")
        return 3
    ok(f"Autenticacion correcta. UID={uid}")

    models = make_proxy(args.url, "/xmlrpc/2/object", verify_ssl=verify_ssl)

    print("\n== Modulos base ==")
    for module in ["sale_management", "stock", "account", "product", "contacts"]:
        try:
            found = call(
                models, args.db, uid, args.password,
                "ir.module.module", "search_read",
                [("name", "=", module)],
                fields=["name", "state"],
                limit=1,
            )
            if found:
                ok(f"{module}: {found[0].get('state')}")
            else:
                warn(f"{module}: no encontrado")
        except Exception as exc:
            fail(f"{module}: {exc}")

    print("\n== Datos comerciales ==")
    check_count(models, args.db, uid, args.password, "product.template", [], "Plantillas de producto")
    check_count(models, args.db, uid, args.password, "product.product", [], "Variantes de producto")
    check_count(models, args.db, uid, args.password, "product.product", [("sale_ok", "=", True)], "Productos vendibles")
    check_count(models, args.db, uid, args.password, "product.pricelist", [], "Listas de precios")
    check_count(models, args.db, uid, args.password, "res.partner", [("customer_rank", ">", 0)], "Clientes")
    check_count(models, args.db, uid, args.password, "sale.order", [], "Pedidos de venta")
    check_count(models, args.db, uid, args.password, "stock.quant", [], "Registros de inventario")

    print("\n== Calidad para WooCommerce ==")
    no_sku = check_count(
        models, args.db, uid, args.password,
        "product.product",
        [("sale_ok", "=", True), ("default_code", "=", False)],
        "Vendibles sin referencia interna/SKU",
    )
    no_barcode = check_count(
        models, args.db, uid, args.password,
        "product.product",
        [("sale_ok", "=", True), ("barcode", "=", False)],
        "Vendibles sin codigo de barras",
    )
    no_price = check_count(
        models, args.db, uid, args.password,
        "product.product",
        [("sale_ok", "=", True), ("list_price", "<=", 0)],
        "Vendibles sin precio publico",
    )

    print("\n== Muestras ==")
    try:
        products = call(
            models, args.db, uid, args.password,
            "product.product", "search_read",
            [("sale_ok", "=", True)],
            fields=["id", "name", "default_code", "barcode", "list_price", "qty_available", "type"],
            limit=10,
        )
        for product in products:
            print(
                f"- {product['id']} | {product.get('default_code') or 'SIN_SKU'} | "
                f"{product.get('name')} | precio={product.get('list_price')} | "
                f"stock={product.get('qty_available')} | tipo={product.get('type')}"
            )
    except Exception as exc:
        fail(f"No se pudo leer muestra de productos: {exc}")

    print("\n== Recomendaciones automaticas ==")
    if no_sku:
        warn("WooCommerce necesita SKU estable. Completar default_code en productos vendibles.")
    if no_price:
        warn("Hay productos vendibles sin precio. Revisar listas/precio publico antes de sincronizar.")
    if no_barcode:
        warn("Si usaras barcode como match, faltan codigos. Mejor usar default_code.")
    if not verify_ssl:
        warn("El diagnostico uso SSL sin verificar. Renovar certificado antes de produccion.")

    return 0


if __name__ == "__main__":
    sys.exit(main())

import sys
import logging

logging.disable(sys.maxsize)

from odoo_client import OdooClient
from woo_client import WooClient

def main():
    product_name_search = 'BROCA PARA CONCRETO 1/4'
    print(f"🔍 Buscando producto relacionado a: '{product_name_search}'...\n")

    odoo = OdooClient()
    woo = WooClient()
    if not woo.api:
        woo._init_api()

    # Buscar en Odoo
    print("=== RESULTADO EN ODOO ===")
    prods = odoo.execute_kw(
        'product.product', 
        'search_read', 
        [('name', 'ilike', product_name_search)], 
        fields=['id', 'name', 'default_code', 'qty_available', 'free_qty', 'virtual_available']
    )

    found_sku = None
    if prods:
        for p in prods:
            if 'BOUNKER' in p['name'].upper() or '6.5' in p['name']:
                print(f"📦 Producto Odoo: {p['name']}")
                print(f"🏷️ SKU: {p.get('default_code')}")
                print(f"📊 Stock 'qty_available' (A Mano global): {p.get('qty_available')}")
                print(f"📊 Stock 'free_qty' (A Mano libre): {p.get('free_qty')}")
                print(f"📊 Stock 'virtual_available' (Pronosticado): {p.get('virtual_available')}")
                found_sku = p.get('default_code')
                break
    else:
        print("❌ No se encontró en Odoo.")

    print("\n=== RESULTADO EN WOOCOMMERCE ===")
    if found_sku:
        try:
            res = woo.api.get("products", params={"sku": found_sku})
            if res.status_code == 200:
                w_prods = res.json()
                if w_prods:
                    w_prod = w_prods[0]
                    print(f"📦 Producto Web: {w_prod.get('name')}")
                    print(f"🏷️ SKU: {w_prod.get('sku')}")
                    print(f"📊 Stock Woo: {w_prod.get('stock_quantity')} unidades")
                else:
                    print("❌ El producto no existe en WooCommerce.")
            else:
                print(f"❌ Error al consultar Woo: {res.status_code}")
        except Exception as e:
            print(f"❌ Error: {e}")
    else:
        print("❌ Al no encontrar el SKU en Odoo, no se puede buscar en Woo.")

    print("\n=== DESGLOSE DE ALMACENES EN ODOO ===")
    if found_sku:
        quants = odoo.execute_kw(
            'stock.quant',
            'search_read',
            [('product_id.default_code', '=', found_sku), ('location_id.usage', '=', 'internal')],
            fields=['location_id', 'quantity', 'reserved_quantity', 'company_id']
        )
        if quants:
            for q in quants:
                loc = q.get('location_id')[1] if q.get('location_id') else 'Desconocido'
                comp = q.get('company_id')[1] if q.get('company_id') else 'Desconocido'
                print(f" - Ubicación: {loc} | Empresa: {comp} | Cantidad: {q.get('quantity')} | Reservado: {q.get('reserved_quantity')}")
        else:
            print("No se encontraron registros detallados de inventario.")

if __name__ == '__main__':
    main()

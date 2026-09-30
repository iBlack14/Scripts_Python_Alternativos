import sys
import logging

# Silenciar logs para que sea más limpio
logging.disable(sys.maxsize)

from odoo_client import OdooClient
from woo_client import WooClient

def main():
    print("=" * 50)
    print("COMPARATIVA DE CATÁLOGO: ODOO vs WOOCOMMERCE")
    print("=" * 50)

    print("\nConectando a WooCommerce...")
    woo = WooClient()
    woo_prods = woo.get_all_products()
    print(f"✓ WooCommerce: {len(woo_prods)} productos encontrados.")

    print("\nConectando a Odoo...")
    odoo = OdooClient()
    odoo_prods = odoo.get_products()
    print(f"✓ Odoo: {len(odoo_prods)} productos encontrados.")

    # Analisis de SKUs
    woo_skus = set(woo_prods.keys())
    odoo_skus = {str(p.get('default_code')).strip() for p in odoo_prods if p.get('default_code')}

    comunes = odoo_skus.intersection(woo_skus)
    faltan_en_woo = odoo_skus - woo_skus
    sobran_en_woo = woo_skus - odoo_skus
    
    print("\n--- ANÁLISIS COMPLETO DE SINCRONIZACIÓN ---")
    print(f"✅ Productos sincronizados (Mismo SKU): {len(comunes)}")
    print(f"⬆️ Productos en Odoo que faltan crear en Woo: {len(faltan_en_woo)}")
    print(f"❌ Productos que SOBRAN en Woo (No existen en Odoo Grupo Flores): {len(sobran_en_woo)}")
    
    if sobran_en_woo:
        print("\n--- MUESTRA DE PRODUCTOS QUE SOBRAN EN WOOCOMMERCE ---")
        print("Estos productos están en tu página web pero no pertenecen al catálogo actual de Odoo:")
        count = 0
    if faltan_en_woo:
        print("\n--- EJEMPLO DE PRODUCTOS QUE FALTAN EN WOO (Total: {}) ---".format(len(faltan_en_woo)))
        count = 0
        for p in odoo_prods:
            sku = str(p.get('default_code')).strip()
            if sku in faltan_en_woo:
                print(f" - SKU: {sku} | Nombre Odoo: {p.get('name')[:50]}")
                count += 1
                if count >= 10:
                    break

    if comunes:
        print("\n--- EJEMPLO DE PRODUCTOS QUE SÍ ESTÁN SINCRONIZADOS (Total: {}) ---".format(len(comunes)))
        count = 0
        for p in odoo_prods:
            sku = str(p.get('default_code')).strip()
            if sku in comunes:
                print(f" - SKU: {sku} | Nombre Odoo: {p.get('name')[:50]}")
                count += 1
                if count >= 10:
                    break

if __name__ == '__main__':
    main()

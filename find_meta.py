import sys
import logging
from woo_client import WooClient

logging.disable(sys.maxsize)

def main():
    print("🔎 Analizando campos ocultos de un producto en WooCommerce...")
    woo = WooClient()
    
    # Buscar el primer producto que exista
    res = woo.api.get("products", params={"per_page": 2})
    if res.status_code == 200:
        prods = res.json()
        if prods:
            for p in prods:
                print(f"\n📦 Producto: {p.get('name')}")
                print(f"Precio normal: {p.get('regular_price')}")
                
                print("--- METADATA (Campos creados por tu plugin) ---")
                meta_data = p.get('meta_data', [])
                found_custom = False
                for meta in meta_data:
                    key = meta.get('key', '')
                    # Filtrar campos internos muy comunes de WordPress para no ensuciar la salida
                    if not key.startswith('_wp_') and not key.startswith('_wc_') and key not in ['total_sales', 'onsale']:
                        print(f" 🔑 {key} = {meta.get('value')}")
                        found_custom = True
                
                if not found_custom:
                    print(" (No se encontraron campos personalizados extra en este producto)")
        else:
            print("❌ No hay productos en la tienda.")
    else:
        print(f"❌ Error al consultar WooCommerce: {res.status_code}")

if __name__ == '__main__':
    main()

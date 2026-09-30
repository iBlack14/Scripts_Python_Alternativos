import sys
import logging
import json
from woo_client import WooClient

logging.disable(sys.maxsize)

def main():
    print("🔎 Consultando la configuración de la página de Checkout en WooCommerce...")
    woo = WooClient()
    
    res = woo.api.get("settings/advanced")
    if res.status_code == 200:
        settings = res.json()
        for s in settings:
            if s.get('id') == 'woocommerce_checkout_page_id':
                page_id = s.get('value')
                print(f"\n🚨 ¡ENCONTRADO! El ID de la página configurada como 'Checkout' es: {page_id}")
                print("Si este ID pertenece a la imagen de la galería en lugar de tu página real, ese es el error.")
                return
        print("No se encontró la configuración 'woocommerce_checkout_page_id'.")
    else:
        print(f"Error: {res.status_code} - {res.text}")

if __name__ == '__main__':
    main()

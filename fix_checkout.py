import sys
import requests
import json
import urllib3
from woo_client import WooClient

urllib3.disable_warnings(urllib3.exceptions.InsecureRequestWarning)
logging = sys.modules['logging']
logging.disable(sys.maxsize)

def main():
    print("🔎 Buscando todas las páginas disponibles en tu WordPress...")
    
    try:
        # Usar la API pública de WordPress para ver las páginas
        res = requests.get('https://grupofloressa.com/wp-json/wp/v2/pages?per_page=100', verify=False, timeout=15)
        if res.status_code == 200:
            pages = res.json()
            found_id = None
            
            print("\nPáginas candidatas encontradas:")
            for p in pages:
                title = p.get('title', {}).get('rendered', '').lower()
                # Buscar páginas que suenen a Checkout
                if 'checkout' in title or 'finalizar' in title or 'pago' in title:
                    print(f" - ID: {p['id']} | Título: {title}")
                    found_id = p['id']
            
            if found_id:
                print(f"\nVoy a forzar a WooCommerce a usar la página ID {found_id} como Checkout oficial.")
                woo = WooClient()
                # Actualizar el setting de WooCommerce vía API
                update_res = woo.api.post(
                    "settings/advanced/woocommerce_checkout_page_id",
                    data={"value": str(found_id)}
                )
                if update_res.status_code == 200:
                    print("✅ ¡Configuración actualizada exitosamente por la fuerza!")
                else:
                    print(f"❌ Falló al actualizar WooCommerce: {update_res.status_code} - {update_res.text}")
            else:
                print("\n❌ No encontré ninguna página que se llame 'Checkout' o 'Finalizar'. Tienes que crearla primero.")
                
        else:
            print(f"Error consultando páginas WP: {res.status_code}")
            
    except Exception as e:
        print(f"Excepción: {e}")

if __name__ == '__main__':
    main()

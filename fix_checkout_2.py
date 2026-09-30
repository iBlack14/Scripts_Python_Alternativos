import sys
import requests
import json
import urllib3
from woo_client import WooClient

urllib3.disable_warnings(urllib3.exceptions.InsecureRequestWarning)
logging = sys.modules['logging']
logging.disable(sys.maxsize)

def main():
    print("Forzando WooCommerce a usar la PÁGINA CORRECTA (ID: 11324 'Finalizar compra seguro')...")
    
    try:
        woo = WooClient()
        # Actualizar el setting de WooCommerce vía API al ID 11324
        update_res = woo.api.post(
            "settings/advanced/woocommerce_checkout_page_id",
            data={"value": "11324"}
        )
        if update_res.status_code == 200:
            print("✅ ¡Configuración actualizada exitosamente por la fuerza a la página ID 11324!")
        else:
            print(f"❌ Falló al actualizar WooCommerce: {update_res.status_code} - {update_res.text}")
            
    except Exception as e:
        print(f"Excepción: {e}")

if __name__ == '__main__':
    main()

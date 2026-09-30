import sys
import logging
from woo_client import WooClient

logging.disable(sys.maxsize)

def main():
    print("🔎 Escaneando configuraciones de WooCommerce remotamente...")
    woo = WooClient()
    
    # Revisar pasarelas de pago activas
    print("\n--- PASARELAS DE PAGO ACTIVAS ---")
    res = woo.api.get("payment_gateways")
    found_error = False
    
    if res.status_code == 200:
        gateways = res.json()
        for g in gateways:
            if g.get('enabled'):
                print(f"✅ Revisando pasarela: {g.get('title')} ({g.get('id')})")
                settings = g.get('settings', {})
                for key, setting_data in settings.items():
                    val = str(setting_data.get('value', ''))
                    if 'Support-3.png' in val or 'wp-content/uploads' in val:
                        print(f"  🚨 ¡ENCONTRADO! El enlace erróneo está en la configuración '{key}' de la pasarela '{g.get('title')}'.")
                        print(f"      Valor exacto: {val}")
                        found_error = True
    else:
        print(f"❌ Error al consultar pasarelas: {res.status_code}")

    if not found_error:
        print("\nNo encontré el enlace malicioso en las pasarelas de pago estándar.")
        print("Esto significa que probablemente el problema está en un plugin de terceros (como un plugin de 'Gracias por tu compra' o un formulario de Elementor) que la API no puede ver.")

if __name__ == '__main__':
    main()

"""
Script de Diagnóstico y Prueba de Conexión
Verifica credenciales y conectividad con Odoo 15 y WooCommerce.
"""

import sys
from colorama import init, Fore, Style
from config import validate_config, ODOO_URL, ODOO_DB, ODOO_USER, WOO_URL
from odoo_client import OdooClient
from woo_client import WooClient

init(autoreset=True)


def print_banner():
    print(Fore.CYAN + "=" * 60)
    print(Fore.CYAN + "   HERRAMIENTA DE DIAGNÓSTICO: ODOO 15 <-> WOOCOMMERCE")
    print(Fore.CYAN + "=" * 60 + Style.RESET_ALL)


def test_odoo():
    print(f"\n{Fore.YELLOW}[1/2] Probando conexión con Odoo 15 ({ODOO_URL} - DB: {ODOO_DB})...{Style.RESET_ALL}")
    odoo = OdooClient()
    result = odoo.test_connection()
    
    if result.get("success"):
        print(f"{Fore.GREEN}✔ Conexión a ODOO EXITOSA!{Style.RESET_ALL}")
        print(f"  - Versión Servidor: {result.get('server_version')}")
        print(f"  - ID de Usuario autenticado (UID): {result.get('uid')}")
        
        # Muestra rápida de productos
        products = odoo.get_products(limit=3)
        print(f"  - Lectura de prueba: {len(products)} productos leídos correctamente.")
        for p in products:
            sku = p.get('default_code') or '(Sin SKU)'
            print(f"    * [{sku}] {p.get('name')} - Stock: {p.get('qty_available', 0)} - Precio: ${p.get('list_price', 0)}")
        return True
    else:
        print(f"{Fore.RED}✘ Error al conectar con Odoo:{Style.RESET_ALL} {result.get('error', 'Credenciales o servidor inaccesible')}")
        return False


def test_woocommerce():
    print(f"\n{Fore.YELLOW}[2/2] Probando conexión con WooCommerce ({WOO_URL})...{Style.RESET_ALL}")
    woo = WooClient()
    result = woo.test_connection()
    
    if result.get("success"):
        print(f"{Fore.GREEN}✔ Conexión a WOOCOMMERCE EXITOSA!{Style.RESET_ALL}")
        print(f"  - Versión WooCommerce: {result.get('wc_version')}")
        if "wp_version" in result:
            print(f"  - Versión WordPress: {result.get('wp_version')}")
        return True
    else:
        print(f"{Fore.RED}✘ Error al conectar con WooCommerce:{Style.RESET_ALL} {result.get('error')}")
        return False


def main():
    print_banner()
    
    is_valid, errors = validate_config()
    if not is_valid:
        print(f"{Fore.RED}Configuración incompleta en el archivo .env:{Style.RESET_ALL}")
        for err in errors:
            print(f" - {err}")
        print(f"\n{Fore.YELLOW}Por favor edita el archivo .env con tus credenciales reales.{Style.RESET_ALL}")
        sys.exit(1)

    odoo_ok = test_odoo()
    woo_ok = test_woocommerce()

    print("\n" + "=" * 60)
    if odoo_ok and woo_ok:
        print(f"{Fore.GREEN}✔ TODAS LAS CONEXIONES FUNCIONAN CORRECTAMENTE. ¡Listo para sincronizar!{Style.RESET_ALL}")
    else:
        print(f"{Fore.RED}✘ Hay problemas de conexión. Revisa los mensajes anteriores.{Style.RESET_ALL}")
    print("=" * 60)


if __name__ == "__main__":
    main()

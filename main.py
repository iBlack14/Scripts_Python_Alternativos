"""
Punto de Entrada Principal del Conector Odoo 15 <-> WooCommerce
CLI para sincronización manual o demonio programado.
"""

import argparse
import sys
import time
import schedule
from colorama import init, Fore, Style
from config import validate_config, SYNC_INTERVAL_MINUTES, logger
from sync_engine import SyncEngine
import test_connection

init(autoreset=True)


def banner():
    print(Fore.CYAN + r"""
  ___  ____   ___   ___    _ _  _____ 
 / _ \|  _ \ / _ \ / _ \  / / || ____|
| | | | | | | | | | | | |/ /| ||  _|  
| |_| | |_| | |_| | |_| / / | || |___ 
 \___/|____/ \___/ \___/_/  |_||_____|
 CONECTOR ODOO 15 <---> WOOCOMMERCE
    """ + Style.RESET_ALL)


def run_daemon(engine, dry_run=False):
    """Ejecuta el conector en modo servicio/demónio periódico."""
    print(f"\n{Fore.GREEN}[MODO DEMONIO ACTIVADO]{Style.RESET_ALL}")
    print(f"Sincronizando stock, pedidos y cancelaciones cada {SYNC_INTERVAL_MINUTES} minutos.")
    print(f"{Fore.YELLOW}Catálogo desactivado (ya importado). Usa --sync-products para forzarlo.{Style.RESET_ALL}")
    print("Presiona Ctrl + C para detener el servicio.\n")

    def fast_job():
        logger.info("--- Ejecutando tarea periódica (Stock, Pedidos, Cancelaciones) ---")
        try:
            engine.sync_stock_only(dry_run=dry_run)
            engine.sync_orders_to_odoo(dry_run=dry_run)
            engine.sync_cancellations_to_woo(dry_run=dry_run)
        except Exception as e:
            logger.error(f"Error en tarea periódica: {e}")

    # Ejecutar inmediatamente al arrancar
    fast_job()

    # Programar intervalo
    schedule.every(SYNC_INTERVAL_MINUTES).minutes.do(fast_job)

    try:
        while True:
            schedule.run_pending()
            time.sleep(1)
    except KeyboardInterrupt:
        print(f"\n{Fore.YELLOW}Servicio detenido por el usuario.{Style.RESET_ALL}")


def main():
    banner()

    parser = argparse.ArgumentParser(
        description="Conector y Sincronizador de Datos entre Odoo 15 y WooCommerce (WordPress)."
    )
    parser.add_argument("--test",          action="store_true", help="Probar conexiones a Odoo y WooCommerce")
    parser.add_argument("--sync-all",      action="store_true", help="Sincronizar Stock + Pedidos (sin catálogo)")
    parser.add_argument("--sync-products", action="store_true", help="[Avanzado] Sincronizar catálogo y precios Odoo → WooCommerce")
    parser.add_argument("--sync-images",   action="store_true", help="Sincronizar solo imágenes de productos Odoo → WooCommerce")
    parser.add_argument("--sync-stock",    action="store_true", help="Sincronizar existencias/stock rápidamente")
    parser.add_argument("--sync-orders",   action="store_true", help="Importar pedidos de WooCommerce a Odoo")
    parser.add_argument("--daemon",        action="store_true", help="Ejecutar en bucle continuo en segundo plano")
    parser.add_argument("--dry-run",       action="store_true", help="Simular operaciones sin escribir cambios")

    args = parser.parse_args()

    # Si no se pasó ningún argumento, mostrar ayuda
    if len(sys.argv) == 1:
        parser.print_help()
        sys.exit(0)

    # Validar credenciales antes de cualquier operación
    is_valid, errors = validate_config()
    if not is_valid and not args.test:
        print(f"{Fore.RED}Error en configuración (.env):{Style.RESET_ALL}")
        for err in errors:
            print(f" - {err}")
        print(f"\n{Fore.YELLOW}Configura tu archivo .env antes de continuar.{Style.RESET_ALL}")
        sys.exit(1)

    if args.test:
        test_connection.main()
        return

    engine = SyncEngine()

    if args.sync_products:
        engine.sync_products(dry_run=args.dry_run)
    elif args.sync_images:
        engine.sync_images_only(dry_run=args.dry_run)
    elif args.sync_stock:
        engine.sync_stock_only(dry_run=args.dry_run)
    elif args.sync_orders:
        engine.sync_orders_to_odoo(dry_run=args.dry_run)
    elif args.sync_all:
        engine.sync_all(dry_run=args.dry_run)
    elif args.daemon:
        run_daemon(engine, dry_run=args.dry_run)


if __name__ == "__main__":
    main()

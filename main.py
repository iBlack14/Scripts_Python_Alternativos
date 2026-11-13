"""
Punto de Entrada Principal del Conector Odoo 15 <-> WooCommerce
CLI para sincronización manual o demonio programado.
"""

import argparse
import sys
import time
import schedule
from colorama import init, Fore, Style
from config import (
    validate_config,
    SYNC_INTERVAL_MINUTES,
    SYNC_INTERVAL_SECONDS,
    IMAGE_SYNC_DAILY_TIME,
    IMAGE_SYNC_TIMEZONE,
    IMAGE_SYNC_MAX_MISSING,
    logger,
)
from sync_engine import SyncEngine
import test_connection

init(autoreset=True)


def banner():
    print(Fore.CYAN + "═" * 50)
    print("   CONECTOR ODOO 15 <---> WOOCOMMERCE")
    print("═" * 50 + Style.RESET_ALL)


def run_daemon(engine, dry_run=False):
    """Ejecuta el conector en modo bucle continuo."""
    print(f"{Fore.GREEN}[MODO BUCLE ACTIVADO]{Style.RESET_ALL} (Intervalo: {Fore.CYAN}{SYNC_INTERVAL_SECONDS}s{Style.RESET_ALL})")
    print(f"Sincronización de imágenes: al inicio y cada 4 horas (límite {IMAGE_SYNC_MAX_MISSING}/ciclo)")
    print("Presiona Ctrl + C para detener.\n")

    # Mientras haya backlog de imágenes se revisa en cada ciclo;
    # cuando se acabe, el schedule de 4 horas toma el relevo.
    image_backlog_active = {"value": True}

    def ciclo():
        logger.info(f"─── Ciclo de sincronización ({SYNC_INTERVAL_SECONDS}s) ───")
        try:
            engine.sync_all(dry_run=dry_run)
        except Exception as e:
            logger.error(f"Error en ciclo: {e}")
        # Imágenes: solo las que Woo confirma que faltan (ligero, sin catálogo)
        if engine.use_plugin and image_backlog_active["value"]:
            try:
                res = engine.sync_images_only(dry_run=dry_run)
                if res.get("uploaded", 0) < IMAGE_SYNC_MAX_MISSING:
                    image_backlog_active["value"] = False
                    logger.info("Imágenes al día. Próxima revisión automática en 4 horas.")
            except Exception as e:
                logger.error(f"Error en sincronización de imágenes: {e}")

    def ciclo_imagenes():
        logger.info("─── Sincronización de imágenes (revisión cada 4h) ───")
        try:
            res = engine.sync_images_only(dry_run=dry_run)
            # Si la revisión de 4h encuentra backlog nuevo, volver a
            # revisarla en cada ciclo hasta vaciarlo.
            if res.get("uploaded", 0) >= IMAGE_SYNC_MAX_MISSING:
                image_backlog_active["value"] = True
        except Exception as e:
            logger.error(f"Error en sincronización de imágenes: {e}")

    # Ejecutar inmediatamente al arrancar (incluye imágenes si hay backlog)
    ciclo()

    # Repetir cada N segundos
    schedule.every(SYNC_INTERVAL_SECONDS).seconds.do(ciclo)
    schedule.every(4).hours.do(ciclo_imagenes)

    try:
        while True:
            schedule.run_pending()
            time.sleep(1)
    except KeyboardInterrupt:
        print(f"\n{Fore.YELLOW}Bucle detenido por el usuario.{Style.RESET_ALL}")


def main():
    banner()

    parser = argparse.ArgumentParser(
        description="Conector y Sincronizador de Datos entre Odoo 15 y WooCommerce (WordPress)."
    )
    parser.add_argument("--test",          action="store_true", help="Probar conexiones a Odoo y WooCommerce")
    parser.add_argument("--sync-all",      action="store_true", help="Sincronizar stock + pedidos + cancelaciones (sin catálogo pesado)")
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

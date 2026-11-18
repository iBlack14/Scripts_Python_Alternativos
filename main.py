"""
Punto de Entrada Principal del Conector Odoo 15 <-> WooCommerce
CLI para sincronización manual o demonio programado.
"""

import argparse
import os
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
    IMAGE_SYNC_IMAGES_INTERVAL_MINUTES,
    logger,
)
from sync_engine import SyncEngine
from state import load_state, save_state
import test_connection

init(autoreset=True)


def banner():
    print(Fore.CYAN + "═" * 50)
    print("   CONECTOR ODOO 15 <---> WOOCOMMERCE")
    print("═" * 50 + Style.RESET_ALL)


def run_daemon(engine, dry_run=False):
    """Ejecuta el conector en modo bucle continuo."""
    print(f"{Fore.GREEN}[MODO BUCLE ACTIVADO]{Style.RESET_ALL} (Intervalo: {Fore.CYAN}{SYNC_INTERVAL_SECONDS}s{Style.RESET_ALL})")
    print(f"Sincronización de imágenes: al inicio, cada ciclo hasta terminar, luego cada {IMAGE_SYNC_IMAGES_INTERVAL_MINUTES} min(es) (límite {IMAGE_SYNC_MAX_MISSING}/ciclo)")
    print("Presiona Ctrl + C para detener.\n")

    # Sync inicial de catálogo: corre UNA sola vez (state.json).
    # Crea productos faltantes, precios y categorías. Si el archivo
    # existe, se salta directo al ciclo ligero.
    state = load_state()
    initial_sync_done = bool(state.get("initial_sync_at"))

    if not initial_sync_done and not dry_run:
        logger.info("═══ PRIMER INICIO: sincronización inicial de catálogo ═══")
        try:
            engine.sync_products(dry_run=dry_run)
            state = load_state()  # recargar por si sync_products escribió algo
            state["initial_sync_at"] = time.strftime("%Y-%m-%d %H:%M:%S")
            save_state(state)
            logger.info("Sync inicial completado. state.json actualizado.")
        except Exception as e:
            logger.error(f"Error en sync inicial (se reintentará en el próximo arranque): {e}")
    elif initial_sync_done:
        logger.debug("Sync inicial ya realizado (state.json existe).")

    # Mientras haya backlog de imágenes se revisa en cada ciclo;
    # cuando se acabe, el schedule diario toma el relevo.
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
                    logger.info(f"Imágenes al día. Próxima revisión automática diaria a las {IMAGE_SYNC_DAILY_TIME}.")
            except Exception as e:
                logger.error(f"Error en sincronización de imágenes: {e}")

    def ciclo_imagenes():
        # Intervalo de imágenes: prioridad al valor configurado en el plugin WordPress.
        plugin_interval = engine.get_plugin_image_interval()
        interval_minutes = plugin_interval if plugin_interval is not None else IMAGE_SYNC_IMAGES_INTERVAL_MINUTES
        logger.info(f"─── Sincronización de imágenes (revisión cada {interval_minutes} min(es)) ───")
        try:
            state = load_state()
            last_image_sync = state.get("last_image_sync_at")  # timestamp ISO
            now = time.time()

            should_run = True
            if last_image_sync:
                try:
                    last_ts = time.mktime(time.strptime(str(last_image_sync), "%Y-%m-%d %H:%M:%S"))
                    minutes_diff = (now - last_ts) / 60.0
                    if minutes_diff < interval_minutes:
                        should_run = False
                        logger.info(f"Imágenes: próxima revisión automática en {int(interval_minutes - minutes_diff)} min(es).")
                except Exception:
                    pass

            if should_run:
                res = engine.sync_images_only(dry_run=dry_run)
                # Si la revisión encuentra backlog nuevo, volver a revisarla en cada ciclo hasta vaciarlo.
                if res.get("uploaded", 0) >= IMAGE_SYNC_MAX_MISSING:
                    image_backlog_active["value"] = True
                state["last_image_sync_at"] = time.strftime("%Y-%m-%d %H:%M:%S")
                save_state(state)
        except Exception as e:
            logger.error(f"Error en sincronización de imágenes: {e}")

    # Ejecutar inmediatamente al arrancar (incluye imágenes si hay backlog)
    ciclo()

    # Repetir cada N segundos
    schedule.every(SYNC_INTERVAL_SECONDS).seconds.do(ciclo)
    # Diario a la hora configurada (hora local del servidor, sin pytz)
    schedule.every().day.at(IMAGE_SYNC_DAILY_TIME).do(ciclo_imagenes)

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

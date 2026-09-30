"""
Script para eliminar TODOS los productos de WooCommerce y re-sincronizar desde Odoo desde cero.
ADVERTENCIA: Este script es destructivo. Borra todo el catálogo de WooCommerce.
"""
import sys
import logging
from woo_client import WooClient
from sync_engine import SyncEngine

logging.basicConfig(level=logging.INFO, format='%(asctime)s [%(levelname)s] %(message)s')
logger = logging.getLogger(__name__)

def delete_all_woo_products():
    print("\n🗑️  FASE 1: ELIMINANDO TODOS LOS PRODUCTOS DE WOOCOMMERCE...\n")
    woo = WooClient()
    
    page = 1
    deleted = 0
    errors = 0
    
    while True:
        res = woo.api.get("products", params={"per_page": 100, "page": page, "status": "any"})
        if res.status_code != 200:
            logger.error(f"Error obteniendo productos: {res.status_code}")
            break
        
        products = res.json()
        if not products:
            break
        
        ids = [p["id"] for p in products]
        logger.info(f"Página {page}: encontrados {len(ids)} productos. Eliminando...")
        
        # Eliminar en lote con force=true (sin papelera)
        del_res = woo.api.post("products/batch", data={
            "delete": ids
        })
        
        if del_res.status_code == 200:
            data = del_res.json()
            n = len(data.get("delete", []))
            deleted += n
            logger.info(f"  ✅ Eliminados: {n}")
        else:
            errors += 1
            logger.error(f"  ❌ Error al borrar lote: {del_res.status_code} - {del_res.text[:200]}")
        
        page += 1
    
    print(f"\n✅ FASE 1 COMPLETA: {deleted} productos eliminados de WooCommerce. Errores: {errors}\n")
    return deleted

def sync_from_scratch():
    print("\n🚀 FASE 2: SINCRONIZANDO PRODUCTOS DESDE ODOO...\n")
    engine = SyncEngine()
    result = engine.sync_products(dry_run=False)
    print(f"\n✅ FASE 2 COMPLETA: Creados={result.get('created', 0)}, Actualizados={result.get('updated', 0)}, Errores={len(result.get('errors', []))}")
    return result

if __name__ == "__main__":
    print("=" * 60)
    print("  RESET TOTAL DE CATÁLOGO WOOCOMMERCE + SYNC DESDE ODOO")
    print("=" * 60)
    
    confirm = input("\n⚠️  ¿Estás seguro? Esto borrará TODOS los productos de WooCommerce.\nEscribe 'SI' para continuar: ")
    
    if confirm.strip().upper() != "SI":
        print("❌ Operación cancelada.")
        sys.exit(0)
    
    deleted = delete_all_woo_products()
    result = sync_from_scratch()
    
    print("\n" + "=" * 60)
    print("  RESET TOTAL COMPLETADO 🏁")
    print(f"  Productos eliminados : {deleted}")
    print(f"  Productos creados    : {result.get('created', 0)}")
    print(f"  Productos actualizados: {result.get('updated', 0)}")
    print("=" * 60)

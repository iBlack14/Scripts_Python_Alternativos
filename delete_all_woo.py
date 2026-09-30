import sys
import logging
from woo_client import WooClient

logging.basicConfig(level=logging.INFO, format='%(asctime)s [%(levelname)s] %(message)s')
logger = logging.getLogger(__name__)

def delete_all_woo_products():
    print("\n🗑️  ELIMINANDO ABSOLUTAMENTE TODOS LOS PRODUCTOS DE WOOCOMMERCE...\n")
    woo = WooClient()
    
    deleted = 0
    errors = 0
    
    # Bucle infinito hasta que ya no haya productos
    while True:
        # Buscamos productos en cualquier estado (publicados, borradores, papelera)
        res = woo.api.get("products", params={"per_page": 100, "page": 1, "status": "any"})
        if res.status_code != 200:
            logger.error(f"Error obteniendo productos: {res.status_code}")
            break
        
        products = res.json()
        if not products:
            logger.info("Ya no quedan productos en WooCommerce.")
            break
        
        ids = [p["id"] for p in products]
        logger.info(f"Encontrados {len(ids)} productos. Eliminando definitivamente...")
        
        # Eliminar en lote con force=true para que no vayan a la papelera, sino que se borren del todo
        # En la API de Woo, pasar los ids en 'delete' (sin 'trash') los borra permanentemente si forzamos.
        # En realidad, batch delete los mueve a la papelera por defecto. Para forzar, hay que hacer delete individual o batch delete puede requerir un parámetro extra en WP.
        # Mejor hacemos un loop rápido para forzar.
        for pid in ids:
            del_res = woo.api.delete(f"products/{pid}", params={"force": True})
            if del_res.status_code in [200, 201]:
                deleted += 1
                if deleted % 50 == 0:
                    logger.info(f"  ✅ Eliminados hasta ahora: {deleted}")
            else:
                errors += 1
                logger.error(f"  ❌ Error al borrar ID {pid}: {del_res.status_code}")
                
    print(f"\n✅ PROCESO COMPLETADO: {deleted} productos eliminados permanentemente. Errores: {errors}\n")

if __name__ == "__main__":
    delete_all_woo_products()

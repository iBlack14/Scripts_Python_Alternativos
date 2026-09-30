import sys
import logging
from sync_engine import SyncEngine

logging.basicConfig(level=logging.INFO, format='%(asctime)s - %(levelname)s - %(message)s')
logger = logging.getLogger(__name__)

def main():
    print("\n🚀 INICIANDO TEST DE SINCRONIZACIÓN DE PEDIDOS (WOO -> ODOO) 🚀\n")
    try:
        engine = SyncEngine()
        result = engine.sync_orders_to_odoo(dry_run=False)
        print("\n✅ RESULTADO:")
        print(f"Pedidos encontrados en WooCommerce (Procesando): {result['total']}")
        print(f"Pedidos importados exitosamente a Odoo: {result['imported']}")
        
    except Exception as e:
        print(f"\n❌ Error durante la sincronización: {e}")

if __name__ == '__main__':
    main()

"""
Cliente REST API para WooCommerce (WordPress)
Maneja operaciones de catálogo, stock por lotes y pedidos.
"""

from woocommerce import API
import requests
from config import WOO_URL, WOO_CONSUMER_KEY, WOO_CONSUMER_SECRET, WOO_VERSION, WOO_VERIFY_SSL, logger


class WooClient:
    def __init__(self):
        self.url = WOO_URL
        self.consumer_key = WOO_CONSUMER_KEY
        self.consumer_secret = WOO_CONSUMER_SECRET
        self.version = WOO_VERSION
        self.verify_ssl = WOO_VERIFY_SSL
        self.api = None
        self._init_api()

    def _init_api(self):
        """Inicializa el objeto API de WooCommerce."""
        if self.url and self.consumer_key and self.consumer_secret:
            self.api = API(
                url=self.url,
                consumer_key=self.consumer_key,
                consumer_secret=self.consumer_secret,
                version=self.version,
                verify_ssl=self.verify_ssl,
                timeout=30
            )

    def test_connection(self):
        """Prueba la conexión a la API de WooCommerce consultando el estado del sistema o productos."""
        if not self.api:
            self._init_api()
        if not self.api:
            return {"success": False, "error": "Credenciales de WooCommerce no configuradas."}
        
        try:
            res = self.api.get("system_status")
            if res.status_code == 200:
                data = res.json()
                environment = data.get("environment", {})
                return {
                    "success": True,
                    "wc_version": environment.get("version", "OK"),
                    "wp_version": environment.get("wp_version", "OK"),
                    "store_url": environment.get("site_url", self.url)
                }
            elif res.status_code in (401, 403):
                return {"success": False, "error": f"Error de autenticación WooCommerce ({res.status_code}): Claves inválidas o permisos insuficientes."}
            else:
                # Fallback: intentar listar 1 producto
                res_prod = self.api.get("products", params={"per_page": 1})
                if res_prod.status_code == 200:
                    return {"success": True, "wc_version": "Conectado"}
                return {"success": False, "error": f"Código HTTP {res.status_code}: {res.text[:200]}"}
        except Exception as e:
            return {"success": False, "error": str(e)}

    def get_all_products(self):
        """
        Obtiene todos los productos de WooCommerce mediante paginación.
        Retorna un diccionario mapeado por SKU: {sku: product_dict}.
        """
        if not self.api:
            self._init_api()

        products_by_sku = {}
        page = 1
        per_page = 100

        logger.info("Obteniendo catálogo existente desde WooCommerce...")
        while True:
            try:
                res = self.api.get("products", params={"per_page": per_page, "page": page})
                if res.status_code != 200:
                    logger.error(f"Error al obtener productos de Woo (página {page}): {res.text}")
                    break

                items = res.json()
                if not items:
                    break

                for item in items:
                    sku = (item.get("sku") or "").strip()
                    if sku:
                        products_by_sku[sku] = item

                total_pages = int(res.headers.get("X-WP-TotalPages", 1))
                if page >= total_pages:
                    break
                page += 1
            except Exception as e:
                logger.error(f"Excepción al paginar productos de Woo: {e}")
                break

        logger.info(f"Se encontraron {len(products_by_sku)} productos con SKU en WooCommerce.")
        return products_by_sku

    def batch_update_products(self, create_items=None, update_items=None):
        """
        Ejecuta creación o actualización en bloque (hasta 100 por petición).
        """
        if not self.api:
            self._init_api()

        create_items = create_items or []
        update_items = update_items or []

        results = {"created": 0, "updated": 0, "errors": []}

        # Procesar en lotes de 100
        batch_size = 100
        total_creates = len(create_items)
        total_updates = len(update_items)

        # 1. Procesar Creates
        for i in range(0, total_creates, batch_size):
            chunk = create_items[i:i + batch_size]
            payload = {"create": chunk}
            try:
                logger.info(f"Enviando lote de creación ({i+1} a {min(i+batch_size, total_creates)} de {total_creates})...")
                res = self.api.post("products/batch", payload)
                if res.status_code in (200, 201):
                    data = res.json()
                    results["created"] += len(data.get("create", []))
                else:
                    results["errors"].append(f"Batch Create Error: {res.text[:200]}")
            except Exception as e:
                results["errors"].append(f"Exception batch create: {str(e)}")

        # 2. Procesar Updates
        for i in range(0, total_updates, batch_size):
            chunk = update_items[i:i + batch_size]
            payload = {"update": chunk}
            try:
                logger.info(f"Enviando lote de actualización ({i+1} a {min(i+batch_size, total_updates)} de {total_updates})...")
                res = self.api.post("products/batch", payload)
                if res.status_code in (200, 201):
                    data = res.json()
                    results["updated"] += len(data.get("update", []))
                else:
                    results["errors"].append(f"Batch Update Error: {res.text[:200]}")
            except Exception as e:
                results["errors"].append(f"Exception batch update: {str(e)}")

        return results

    def batch_update_stock(self, stock_list):
        """
        Actualiza el stock en bloque en WooCommerce.
        stock_list = [{'id': woo_id, 'manage_stock': True, 'stock_quantity': 10}, ...]
        """
        return self.batch_update_products(update_items=stock_list)

    def get_orders(self, status="processing", per_page=50):
        """
        Obtiene órdenes recientes desde WooCommerce.
        """
        if not self.api:
            self._init_api()

        try:
            params = {"status": status, "per_page": per_page}
            res = self.api.get("orders", params=params)
            if res.status_code == 200:
                return res.json()
            else:
                logger.error(f"Error obteniendo pedidos de WooCommerce: {res.text}")
                return []
        except Exception as e:
            logger.error(f"Excepción obteniendo pedidos de WooCommerce: {e}")
            return []

    def update_order_status(self, order_id, new_status):
        """
        Actualiza el estado de un pedido en WooCommerce (ej. 'completed').
        """
        if not self.api:
            self._init_api()

        try:
            res = self.api.put(f"orders/{order_id}", {"status": new_status})
            return res.status_code == 200
        except Exception as e:
            logger.error(f"Error actualizando orden #{order_id}: {e}")
            return False

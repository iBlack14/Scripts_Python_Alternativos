"""
Motor de Sincronización Odoo 15 <-> WooCommerce
Versión 2.0: Soporta envío directo al Plugin WordPress (REST API propia)
además de la API nativa de WooCommerce.
"""

import requests
from odoo_client import OdooClient
from woo_client import WooClient
from config import ODOO_MATCH_FIELD, SYNC_ONLY_SALE_OK, SYNC_IMAGES, logger
import os


class SyncEngine:
    def __init__(self):
        self.odoo = OdooClient()
        self.woo = WooClient()
        self.match_field = ODOO_MATCH_FIELD

        # Configuración opcional para enviar al Plugin WordPress directamente
        self.woo_url = os.getenv("WOO_URL", "").rstrip("/")
        self.plugin_token = os.getenv("WOO_API_TOKEN", "")
        self.use_plugin_api = bool(self.plugin_token)

    # ─── Cabeceras para el Plugin WordPress ─────────────────────────────────
    def _plugin_headers(self):
        return {
            "X-Odoo-Token": self.plugin_token,
            "Content-Type": "application/json"
        }

    def _plugin_url(self, endpoint):
        return f"{self.woo_url}/wp-json/odoo-sync/v1/{endpoint}"

    # ─── Verificar estado del Plugin en WordPress ────────────────────────────
    def check_plugin_status(self):
        if not self.use_plugin_api:
            logger.warning("WOO_API_TOKEN no configurado. No se puede verificar el plugin de WordPress.")
            return None
        try:
            r = requests.get(self._plugin_url("status"), headers=self._plugin_headers(), timeout=10, verify=False)
            if r.status_code == 200:
                data = r.json()
                logger.info(f"Plugin WordPress conectado: {data.get('plugin')} v{data.get('version')} | WC {data.get('woocommerce_version')}")
                logger.info(f"  Última sync productos : {data.get('last_sync_products')}")
                logger.info(f"  Última sync stock     : {data.get('last_sync_stock')}")
                logger.info(f"  Última sync pedidos   : {data.get('last_sync_orders')}")
                return data
            else:
                logger.error(f"Plugin WordPress respondió con código {r.status_code}: {r.text[:200]}")
                return None
        except Exception as e:
            logger.error(f"Error conectando al plugin WordPress: {e}")
            return None

    # ─── Sincronización de Productos (Odoo → Plugin WordPress → WooCommerce) ─
    def sync_products(self, dry_run=False):
        logger.info("=== Sincronizando Catálogo de Productos Odoo → WooCommerce ===")

        domain = [('sale_ok', '=', True)] if SYNC_ONLY_SALE_OK else []
        domain.extend(['|', ('default_code', '!=', False), ('barcode', '!=', False)])
        domain.append(('type', '=', 'product'))

        odoo_products = self.odoo.get_products(domain=domain)
        logger.info(f"Odoo: {len(odoo_products)} productos encontrados.")

        if not odoo_products:
            return {"status": "empty", "sent": 0}

        # Construir payload para el plugin
        products_payload = []
        for p in odoo_products:
            alternate_field = 'barcode' if self.match_field == 'default_code' else 'default_code'
            sku = str(p.get(self.match_field) or p.get(alternate_field) or "").strip()
            if not sku:
                continue

            qty = p.get('free_qty') or p.get('qty_available', 0.0)
            
            # Use specific pricelist prices if available, fallback to list_price
            base_price = p.get('public_price') or p.get('list_price', 0.0)
            dist_price = p.get('ferretero_price') or p.get('list_price', 0.0)

            item = {
                "sku":          sku,
                "name":         p.get('name', ''),
                "price":        str(base_price),
                "distributor_price": str(dist_price),
                "description":  p.get('description_sale') or '',
                "weight":       str(p.get('weight', '')) if p.get('weight') else '',
                "manage_stock": True,
                "stock":        int(max(0, qty)),
                "category":     p.get('categ_id', [None, ''])[1] if p.get('categ_id') else '',
            }
            products_payload.append(item)

        logger.info(f"Preparados {len(products_payload)} productos para enviar.")

        if dry_run:
            logger.info("[DRY-RUN] No se enviaron datos al plugin WordPress.")
            return {"status": "dry-run", "prepared": len(products_payload)}

        # Enviar al Plugin WordPress (en lotes de 100)
        if self.use_plugin_api:
            return self._send_products_to_plugin(products_payload)
        else:
            # Fallback: usar API WooCommerce directamente
            return self._sync_products_via_woo_api(products_payload)

    def _send_products_to_plugin(self, products):
        batch_size = 100
        total_created = 0
        total_updated = 0
        total_errors = []

        for i in range(0, len(products), batch_size):
            chunk = products[i:i + batch_size]
            try:
                r = requests.post(
                    self._plugin_url("push-products"),
                    json={"products": chunk},
                    headers=self._plugin_headers(),
                    timeout=60,
                    verify=False
                )
                if r.status_code == 200:
                    data = r.json()
                    total_created += data.get("created", 0)
                    total_updated += data.get("updated", 0)
                    total_errors.extend(data.get("errors", []))
                    logger.info(f"Lote {i//batch_size + 1}: Creados={data.get('created')}, Actualizados={data.get('updated')}")
                else:
                    logger.error(f"Error enviando lote {i//batch_size + 1}: HTTP {r.status_code} — {r.text[:200]}")
            except Exception as e:
                logger.error(f"Excepción enviando lote {i//batch_size + 1}: {e}")

        logger.info(f"Sync productos finalizada: {total_created} creados, {total_updated} actualizados.")
        return {"created": total_created, "updated": total_updated, "errors": total_errors}

    def _sync_products_via_woo_api(self, products_payload):
        """Fallback: usar la API WooCommerce directamente si no hay token del plugin."""
        woo_products_map = self.woo.get_all_products()
        create_list = []
        update_list = []

        for p in products_payload:
            sku = p["sku"]
            payload = {
                "name": p["name"], "type": "simple",
                "regular_price": p["price"], "description": p["description"],
                "sku": sku, "manage_stock": p["manage_stock"],
                "meta_data": [
                    {
                        "key": "_owc_distributor_price",
                        "value": p.get("distributor_price", "")
                    }
                ]
            }
            if p["stock"] is not None:
                payload["stock_quantity"] = p["stock"]
            if sku in woo_products_map:
                payload["id"] = woo_products_map[sku]["id"]
                update_list.append(payload)
            else:
                create_list.append(payload)

        results = self.woo.batch_update_products(create_items=create_list, update_items=update_list)
        logger.info(f"Sync productos (API WooCommerce): {results['created']} creados, {results['updated']} actualizados.")
        return results

    # ─── Sincronización Rápida de Stock ─────────────────────────────────────
    def sync_stock_only(self, dry_run=False):
        logger.info("=== Sincronizando Stock/Inventario Odoo → WooCommerce ===")

        stock_map = self.odoo.get_stock_quantities(match_field=self.match_field)
        logger.info(f"Odoo: Existencias consultadas para {len(stock_map)} SKUs.")

        if dry_run:
            logger.info(f"[DRY-RUN] Se actualizarían {len(stock_map)} existencias en WooCommerce.")
            return {"status": "dry-run", "pending": len(stock_map)}

        if self.use_plugin_api:
            return self._send_stock_to_plugin(stock_map)
        else:
            return self._sync_stock_via_woo_api(stock_map)

    def _send_stock_to_plugin(self, stock_map):
        stock_list = [{"sku": sku, "qty": info["qty"]} for sku, info in stock_map.items()]

        batch_size = 200
        total_updated = 0
        total_not_found = 0

        for i in range(0, len(stock_list), batch_size):
            chunk = stock_list[i:i + batch_size]
            try:
                r = requests.post(
                    self._plugin_url("push-stock"),
                    json={"stock": chunk},
                    headers=self._plugin_headers(),
                    timeout=60,
                    verify=False
                )
                if r.status_code == 200:
                    data = r.json()
                    total_updated   += data.get("updated", 0)
                    total_not_found += data.get("not_found", 0)
                    logger.info(f"Lote stock {i//batch_size + 1}: Actualizados={data.get('updated')}, No encontrados={data.get('not_found')}")
                else:
                    logger.error(f"Error enviando stock lote {i//batch_size + 1}: HTTP {r.status_code}")
            except Exception as e:
                logger.error(f"Excepción enviando stock: {e}")

        logger.info(f"Sync stock finalizada: {total_updated} actualizados, {total_not_found} no encontrados en WooCommerce.")
        return {"updated": total_updated, "not_found": total_not_found}

    def _sync_stock_via_woo_api(self, stock_map):
        woo_products_map = self.woo.get_all_products()
        stock_updates = []
        for sku, info in stock_map.items():
            if sku in woo_products_map:
                woo_prod = woo_products_map[sku]
                if woo_prod.get("stock_quantity") != info["qty"]:
                    stock_updates.append({"id": woo_prod["id"], "manage_stock": True, "stock_quantity": info["qty"]})

        if not stock_updates:
            logger.info("El inventario en WooCommerce ya está al día.")
            return {"status": "up-to-date", "updated": 0}

        results = self.woo.batch_update_stock(stock_updates)
        logger.info(f"Stock actualizado (API WooCommerce): {results['updated']} productos.")
        return results

    # ─── Importar Pedidos de WooCommerce → Odoo ──────────────────────────────
    def sync_orders_to_odoo(self, dry_run=False):
        logger.info("=== Importando Pedidos de WooCommerce → Odoo ===")

        orders = self.woo.get_orders(status="processing")
        logger.info(f"WooCommerce: {len(orders)} pedidos en estado 'processing'.")

        imported = 0
        for order in orders:
            order_id = order.get("id")
            if dry_run:
                logger.info(f"[DRY-RUN] Pedido #{order_id} sería creado en Odoo.")
                imported += 1
                continue

            sale_id = self.odoo.create_sale_order(order, match_field=self.match_field)
            if sale_id:
                imported += 1

        logger.info(f"Pedidos procesados: {imported}/{len(orders)}.")
        return {"imported": imported, "total": len(orders)}

    # ─── Sincronización Total ────────────────────────────────────────────────
    def sync_all(self, dry_run=False):
        res_prod   = self.sync_products(dry_run=dry_run)
        res_stock  = self.sync_stock_only(dry_run=dry_run)
        res_orders = self.sync_orders_to_odoo(dry_run=dry_run)
        return {"products": res_prod, "stock": res_stock, "orders": res_orders}

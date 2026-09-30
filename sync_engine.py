"""
Motor de Sincronización Odoo 15 <-> WooCommerce  ·  v3.0
─────────────────────────────────────────────────────────
Bucle principal: Productos (crear + precio) + Stock + Imágenes
Sin categorías, sin catálogo pesado.
"""

import requests
from odoo_client import OdooClient
from woo_client import WooClient
from config import ODOO_MATCH_FIELD, SYNC_ONLY_SALE_OK, logger
import os


class SyncEngine:
    def __init__(self):
        self.odoo = OdooClient()
        self.woo  = WooClient()
        self.match_field = ODOO_MATCH_FIELD

        # Plugin WordPress (opcional)
        self.woo_url      = os.getenv("WOO_URL", "").rstrip("/")
        self.plugin_token = os.getenv("WOO_API_TOKEN", "")
        self.use_plugin   = bool(self.plugin_token)

    # ── Helpers plugin ────────────────────────────────────────────────────────
    def _ph(self):
        return {"X-Odoo-Token": self.plugin_token, "Content-Type": "application/json"}

    def _pu(self, endpoint):
        return f"{self.woo_url}/wp-json/odoo-sync/v1/{endpoint}"

    # ── Estado del plugin ─────────────────────────────────────────────────────
    def check_plugin_status(self):
        if not self.use_plugin:
            logger.warning("WOO_API_TOKEN no configurado.")
            return None
        try:
            r = requests.get(self._pu("status"), headers=self._ph(), timeout=10, verify=False)
            if r.status_code == 200:
                d = r.json()
                logger.info(f"Plugin: {d.get('plugin')} v{d.get('version')} | WC {d.get('woocommerce_version')}")
                return d
            logger.error(f"Plugin respondió {r.status_code}: {r.text[:200]}")
        except Exception as e:
            logger.error(f"Error conectando plugin: {e}")
        return None

    # ══════════════════════════════════════════════════════════════════════════
    #  BUCLE PRINCIPAL: Crear productos + Precio + Stock + Imágenes
    # ══════════════════════════════════════════════════════════════════════════
    def sync_full_loop(self, dry_run=False):
        """
        Un solo pase desde Odoo que hace TODO:
          1. Crea productos nuevos en WooCommerce (si no existen por SKU)
          2. Actualiza nombre y precio
          3. Actualiza stock
          4. Sube/actualiza la imagen principal
        Sin categorías. Sin catálogo pesado.
        """
        logger.info("════ SYNC COMPLETO: Productos · Stock · Precio · Imágenes ════")

        domain = [("type", "=", "product")]
        if SYNC_ONLY_SALE_OK:
            domain.append(("sale_ok", "=", True))

        odoo_products = self.odoo.get_products(domain=domain)
        logger.info(f"Odoo: {len(odoo_products)} productos encontrados.")

        if not odoo_products:
            return {"status": "empty"}

        payload = []
        for p in odoo_products:
            alt = "barcode" if self.match_field == "default_code" else "default_code"
            sku = str(p.get(self.match_field) or p.get(alt) or "").strip()
            if not sku:
                sku = f"ODOO-{p['id']}"

            qty        = p.get("free_qty") or p.get("qty_available", 0.0)
            price_pub  = p.get("public_price")  or p.get("list_price", 0.0)
            price_dist = p.get("ferretero_price") or p.get("list_price", 0.0)
            
            image_b64  = p.get("image_1920") or p.get("image_128") or ""

            payload.append({
                "odoo_id":           p["id"],
                "sku":               sku,
                "name":              p.get("name", ""),
                "price":             str(price_pub),
                "distributor_price": str(price_dist),
                "description":       p.get("description_sale") or "",
                "weight":            str(p.get("weight", "")) if p.get("weight") else "",
                "manage_stock":      True,
                "stock":             int(max(0, qty)),
                "image_b64":         image_b64,
            })

        logger.info(f"Preparados {len(payload)} productos.")

        if dry_run:
            logger.info("[DRY-RUN] Sin cambios reales.")
            return {"status": "dry-run", "prepared": len(payload)}

        if self.use_plugin:
            return self._push_to_plugin(payload)
        else:
            return self._push_via_woo_api(payload)

    # ── Envío al Plugin WordPress ─────────────────────────────────────────────
    def _push_to_plugin(self, payload, batch_size=50):
        """Lotes de 50 porque las imágenes base64 son pesadas."""
        total_created = 0
        total_updated = 0
        total_errors  = []

        for i in range(0, len(payload), batch_size):
            chunk = payload[i : i + batch_size]
            lote  = i // batch_size + 1
            try:
                r = requests.post(
                    self._pu("push-products"),
                    json={"products": chunk},
                    headers=self._ph(),
                    timeout=120,
                    verify=False,
                )
                if r.status_code == 200:
                    d = r.json()
                    total_created += d.get("created", 0)
                    total_updated += d.get("updated", 0)
                    total_errors.extend(d.get("errors", []))
                    logger.info(
                        f"Lote {lote}: Creados={d.get('created',0)}  "
                        f"Actualizados={d.get('updated',0)}  "
                        f"Omitidos={d.get('skipped',0)}"
                    )
                else:
                    logger.error(f"Lote {lote} HTTP {r.status_code}: {r.text[:200]}")
            except Exception as e:
                logger.error(f"Lote {lote} excepción: {e}")

        logger.info(f"════ FIN SYNC: {total_created} creados, {total_updated} actualizados ════")
        return {"created": total_created, "updated": total_updated, "errors": total_errors}

    # ── Fallback: API WooCommerce directa ─────────────────────────────────────
    def _push_via_woo_api(self, payload):
        """Usa la REST API de WooCommerce directa.
        Para las imágenes, usa las credenciales WP_USER y WP_APP_PASSWORD
        para subirlas a la Biblioteca de Medios.
        """
        woo_map    = self.woo.get_all_products()
        create_lst = []
        update_lst = []
        imgs_set   = 0
        imgs_skip  = 0
        
        for p in payload:
            sku = p["sku"]
            item = {
                "name":           p["name"],
                "type":           "simple",
                "regular_price":  p["price"],
                "description":    p["description"],
                "sku":            sku,
                "manage_stock":   p["manage_stock"],
                "stock_quantity": p["stock"],
                "weight":         p.get("weight", ""),
                "meta_data": [
                    {"key": "_owc_distributor_price", "value": p.get("distributor_price", "")}
                ],
            }

            # ── Lógica de Imágenes (Subir a WP primero) ──────────────
            image_b64 = p.get("image_b64")
            if image_b64:
                if sku in woo_map:
                    # Si ya existe en Woo, verificar si ya tiene imagen
                    woo_prod = woo_map[sku]
                    woo_images = woo_prod.get("images", [])
                    has_woo_img = False
                    
                    if woo_images:
                        src = woo_images[0].get("src", "")
                        if "woocommerce-placeholder" not in src:
                            has_woo_img = True
                    
                    if not has_woo_img:
                        url = self.woo.upload_media_from_base64(image_b64, sku)
                        if url:
                            item["images"] = [{"src": url, "position": 0}]
                            imgs_set += 1
                        else:
                            imgs_skip += 1
                    else:
                        imgs_skip += 1
                else:
                    # Producto nuevo, subir imagen
                    url = self.woo.upload_media_from_base64(image_b64, sku)
                    if url:
                        item["images"] = [{"src": url, "position": 0}]
                        imgs_set += 1
                    else:
                        imgs_skip += 1
            else:
                imgs_skip += 1

            if sku in woo_map:
                item["id"] = woo_map[sku]["id"]
                update_lst.append(item)
            else:
                create_lst.append(item)

        logger.info(
            f"Imágenes → Subidas a WP y vinculadas a Woo: {imgs_set} | "
            f"Omitidas (ya tenían o sin imagen): {imgs_skip}"
        )
        
        results = self.woo.batch_update_products(create_items=create_lst, update_items=update_lst)
        logger.info(
            f"════ FIN SYNC (API Woo): {results['created']} creados, "
            f"{results['updated']} actualizados ════"
        )
        return results


    # ══════════════════════════════════════════════════════════════════════════
    #  STOCK RÁPIDO  (solo inventario)
    # ══════════════════════════════════════════════════════════════════════════
    def sync_stock_only(self, dry_run=False):
        logger.info("═══ Sincronizando Stock Odoo → WooCommerce ═══")
        stock_map = self.odoo.get_stock_quantities(match_field=self.match_field)
        logger.info(f"Odoo: {len(stock_map)} SKUs con stock.")

        if dry_run:
            return {"status": "dry-run", "pending": len(stock_map)}

        if self.use_plugin:
            return self._stock_to_plugin(stock_map)
        return self._stock_via_woo_api(stock_map)

    def _stock_to_plugin(self, stock_map):
        stock_list = [{"sku": s, "qty": i["qty"]} for s, i in stock_map.items()]
        batch_size = 200
        total_upd  = 0
        total_nf   = 0

        for i in range(0, len(stock_list), batch_size):
            chunk = stock_list[i : i + batch_size]
            try:
                r = requests.post(
                    self._pu("push-stock"),
                    json={"stock": chunk},
                    headers=self._ph(),
                    timeout=60,
                    verify=False,
                )
                if r.status_code == 200:
                    d = r.json()
                    total_upd += d.get("updated", 0)
                    total_nf  += d.get("not_found", 0)
                    logger.info(f"Stock lote {i//batch_size+1}: Actualizados={d.get('updated')}  No-encontrados={d.get('not_found')}")
                else:
                    logger.error(f"Stock lote {i//batch_size+1} HTTP {r.status_code}")
            except Exception as e:
                logger.error(f"Stock excepción: {e}")

        logger.info(f"Stock finalizado: {total_upd} actualizados, {total_nf} no encontrados.")
        return {"updated": total_upd, "not_found": total_nf}

    def _stock_via_woo_api(self, stock_map):
        woo_map = self.woo.get_all_products()
        updates = [
            {"id": woo_map[s]["id"], "manage_stock": True, "stock_quantity": i["qty"]}
            for s, i in stock_map.items()
            if s in woo_map and woo_map[s].get("stock_quantity") != i["qty"]
        ]
        if not updates:
            logger.info("Stock ya al día en WooCommerce.")
            return {"status": "up-to-date", "updated": 0}
        res = self.woo.batch_update_stock(updates)
        logger.info(f"Stock actualizado (API Woo): {res['updated']} productos.")
        return res

    # ══════════════════════════════════════════════════════════════════════════
    #  PEDIDOS WooCommerce → Odoo
    # ══════════════════════════════════════════════════════════════════════════
    def sync_orders_to_odoo(self, dry_run=False):
        logger.info("═══ Importando Pedidos WooCommerce → Odoo ═══")
        orders   = self.woo.get_orders(status="processing")
        logger.info(f"WooCommerce: {len(orders)} pedidos en 'processing'.")

        imported = 0
        for order in orders:
            oid = order.get("id")
            if dry_run:
                logger.info(f"[DRY-RUN] Pedido #{oid} sería creado en Odoo.")
                imported += 1
                continue
            if self.odoo.create_sale_order(order, match_field=self.match_field):
                imported += 1

        logger.info(f"Pedidos: {imported}/{len(orders)} importados.")
        return {"imported": imported, "total": len(orders)}

    # ══════════════════════════════════════════════════════════════════════════
    #  CANCELACIONES Odoo → WooCommerce
    # ══════════════════════════════════════════════════════════════════════════
    def sync_cancellations_to_woo(self, dry_run=False):
        logger.info("═══ Sincronizando Cancelaciones Odoo → WooCommerce ═══")
        cancelled = self.odoo.execute_kw(
            "sale.order", "search_read",
            [("state", "=", "cancel"), ("client_order_ref", "like", "WOO-")],
            fields=["id", "name", "client_order_ref", "state"],
        )
        if not cancelled:
            logger.info("Sin pedidos cancelados con referencia WooCommerce.")
            return {"cancelled": 0}

        logger.info(f"Odoo: {len(cancelled)} cancelados con ref WooCommerce.")
        count = 0
        for order in cancelled:
            ref    = order.get("client_order_ref", "")
            woo_id = ref.replace("WOO-", "").strip()
            if not ref.startswith("WOO-"):
                continue
            if dry_run:
                logger.info(f"[DRY-RUN] Woo #{woo_id} ({order['name']}) sería cancelado.")
                count += 1
                continue
            try:
                res = self.woo.api.get(f"orders/{woo_id}")
                if res.status_code != 200:
                    logger.warning(f"Orden Woo #{woo_id} no encontrada ({res.status_code}).")
                    continue
                status = res.json().get("status", "")
                if status in ("cancelled", "refunded", "completed"):
                    logger.info(f"Woo #{woo_id} ya en '{status}'. Omitido.")
                    continue
                upd = self.woo.api.put(f"orders/{woo_id}", data={"status": "cancelled"})
                if upd.status_code == 200:
                    logger.info(f"Woo #{woo_id} ({order['name']}) cancelado.")
                    count += 1
                else:
                    logger.error(f"Error cancelando Woo #{woo_id}: {upd.status_code}")
            except Exception as e:
                logger.error(f"Excepción cancelando Woo #{woo_id}: {e}")

        logger.info(f"Cancelaciones: {count}/{len(cancelled)}.")
        return {"cancelled": count}

    # ══════════════════════════════════════════════════════════════════════════
    #  SYNC_ALL: Full loop + Pedidos + Cancelaciones
    # ══════════════════════════════════════════════════════════════════════════
    def sync_all(self, dry_run=False):
        """
        Ciclo completo para el demonio:
          1. sync_full_loop  → Crear/actualizar productos + precio + stock + imágenes
          2. sync_orders     → Pedidos WooCommerce → Odoo
          3. sync_cancel     → Cancelaciones Odoo → WooCommerce
        """
        res_full   = self.sync_full_loop(dry_run=dry_run)
        res_orders = self.sync_orders_to_odoo(dry_run=dry_run)
        res_cancel = self.sync_cancellations_to_woo(dry_run=dry_run)
        return {"full_sync": res_full, "orders": res_orders, "cancellations": res_cancel}

    # ── Alias CLI (compatibilidad) ────────────────────────────────────────────
    def sync_products(self, dry_run=False):
        """Alias → sync_full_loop."""
        return self.sync_full_loop(dry_run=dry_run)

    def sync_images_only(self, dry_run=False):
        """Alias → sync_full_loop (imágenes incluidas en cada ciclo)."""
        return self.sync_full_loop(dry_run=dry_run)

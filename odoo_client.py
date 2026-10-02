"""
Cliente XML-RPC para Odoo 15
Maneja la autenticación y las operaciones sobre los modelos de Odoo.
"""

import xmlrpc.client
import ssl

try:
    ssl._create_default_https_context = ssl._create_unverified_context
except AttributeError:
    pass

from config import ODOO_URL, ODOO_DB, ODOO_USER, ODOO_PASSWORD, logger


class OdooClient:
    def __init__(self):
        self.url = ODOO_URL
        self.db = ODOO_DB
        self.username = ODOO_USER
        self.password = ODOO_PASSWORD
        self.uid = None
        self.common = None
        self.models = None

    def connect(self):
        """Autentica contra el servidor Odoo y obtiene el UID."""
        try:
            self.common = xmlrpc.client.ServerProxy(f"{self.url}/xmlrpc/2/common")
            self.uid = self.common.authenticate(self.db, self.username, self.password, {})
            if not self.uid:
                logger.error("Error de autenticación en Odoo: Credenciales incorrectas o BD inválida.")
                return False
            
            self.models = xmlrpc.client.ServerProxy(f"{self.url}/xmlrpc/2/object")
            logger.info(f"Conexión exitosa a Odoo 15. UID: {self.uid}")
            return True
        except Exception as e:
            logger.error(f"Error al conectar con Odoo ({self.url}): {e}")
            return False

    def execute_kw(self, model, method, *args, **kwargs):
        """Ejecuta una llamada execute_kw en Odoo con manejo de sesión."""
        if not self.uid:
            if not self.connect():
                raise ConnectionError("No se pudo conectar a Odoo.")
        
        # Inyectar el contexto de Grupo Flores de forma automática
        if 'context' not in kwargs:
            kwargs['context'] = {}
        kwargs['context']['allowed_company_ids'] = [2]
            
        return self.models.execute_kw(self.db, self.uid, self.password, model, method, list(args), kwargs)

    def test_connection(self):
        """Prueba la conexión y devuelve la versión de Odoo y el estado."""
        try:
            if not self.common:
                self.common = xmlrpc.client.ServerProxy(f"{self.url}/xmlrpc/2/common")
            version_info = self.common.version()
            is_connected = self.connect()
            return {
                "success": is_connected,
                "server_version": version_info.get("server_version", "Desconocida"),
                "uid": self.uid
            }
        except Exception as e:
            return {"success": False, "error": str(e)}

    def get_products(self, domain=None, fields=None, limit=0, offset=0, include_archived=False):
        """
        Obtiene productos desde Odoo (product.product).
        """
        if domain is None:
            # Traer productos activos e inactivos (archivados)
            domain = [('sale_ok', '=', True), '|', ('active', '=', True), ('active', '=', False)]
        
        if fields is None:
            fields = [
                'id',
                'name',
                'default_code',
                'barcode',
                'list_price',
                'standard_price',
                'qty_available',
                'virtual_available',
                'free_qty',
                'type',
                'description_sale',
                'categ_id',
                'weight',
                'active',
                'product_tmpl_id',
                'image_1920',   # Imagen principal en alta resolución (base64)
                'image_128',    # Miniatura de respaldo
            ]
        
        try:
            context = {'active_test': False} if include_archived else {}
            products = self.execute_kw(
                'product.product',
                'search_read',
                domain,
                fields=fields,
                offset=offset,
                limit=limit,
                context=context,
            )

            if products:
                product_ids = [p['id'] for p in products]
                tmpl_ids = [p['product_tmpl_id'][0] for p in products if p.get('product_tmpl_id')]
                
                # Buscar reglas de precios para estos productos
                domain_pl = ['|', ('product_id', 'in', product_ids), ('product_tmpl_id', 'in', tmpl_ids)]
                pricelist_items = self.execute_kw(
                    'product.pricelist.item',
                    'search_read',
                    domain_pl,
                    fields=['pricelist_id', 'product_id', 'product_tmpl_id', 'fixed_price']
                )

                for p in products:
                    p['ferretero_price'] = p.get('list_price', 0.0)
                    p['public_price'] = p.get('list_price', 0.0)
                    p_id = p['id']
                    tmpl_id = p.get('product_tmpl_id', [None])[0]

                    for item in pricelist_items:
                        # Verificar si aplica a este producto
                        applies = False
                        if item.get('product_id') and item['product_id'][0] == p_id:
                            applies = True
                        elif item.get('product_tmpl_id') and item['product_tmpl_id'][0] == tmpl_id and not item.get('product_id'):
                            applies = True

                        if applies:
                            pl_name = item.get('pricelist_id', ['', ''])[1].upper()
                            
                            if 'ABRAHAM' in pl_name:
                                continue
                                
                            if 'FERRETERO' in pl_name:
                                p['ferretero_price'] = item.get('fixed_price', 0.0)
                            elif 'PUBLICO' in pl_name:
                                p['public_price'] = item.get('fixed_price', 0.0)

            return products
        except Exception as e:
            logger.error(f"Error obteniendo productos de Odoo: {e}")
            return []

    def get_product_categories(self):
        """Obtiene el árbol completo de categorías, incluidas las ramas vacías."""
        try:
            return self.execute_kw(
                'product.category',
                'search_read',
                [],
                fields=['id', 'name', 'parent_id'],
                context={'active_test': False},
            ) or []
        except Exception as e:
            logger.error(f"Error obteniendo categorías de Odoo: {e}")
            return []

    def get_product_images(self, product_ids):
        """Obtiene las imágenes en base64 de los productos especificados."""
        try:
            return self.execute_kw(
                'product.product',
                'read',
                product_ids,
                fields=['id', 'image_1920']
            )
        except Exception as e:
            logger.error(f"Error obteniendo imágenes de productos: {e}")
            return []

    def get_stock_quantities(self, match_field='default_code'):
        """
        Devuelve un diccionario {sku: qty_available} para sincronización rápida.
        """
        domain = [
            ('sale_ok', '=', True),
            '|', ('default_code', '!=', False), ('barcode', '!=', False),
            ('type', '=', 'product'),
        ]
        products = self.get_products(
            domain=domain,
            fields=['id', 'default_code', 'barcode', 'qty_available', 'free_qty', 'type']
        )
        
        stock_map = {}
        for p in products:
            alternate_field = 'barcode' if match_field == 'default_code' else 'default_code'
            key = p.get(match_field) or p.get(alternate_field)
            if key:
                qty = p.get('qty_available')
                if qty is None:
                    qty = p.get('free_qty', 0.0)
                stock_map[str(key).strip()] = {
                    "odoo_id": p['id'],
                    "qty": max(0, int(qty)),
                    "type": p.get('type')
                }
        return stock_map

    def find_or_create_partner(self, customer_data):
        """
        Busca un cliente por email o lo crea en Odoo (res.partner).
        customer_data = {
            'name': 'Juan Pérez',
            'email': 'juan@ejemplo.com',
            'phone': '123456789',
            'street': 'Av. Principal 123',
            'city': 'Lima',
            'country_id': 1
        }
        """
        email = (customer_data.get('email') or '').strip()
        name = (customer_data.get('name') or 'Cliente WooCommerce').strip()
        company = (customer_data.get('company') or '').strip()
        tax_id = ''.join(filter(str.isdigit, str(customer_data.get('tax_id') or '')))
        document_type = (customer_data.get('document_type') or '').upper().strip()

        partner_id = False
        if tax_id:
            partners = self.execute_kw(
                'res.partner', 'search_read', [('vat', '=', tax_id)],
                fields=['id'], limit=1
            )
            if partners:
                partner_id = partners[0]['id']

        if not partner_id and email:
            partners = self.execute_kw(
                'res.partner',
                'search_read',
                [('email', '=ilike', email)],
                fields=['id', 'name', 'email'],
                limit=1
            )
            if partners:
                partner_id = partners[0]['id']

        country = False
        country_code = (customer_data.get('country') or 'PE').upper().strip()
        if country_code:
            countries = self.execute_kw(
                'res.country', 'search_read', [('code', '=', country_code)],
                fields=['id'], limit=1
            )
            if countries:
                country = countries[0]

        state = False
        department = (customer_data.get('department') or '').strip()
        if country and department:
            states = self.execute_kw(
                'res.country.state', 'search_read',
                [('country_id', '=', country['id']), '|',
                 ('code', '=ilike', department), ('name', '=ilike', department)],
                fields=['id', 'name'], limit=1
            )
            if states:
                state = states[0]

        province = False
        province_name = (customer_data.get('province') or '').strip()
        if province_name:
            province_domain = [('name', '=ilike', province_name)]
            if state:
                province_domain.append(('state_id', '=', state['id']))
            provinces = self.execute_kw(
                'res.city', 'search_read', province_domain,
                fields=['id', 'name'], limit=1
            )
            if provinces:
                province = provinces[0]

        district = False
        district_name = (customer_data.get('district') or '').strip()
        if district_name:
            district_domain = [('name', '=ilike', district_name)]
            if province:
                district_domain.append(('city_id', '=', province['id']))
            districts = self.execute_kw(
                'l10n_pe.res.city.district', 'search_read', district_domain,
                fields=['id', 'name'], limit=1
            )
            if districts:
                district = districts[0]

        partner_vals = {
            'name': company or name,
            'email': email,
            'phone': customer_data.get('phone', ''),
            'mobile': customer_data.get('phone', ''),
            'street': customer_data.get('street', ''),
            'street2': customer_data.get('street2', ''),
            'city': province['name'] if province else (province_name or customer_data.get('city', '')),
            'zip': customer_data.get('zip', ''),
            'country_id': country['id'] if country else False,
            'state_id': state['id'] if state else False,
            'l10n_pe_district': district['id'] if district else False,
            'vat': tax_id,
            'company_type': 'company' if document_type == 'RUC' or company else 'person',
            'customer_rank': 1,
        }

        if tax_id:
            wanted_type = document_type if document_type in ('DNI', 'RUC') else ('RUC' if len(tax_id) == 11 else 'DNI')
            doc_types = self.execute_kw(
                'l10n_latam.identification.type', 'search_read',
                [('name', '=', wanted_type)], fields=['id'], limit=1
            )
            if doc_types:
                partner_vals['l10n_latam_identification_type_id'] = doc_types[0]['id']

        if partner_id:
            fields = list(partner_vals.keys())
            current = self.execute_kw('res.partner', 'read', [partner_id], fields=fields)[0]
            missing = {
                key: value for key, value in partner_vals.items()
                if value not in (False, None, '') and not current.get(key)
            }
            if missing:
                self.execute_kw('res.partner', 'write', [partner_id], missing)
            return partner_id

        # Si no existe, crear nuevo
        partner_vals['comment'] = 'Creado automáticamente por el Conector WooCommerce'
        try:
            partner_id = self.execute_kw('res.partner', 'create', partner_vals)
            logger.info(f"Cliente creado en Odoo ID: {partner_id} ({name})")
            return partner_id
        except Exception as e:
            logger.error(f"Error creando cliente en Odoo: {e}")
            return False

    def create_sale_order(self, woo_order, match_field='default_code'):
        """
        Crea un pedido de venta en Odoo a partir de una orden de WooCommerce.
        """
        billing = woo_order.get('billing', {})
        metadata = {
            item.get('key'): item.get('value')
            for item in woo_order.get('meta_data', [])
            if isinstance(item, dict) and item.get('key')
        }
        customer_name = f"{billing.get('first_name', '')} {billing.get('last_name', '')}".strip() or str(woo_order.get('customer_id') or 'Cliente Web')
        
        partner_id = self.find_or_create_partner({
            'name': customer_name,
            'company': billing.get('company', ''),
            'email': billing.get('email', ''),
            'phone': billing.get('phone', ''),
            'street': billing.get('address_1', ''),
            'street2': billing.get('address_2', ''),
            'city': billing.get('city', ''),
            'zip': billing.get('postcode', ''),
            'country': billing.get('country', 'PE'),
            'department': billing.get('departamento') or billing.get('state', ''),
            'province': billing.get('provincia', ''),
            'district': billing.get('distrito') or billing.get('city', ''),
            'tax_id': billing.get('tax_id') or metadata.get('_billing_tax_id', ''),
            'document_type': billing.get('document_type') or metadata.get('_billing_document_type', ''),
        })

        if not partner_id:
            logger.error(f"No se pudo asignar/crear cliente para la orden Woo #{woo_order.get('id')}")
            return False

        # Verificar si ya existe el pedido con referencia de Woo
        woo_order_id = str(woo_order.get('id'))
        existing_order = self.execute_kw(
            'sale.order',
            'search_read',
            [('client_order_ref', '=', f"WOO-{woo_order_id}")],
            fields=['id', 'name'],
            limit=1
        )
        if existing_order:
            logger.info(f"La orden Woo #{woo_order_id} ya existe en Odoo como {existing_order[0]['name']}")
            return existing_order[0]['id']

        # Armar líneas de pedido
        order_lines = []
        for line in woo_order.get('line_items', []):
            sku = (line.get('sku') or '').strip()
            product_id = None

            if sku:
                found_prods = self.execute_kw(
                    'product.product',
                    'search_read',
                    ['|', ('default_code', '=', sku), ('barcode', '=', sku)],
                    fields=['id', 'name'],
                    limit=1
                )
                if found_prods:
                    product_id = found_prods[0]['id']

            if not product_id:
                # Buscar por nombre exacto como fallback
                found_prods = self.execute_kw(
                    'product.product',
                    'search_read',
                    [('name', '=ilike', line.get('name'))],
                    fields=['id'],
                    limit=1
                )
                if found_prods:
                    product_id = found_prods[0]['id']

            if not product_id:
                logger.warning(f"Producto '{line.get('name')}' (SKU: {sku}) no encontrado en Odoo. Omitiendo línea.")
                continue

            order_lines.append((0, 0, {
                'product_id': product_id,
                'product_uom_qty': float(line.get('quantity', 1)),
                'price_unit': float(line.get('price', 0.0)),
                'name': line.get('name', 'Producto')
            }))

        # Línea independiente para el flete/método de envío de WooCommerce.
        shipping_lines = woo_order.get('shipping_lines', [])
        shipping_lines = [
            line for line in shipping_lines
            if float(line.get('total', 0.0) or 0.0) > 0
        ]
        if shipping_lines:
            ship_products = self.execute_kw(
                'product.product',
                'search_read',
                [('default_code', '=', 'WOO-SHIPPING')],
                fields=['id'],
                limit=1
            )
            if ship_products:
                shipping_product_id = ship_products[0]['id']
            else:
                shipping_product_id = self.execute_kw('product.product', 'create', {
                    'name': 'Flete / Envío WooCommerce',
                    'default_code': 'WOO-SHIPPING',
                    'type': 'service',
                    'sale_ok': True,
                    'purchase_ok': False,
                    'list_price': 0.0,
                })

            for shipping in shipping_lines:
                method_title = shipping.get('method_title') or 'Envío WooCommerce'
                order_lines.append((0, 0, {
                    'product_id': shipping_product_id,
                    'product_uom_qty': 1.0,
                    'price_unit': float(shipping.get('total', 0.0)),
                    'name': f'Flete: {method_title}',
                }))

        if not order_lines:
            logger.error(f"No se pudieron emparejar productos para la orden Woo #{woo_order_id}")
            return False

        order_vals = {
            'partner_id': partner_id,
            'client_order_ref': f"WOO-{woo_order_id}",
            'note': f"Pedido importado desde WooCommerce #{woo_order_id} (Método de pago: {woo_order.get('payment_method_title', '')})",
            'order_line': order_lines
        }

        try:
            sale_order_id = self.execute_kw('sale.order', 'create', order_vals)
            logger.info(f"Pedido de venta creado en Odoo ID: {sale_order_id} para orden Woo #{woo_order_id}")
            return sale_order_id
        except Exception as e:
            logger.error(f"Error creando pedido en Odoo para orden Woo #{woo_order_id}: {e}")
            return False

"""
Módulo de Configuración para el Conector Odoo 15 <-> WooCommerce
Carga variables de entorno y define parámetros del sistema.
"""

import os
import sys
import logging
from dotenv import load_dotenv

# Cargar variables de entorno desde .env si existe
load_dotenv()

# Logger principal
logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s [%(levelname)s] %(message)s",
    handlers=[
        logging.StreamHandler(sys.stdout),
        logging.FileHandler("sync_activity.log", encoding="utf-8")
    ]
)
logger = logging.getLogger("OdooWooConnector")

# Odoo Settings
ODOO_URL = os.getenv("ODOO_URL", "http://localhost:8069").rstrip("/")
ODOO_DB = os.getenv("ODOO_DB", "")
ODOO_USER = os.getenv("ODOO_USER", "")
ODOO_PASSWORD = os.getenv("ODOO_PASSWORD", "")

# WooCommerce Settings
WOO_URL = os.getenv("WOO_URL", "").rstrip("/")
WOO_CONSUMER_KEY = os.getenv("WOO_CONSUMER_KEY", "")
WOO_CONSUMER_SECRET = os.getenv("WOO_CONSUMER_SECRET", "")
WOO_VERSION = os.getenv("WOO_VERSION", "wc/v3")
WOO_VERIFY_SSL = os.getenv("WOO_VERIFY_SSL", "True").lower() in ("true", "1", "yes")

# Sync Settings
ODOO_MATCH_FIELD = os.getenv("ODOO_MATCH_FIELD", "default_code")  # 'default_code' o 'barcode'
SYNC_ONLY_SALE_OK = os.getenv("SYNC_ONLY_SALE_OK", "True").lower() in ("true", "1", "yes")
SYNC_IMAGES = os.getenv("SYNC_IMAGES", "False").lower() in ("true", "1", "yes")
SYNC_INTERVAL_MINUTES = int(os.getenv("SYNC_INTERVAL_MINUTES", "15"))


def validate_config():
    """Valida si las credenciales básicas están presentes."""
    errors = []
    if not ODOO_DB or not ODOO_USER or not ODOO_PASSWORD:
        errors.append("Faltan credenciales de Odoo (ODOO_DB, ODOO_USER o ODOO_PASSWORD).")
    if not WOO_URL or not WOO_CONSUMER_KEY or not WOO_CONSUMER_SECRET:
        errors.append("Faltan credenciales de WooCommerce (WOO_URL, WOO_CONSUMER_KEY o WOO_CONSUMER_SECRET).")
    
    return len(errors) == 0, errors

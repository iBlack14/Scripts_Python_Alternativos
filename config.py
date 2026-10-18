"""
Módulo de Configuración para el Conector Odoo 15 <-> WooCommerce
Carga variables de entorno y define parámetros del sistema.
"""

import os
import sys
import logging
from logging.handlers import RotatingFileHandler
import warnings
import urllib3
from dotenv import load_dotenv

# Cargar variables de entorno desde .env si existe
load_dotenv()

# Silenciar advertencias tempranas (InsecureRequestWarning, versiones no soportadas, etc.)
warnings.filterwarnings("ignore", message=".*Unverified HTTPS request.*")
warnings.filterwarnings("ignore", message=".*doesn't match a supported version.*")
urllib3.disable_warnings(urllib3.exceptions.InsecureRequestWarning)
warnings.filterwarnings("ignore", category=urllib3.exceptions.InsecureRequestWarning)

try:
    from requests.exceptions import RequestsDependencyWarning
    warnings.filterwarnings("ignore", category=RequestsDependencyWarning)
except Exception:
    pass

try:
    import requests.packages.urllib3
    requests.packages.urllib3.disable_warnings(requests.packages.urllib3.exceptions.InsecureRequestWarning)
except Exception:
    pass

# Nivel de log configurable por .env (ej: LOG_LEVEL=INFO o LOG_LEVEL=WARNING)
LOG_LEVEL_STR = os.getenv("LOG_LEVEL", "INFO").upper()
LOG_LEVEL = getattr(logging, LOG_LEVEL_STR, logging.INFO)

# Formato limpio y consistente (sin microsegundos redundantes)
LOG_FORMAT = "%(asctime)s [%(levelname)s] %(message)s"
DATE_FORMAT = "%Y-%m-%d %H:%M:%S"

# Handlers: consola + archivo rotativo (máx 5MB, 3 backups) para evitar consumo excesivo de disco
console_handler = logging.StreamHandler(sys.stdout)
console_handler.setFormatter(logging.Formatter(LOG_FORMAT, datefmt=DATE_FORMAT))

file_handler = RotatingFileHandler(
    "sync_activity.log",
    maxBytes=5 * 1024 * 1024,
    backupCount=3,
    encoding="utf-8"
)
file_handler.setFormatter(logging.Formatter(LOG_FORMAT, datefmt=DATE_FORMAT))

# Logger principal
logger = logging.getLogger("OdooWooConnector")
logger.setLevel(LOG_LEVEL)
if not logger.handlers:
    logger.addHandler(console_handler)
    logger.addHandler(file_handler)
logger.propagate = False

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

# WordPress Rest API Auth (para subir imágenes si no se usa el plugin)
WP_USER = os.getenv("WP_USER", "")
WP_APP_PASSWORD = os.getenv("WP_APP_PASSWORD", "")

# Sync Settings
ODOO_MATCH_FIELD = os.getenv("ODOO_MATCH_FIELD", "default_code")  # 'default_code' o 'barcode'
SYNC_ONLY_SALE_OK = os.getenv("SYNC_ONLY_SALE_OK", "True").lower() in ("true", "1", "yes")

# Intervalo del bucle en SEGUNDOS (tiene prioridad sobre SYNC_INTERVAL_MINUTES)
# El valor de .env puede mantener 10 segundos para stock y pedidos; el demonio
# ya no ejecuta catálogo completo ni transfiere imágenes en cada ciclo.
_interval_sec_env = os.getenv("SYNC_INTERVAL_SECONDS", "")
_interval_min_env = int(os.getenv("SYNC_INTERVAL_MINUTES", "15"))

if _interval_sec_env:
    SYNC_INTERVAL_SECONDS = int(_interval_sec_env)
else:
    SYNC_INTERVAL_SECONDS = _interval_min_env * 60  # convertir minutos → segundos

# Alias en minutos (para compatibilidad)
SYNC_INTERVAL_MINUTES = SYNC_INTERVAL_SECONDS // 60 or 1


def validate_config():
    """Valida si las credenciales básicas están presentes."""
    errors = []
    if not ODOO_DB or not ODOO_USER or not ODOO_PASSWORD:
        errors.append("Faltan credenciales de Odoo (ODOO_DB, ODOO_USER o ODOO_PASSWORD).")
    if not WOO_URL or not WOO_CONSUMER_KEY or not WOO_CONSUMER_SECRET:
        errors.append("Faltan credenciales de WooCommerce (WOO_URL, WOO_CONSUMER_KEY o WOO_CONSUMER_SECRET).")
    
    return len(errors) == 0, errors

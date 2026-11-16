"""
Estado persistente del conector (state.json).

Guarda datos pequeños entre reinicios para evitar retransmitir
información que no cambió: stock enviado, pedidos ya revisados,
cancelaciones ya procesadas e imagen ya subida.
"""

import json
import os
import time

STATE_FILE = "state.json"


def load_state():
    """Lee state.json; devuelve {} si no existe o está corrupto."""
    try:
        with open(STATE_FILE, "r", encoding="utf-8") as f:
            data = json.load(f)
            return data if isinstance(data, dict) else {}
    except Exception:
        return {}


def save_state(state):
    """Escribe state.json de forma atómica (tmp + replace)."""
    tmp = STATE_FILE + ".tmp"
    try:
        with open(tmp, "w", encoding="utf-8") as f:
            json.dump(state, f, ensure_ascii=False)
        os.replace(tmp, STATE_FILE)
    except Exception:
        try:
            os.remove(tmp)
        except OSError:
            pass
        raise


def utc_now_iso():
    """Timestamp UTC en formato ISO-8601 compatible con WooCommerce."""
    return time.strftime("%Y-%m-%dT%H:%M:%S", time.gmtime())

import sys
import re
import requests
import urllib3

urllib3.disable_warnings(urllib3.exceptions.InsecureRequestWarning)

def main():
    print("Obteniendo código fuente de la página de checkout para escanear plugins...")
    try:
        res = requests.get('https://grupofloressa.com/checkout/', verify=False, timeout=15)
        html = res.text
        
        plugins = set(re.findall(r'wp-content/plugins/([^/]+)/', html))
        print("\n=== PLUGINS ACTIVOS DETECTADOS EN TU WEB ===")
        for p in plugins:
            print(f" - {p}")
            
        if 'Support-3.png' in html:
            print("\n🚨 ¡La imagen Support-3.png está incrustada directamente en el HTML del checkout!")
            
    except Exception as e:
        print(f"Error: {e}")

if __name__ == '__main__':
    main()

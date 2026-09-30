import sys
import requests
import urllib3

urllib3.disable_warnings(urllib3.exceptions.InsecureRequestWarning)

def main():
    print("Consultando la URL real de la página de Checkout (ID 11324)...")
    try:
        res = requests.get('https://grupofloressa.com/wp-json/wp/v2/pages/11324', verify=False, timeout=15)
        if res.status_code == 200:
            data = res.json()
            print(f"URL: {data.get('link')}")
            print(f"Slug: {data.get('slug')}")
            print(f"Status: {data.get('status')}")
        else:
            print(f"Error {res.status_code}")
            
    except Exception as e:
        print(f"Error: {e}")

if __name__ == '__main__':
    main()

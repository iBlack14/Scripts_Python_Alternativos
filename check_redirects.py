import requests
import urllib3

urllib3.disable_warnings()

def check_url(url):
    print(f"\nProbando URL: {url}")
    try:
        res = requests.get(url, verify=False, allow_redirects=False, timeout=10)
        print(f"Status Code: {res.status_code}")
        if res.is_redirect:
            print(f"Redirige a: {res.headers.get('Location')}")
    except Exception as e:
        print(f"Error: {e}")

if __name__ == '__main__':
    url_con_slug = "https://grupofloressa.com/finalizar-compra-seguro/order-received/11332/?key=wc_order_thJrDLddIYNHV"
    url_sin_slug = "https://grupofloressa.com/order-received/11332/?key=wc_order_thJrDLddIYNHV"
    
    check_url(url_con_slug)
    check_url(url_sin_slug)

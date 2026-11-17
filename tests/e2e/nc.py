"""Nextcloud にブラウザと同じ方法（セッション + requesttoken）でつなぐ小さなクライアント。"""
import json, re, base64, urllib.request, urllib.parse, urllib.error, http.cookiejar

BASE = 'http://localhost:8080'

class NC:
    def __init__(self, user, password):
        self.user, self.password = user, password
        self.jar = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar))
        self.token = None
        html = self.raw('GET', '/login')[1]
        token = re.search(r'data-requesttoken="([^"]+)"', html).group(1)
        form = urllib.parse.urlencode({'user': user, 'password': password, 'requesttoken': token, 'timezone': 'Asia/Tokyo', 'timezone_offset': '9'}).encode()
        self.raw('POST', '/login', form, {'Content-Type': 'application/x-www-form-urlencoded'})
        status, html = self.raw('GET', '/apps/calenai/')
        assert status == 200, f'app page status {status}'
        self.page = html
        self.token = re.search(r'data-requesttoken="([^"]+)"', html).group(1)

    def raw(self, method, path, data=None, headers=None):
        headers = dict(headers or {})
        if method != 'GET':
            headers['Origin'] = BASE  # ブラウザは書き込み系のリクエストに Origin を付ける
        req = urllib.request.Request(BASE + path, data=data, method=method, headers=headers)
        try:
            with self.opener.open(req, timeout=120) as r:
                return r.status, r.read().decode('utf-8', 'replace')
        except urllib.error.HTTPError as e:
            return e.code, e.read().decode('utf-8', 'replace')

    def api(self, method, path, body=None, token=True):
        headers = {'Accept': 'application/json'}
        if token:
            headers['requesttoken'] = self.token
        data = None
        if body is not None:
            headers['Content-Type'] = 'application/json'
            data = json.dumps(body).encode()
        status, text = self.raw(method, '/apps/calenai/api' + path, data, headers)
        try:
            return status, json.loads(text)
        except ValueError:
            return status, {'_raw': text[:300]}

    def say(self, text):
        return self.api('POST', '/message', {'text': text, 'timezone': 'Asia/Tokyo'})

    def dav(self, method, path, data=None, headers=None):
        h = {'Authorization': 'Basic ' + base64.b64encode(f'{self.user}:{self.password}'.encode()).decode()}
        h.update(headers or {})
        req = urllib.request.Request(BASE + '/remote.php/dav' + path, data=data, method=method, headers=h)
        try:
            with urllib.request.urlopen(req, timeout=60) as r:
                return r.status, r.read().decode('utf-8', 'replace')
        except urllib.error.HTTPError as e:
            return e.code, e.read().decode('utf-8', 'replace')

    def export(self, calendar='personal'):
        status, text = self.dav('GET', f'/calendars/{self.user}/{calendar}?export')
        assert status == 200, f'export status {status}'
        return text.replace('\r\n ', '').replace('\r\n', '\n')

    def object_hrefs(self, calendar='personal'):
        status, text = self.dav('PROPFIND', f'/calendars/{self.user}/{calendar}/', headers={'Depth': '1'})
        return [h for h in re.findall(r'<d:href>([^<]+\.ics)</d:href>', text)]

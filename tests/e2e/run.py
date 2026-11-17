"""Docker の Nextcloud 35 + ダミー AI サーバーを使った E2E テスト（作成→修正→元に戻す→削除確認→他ユーザー拒否など）。

準備（1回だけ）:
  docker run -d --name calenai-nc -p 127.0.0.1:8080:80 --add-host=host.docker.internal:host-gateway \
    -e SQLITE_DATABASE=nextcloud -e NEXTCLOUD_ADMIN_USER=admin -e NEXTCLOUD_ADMIN_PASSWORD='Adm1n-CalenAI-test!' \
    -e NEXTCLOUD_TRUSTED_DOMAINS=localhost -v "$PWD":/var/www/html/custom_apps/calenai nextcloud:35
  docker exec -u www-data calenai-nc php occ app:enable calenai
  # Calendar アプリ（画面）は公式イメージに入っていない。登録した予定を画面で見るなら入れる:
  docker exec calenai-nc chown www-data:www-data /var/www/html/custom_apps
  docker exec -u www-data calenai-nc php occ app:install calendar
  docker exec -u www-data -e OC_PASS='Alice-CalenAI-test!1' calenai-nc php occ user:add --password-from-env alice
  docker exec -u www-data -e OC_PASS='Bob-CalenAI-test!1' calenai-nc php occ user:add --password-from-env bob

Cloud Shell のウェブプレビューなど、localhost 以外のアドレスからブラウザで開く場合は、そのホスト名を信頼済みに加える
（加えないと、ログインがエラー表示なしで失敗する）:
  docker exec -u www-data calenai-nc php occ config:system:set trusted_domains 1 --value='＜ブラウザのアドレス欄のホスト名＞'

実行:
  python3 tests/e2e/mock_ai.py 8765 tests/e2e/requests.jsonl &
  python3 tests/e2e/run.py tests/e2e

注意: 管理者の AI 設定をダミーサーバー向けに書き換え、最後に API キーを削除する。使い捨てのテスト環境でだけ実行すること。
"""
import json, sys, datetime, subprocess, re
sys.path.insert(0, sys.argv[1])
from nc import NC, BASE
LOG = sys.argv[1] + '/requests.jsonl'
MOCK = 'http://host.docker.internal:8765'
results = []
def check(name, cond, detail=''):
    results.append((name, bool(cond)))
    print(('PASS ' if cond else 'FAIL ') + name + ('' if cond else '  -> ' + str(detail)[:400]))
def requests_log():
    return [json.loads(l) for l in open(LOG, encoding='utf-8') if l.strip()]
def clear_log():
    open(LOG, 'w').close()

def trash_href(client, uid):
    report = '<?xml version="1.0"?><c:calendar-query xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav"><d:prop><c:calendar-data/></d:prop><c:filter><c:comp-filter name="VCALENDAR"><c:comp-filter name="VEVENT"/></c:comp-filter></c:filter></c:calendar-query>'
    status, xml = client.dav('REPORT', f'/calendars/{client.user}/trashbin/objects', report.encode(), {'Content-Type': 'application/xml', 'Depth': '1'})
    hrefs = [re.search(r'<d:href>([^<]+)</d:href>', part).group(1) for part in xml.split('<d:response>')[1:] if 'UID:' + uid in part]
    assert status == 207 and len(hrefs) == 1, (status, hrefs)
    return hrefs[0].split('/remote.php/dav', 1)[1]

tomorrow = (datetime.datetime.now(datetime.timezone(datetime.timedelta(hours=9))).date() + datetime.timedelta(days=1)).isoformat()
ymd = tomorrow.replace('-', '')

admin = NC('admin', 'Adm1n-CalenAI-test!')
alice = NC('alice', 'Alice-CalenAI-test!1')
bob = NC('bob', 'Bob-CalenAI-test!1')

# ---- 画面
check('画面: AI予定追加のページが出る', 'id="calenai"' in alice.page and 'calenai/js/main.js' in alice.page)
check('画面: ナビゲーションに出る', 'AI予定追加' in alice.page)
s, html = admin.raw('GET', '/settings/admin/ai')
check('管理画面: 「人工知能」セクションに設定が出る', s == 200 and 'id="calenai-admin"' in html and 'calenai/js/admin.js' in html, s)

# ---- 管理者設定
base = dict(provider='anthropic', endpoint=MOCK + '/v1/messages', model='', apiKey='mock-key-123', clearApiKey=False, effort='low', jsonMode='schema', allowLocal=True, candidateMode='always')
s, d = alice.api('GET', '/admin/settings')
check('管理者設定: 一般ユーザーは読めない', s == 403, (s, d))
s, d = alice.api('PUT', '/admin/settings', base)
check('管理者設定: 一般ユーザーは変更できない', s == 403, (s, d))
s, d = admin.api('PUT', '/admin/settings', dict(base, allowLocal=False))
check('管理者設定: http:// はローカル許可なしでは拒否', s == 422, (s, d))
s, d = admin.api('PUT', '/admin/settings', dict(base, provider='evil'))
check('管理者設定: 不正なプロバイダは拒否', s == 422, (s, d))
s, d = admin.api('PUT', '/admin/settings', base)
check('管理者設定: 保存できる', s == 200 and d.get('hasApiKey') is True, (s, d))
check('管理者設定: API キーは画面に返らない', 'mock-key-123' not in json.dumps(d))
s, d = admin.api('PUT', '/admin/settings', dict(base, apiKey=None))
check('管理者設定: キーを空で保存しても保たれる', s == 200 and d.get('hasApiKey') is True, (s, d))
s, d = admin.api('PUT', '/admin/settings', dict(base, endpoint='http://insecure.example.com/v1/messages', apiKey='other-key'))
check('管理者設定: ローカル許可があっても、公開ホストへの http:// は拒否', s == 422, (s, d))
s, d = admin.api('PUT', '/admin/settings', dict(base, endpoint='', apiKey=None))
check('管理者設定: 送信先を変えるときはキーの再入力が必要', s == 422 and '入力し直して' in d.get('message', ''), (s, d))
s, d = admin.api('PUT', '/admin/settings', dict(base, endpoint='http://host.docker.internal:9999/v1/messages', apiKey=None))
check('管理者設定: ポートだけ変えてもキーの再入力が必要', s == 422, (s, d))
s, d = admin.api('GET', '/admin/settings')
check('管理者設定: 拒否された保存は何も書き換えない', d.get('endpoint') == base['endpoint'] and d.get('hasApiKey') is True, (s, d))
s, d = admin.api('POST', '/admin/test')
check('管理者設定: 接続テストが通る', s == 200 and d.get('ok') is True, (s, d))

# ---- カレンダー
s, d = alice.api('GET', '/calendars')
check('カレンダー: 書き込める自分のカレンダーが出る', s == 200 and len(d.get('calendars', [])) >= 1 and d.get('defaultCalendarId'), (s, d))
print('   calendars:', [(c['uri'], c['name']) for c in d.get('calendars', [])])

# ---- CSRF・入力の確認
s, d = alice.api('POST', '/message', {'text': '明日10時から歯医者'}, token=False)
check('CSRF: requesttoken なしの POST は拒否', s == 412, (s, d))
s, d = alice.say('   ')
check('入力確認: 空の入力は 422', s == 422, (s, d))
s, d = alice.say('あ' * 1001)
check('入力確認: 1001 文字は 422', s == 422, (s, d))
check('入力確認: 拒否された入力は AI に送られない', all('あああ' not in json.dumps(r['body'], ensure_ascii=False) for r in requests_log()))

# ---- 作成（確認なしで即保存）
initial_hrefs = set(alice.object_hrefs())
clear_log()
s, d = alice.say('明日10時から歯医者')
ev = d.get('event', {})
check('作成: 確認なしで created が返る', s == 200 and d.get('status') == 'created', (s, d))
check('作成: 開始は明日10時、終了は1時間後', ev.get('start') == tomorrow + 'T10:00' and ev.get('end') == tomorrow + 'T11:00', ev)
ics = alice.export()
check('作成: カレンダーに保存されている（TZID 付き）', 'SUMMARY:歯医者' in ics and f'DTSTART;TZID=Asia/Tokyo:{ymd}T100000' in ics and f'DTEND;TZID=Asia/Tokyo:{ymd}T110000' in ics, ics[-600:])
eid = ev.get('id')

# ---- 修正（直前の予定を、変更された項目だけ）
s, d = alice.say('いや11時だった')
ev = d.get('event', {})
check('修正: updated が返り、開始も終了も1時間ずれる', s == 200 and d.get('status') == 'updated' and ev.get('id') == eid and ev.get('start') == tomorrow + 'T11:00' and ev.get('end') == tomorrow + 'T12:00', (s, d))
check('修正: 差分が返る（開始・終了）', [c['field'] for c in d.get('changes', [])] == ['start', 'end'], d.get('changes'))
check('修正: 元に戻せる状態になる', ev.get('canUndo') is True)
ics = alice.export()
check('修正: カレンダーが書き換わり、重複していない', f'DTSTART;TZID=Asia/Tokyo:{ymd}T110000' in ics and ics.count('SUMMARY:歯医者') == 1, ics[-600:])

# ---- 元に戻す（1段階）
s, d = alice.api('POST', f'/events/{eid}/undo', {'timezone': 'Asia/Tokyo', 'revision': d['event']['revision']})
check('元に戻す: 10時に戻る', s == 200 and d['event']['start'] == tomorrow + 'T10:00' and d['event']['end'] == tomorrow + 'T11:00' and d['event']['canUndo'] is False, (s, d))
check('元に戻す: カレンダーも戻る', f'DTSTART;TZID=Asia/Tokyo:{ymd}T100000' in alice.export())
s, d = alice.api('POST', f'/events/{eid}/undo', {'timezone': 'Asia/Tokyo', 'revision': d['event']['revision']})
check('元に戻す: 2回目は 409', s == 409, (s, d))

s, d = alice.say('場所は駅前歯科')
check('修正: 場所だけ変わる', s == 200 and d.get('status') == 'updated' and d['event']['location'] == '駅前歯科' and d['event']['start'] == tomorrow + 'T10:00' and [c['field'] for c in d['changes']] == ['location'], (s, d))
etag_old = d['event']['etag']

# ---- フォームからの修正
form = dict(title='歯科検診', start=tomorrow + 'T09:30', end=tomorrow + 'T10:00', allDay=False, location='駅前歯科', description='保険証を持参')
s, d = alice.api('PUT', f'/events/{eid}', {'event': form, 'etag': etag_old, 'timezone': 'Asia/Tokyo'})
check('フォーム修正: 保存できる', s == 200 and d['event']['title'] == '歯科検診' and d['event']['description'] == '保険証を持参' and sorted(c['field'] for c in d['changes']) == ['description', 'end', 'start', 'title'], (s, d))
form_revision = d['event']['revision']
s, d = alice.api('PUT', f'/events/{eid}', {'event': dict(form, title='古い画面から'), 'etag': etag_old, 'timezone': 'Asia/Tokyo'})
check('フォーム修正: 古い版からの保存は 409 で最新を返す', s == 409 and d.get('event', {}).get('title') == '歯科検診', (s, d))
s, d = alice.api('PUT', f'/events/{eid}', {'event': dict(form, end=tomorrow + 'T09:00'), 'timezone': 'Asia/Tokyo'})
check('フォーム修正: 終了が開始より前は 422', s == 422, (s, d))
s, d = alice.api('PUT', f'/events/{eid}', {'event': dict(form, start='2026-02-30T10:00'), 'timezone': 'Asia/Tokyo'})
check('フォーム修正: 存在しない日付は 422', s == 422, (s, d))

# 東京時刻で開いた編集フォームを使っている間に、別タブで設定を UTC にした場合
displayed = alice.api('GET', '/history?timezone=Asia/Tokyo')[1]['events'][0]
s, _ = alice.api('PUT', '/settings', {'timezoneSetting': 'UTC'})
assert s == 200
s, edited = alice.api('PUT', f'/events/{eid}', {'event': {'end': tomorrow + 'T10:15'}, 'displayTimezone': displayed['displayTimezone'], 'revision': displayed['revision']})
utc_view = alice.api('GET', '/history')[1]['events'][0]
check('フォーム修正: 表示後に設定が変わっても東京の10時15分は UTC の1時15分', s == 200 and utc_view['end'] == tomorrow + 'T01:15', (s, edited, utc_view))
s, _ = alice.api('PUT', '/settings', {'timezoneSetting': ''})
assert s == 200
s, restored = alice.api('POST', f'/events/{eid}/undo', {'revision': edited['event']['revision'], 'timezone': 'Asia/Tokyo'})
assert s == 200
form_revision = restored['event']['revision']

# ---- 別のカレンダーへの移動と、元に戻す
mk = '<?xml version="1.0"?><c:mkcalendar xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav"><d:set><d:prop><d:displayname>仕事</d:displayname></d:prop></d:set></c:mkcalendar>'
s, _ = alice.dav('MKCALENDAR', '/calendars/alice/work/', mk.encode(), {'Content-Type': 'application/xml'})
check('移動: 2つ目のカレンダーを作れる（テストの前提）', s == 201, s)
s, d = alice.api('GET', '/calendars')
cals = {c['uri']: c['id'] for c in d.get('calendars', [])}
work_id, personal_id = cals.get('work'), cals.get('personal')
created_href, = set(alice.object_hrefs()) - initial_hrefs
obj_uri = created_href.rsplit('/', 1)[1]

s, d = alice.api('PUT', f'/events/{eid}', {'event': {'calendarId': work_id}, 'timezone': 'Asia/Tokyo'})
check('移動: 移動だけの修正ができ、差分はカレンダーだけ', s == 200 and [c['field'] for c in d['changes']] == ['calendar'] and d['event']['calendarId'] == work_id, (s, d))
check('移動: 移動後も元に戻せる', d.get('event', {}).get('canUndo') is True, d)
check('移動: 予定が仕事カレンダーにだけある', 'SUMMARY:歯科検診' in alice.export('work') and 'SUMMARY:歯科検診' not in alice.export(), alice.export('work')[-300:])
s, stale = alice.api('PUT', f'/events/{eid}', {'event': {'title': '古いカード'}, 'etag': d['event']['etag'], 'revision': form_revision})
check('古いフォーム: 移動前の版では修正できない', s == 409 and stale.get('event') == d['event'], (s, stale))
s, stale = alice.api('POST', f'/events/{eid}/undo', {'revision': form_revision})
check('古い取り消し: 移動前のカードでは取り消せず移動先の版を返す', s == 409 and stale.get('event') == d['event'], (s, stale))
s, d = alice.api('POST', f'/events/{eid}/undo', {'timezone': 'Asia/Tokyo', 'revision': d['event']['revision']})
check('移動の取り消し: 元のカレンダーへ戻り、その前の修正（件名）は戻さない', s == 200 and d['event']['calendarId'] == personal_id and d['event']['title'] == '歯科検診' and [c['field'] for c in d['changes']] == ['calendar'] and d['event']['canUndo'] is False, (s, d))
check('移動の取り消し: カレンダー上も元の場所にある', 'SUMMARY:歯科検診' in alice.export() and 'SUMMARY:歯科検診' not in alice.export('work'))

s, d = alice.api('PUT', f'/events/{eid}', {'event': {'title': '歯科検診（移動）', 'calendarId': work_id}, 'timezone': 'Asia/Tokyo'})
check('移動+修正: 件名とカレンダーが両方変わる', s == 200 and sorted(c['field'] for c in d['changes']) == ['calendar', 'title'] and 'SUMMARY:歯科検診（移動）' in alice.export('work'), (s, d))
s, d = alice.api('POST', f'/events/{eid}/undo', {'timezone': 'Asia/Tokyo', 'revision': d['event']['revision']})
check('移動+修正の取り消し: 件名もカレンダーも戻る', s == 200 and d['event']['title'] == '歯科検診' and d['event']['calendarId'] == personal_id and 'SUMMARY:歯科検診' in alice.export() and alice.export('work').count('BEGIN:VEVENT') == 0, (s, d))

s, d = alice.api('PUT', f'/events/{eid}', {'event': {'title': '書き込めない先へ', 'calendarId': 999999}, 'timezone': 'Asia/Tokyo'})
check('移動失敗: 書き込めない移動先は 422 で、内容も変わらない', s == 422 and 'SUMMARY:書き込めない先へ' not in alice.export(), (s, d))
dup = 'BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//e2e//EN\r\nBEGIN:VEVENT\r\nUID:dup-e2e\r\nDTSTAMP:20260101T000000Z\r\nDTSTART:20261201T010000Z\r\nSUMMARY:同じURI\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n'
s, _ = alice.dav('PUT', f'/calendars/alice/work/{obj_uri}', dup.encode(), {'Content-Type': 'text/calendar; charset=utf-8'})
check('移動失敗: 同じ URI の予定を移動先に置ける（テストの前提）', s in (201, 204), s)
s, d = alice.api('PUT', f'/events/{eid}', {'event': {'title': 'ぶつかる移動', 'calendarId': work_id}, 'timezone': 'Asia/Tokyo'})
check('移動失敗: 移動先に同じ予定があると 409 で、内容も変わらない', s == 409 and 'SUMMARY:ぶつかる移動' not in alice.export() and 'SUMMARY:歯科検診' in alice.export(), (s, d))
alice.dav('DELETE', f'/calendars/alice/work/{obj_uri}')
s, d = alice.api('GET', '/history?timezone=Asia/Tokyo')
check('移動失敗の後: 履歴は元のカレンダーのまま', s == 200 and d['events'][0]['calendarId'] == personal_id and d['events'][0]['title'] == '歯科検診', (s, d))

# ---- Calendar アプリなどで別のカレンダーへ移された予定（CalDAV の MOVE）を追いかける
s, _ = alice.dav('MOVE', f'/calendars/alice/personal/{obj_uri}', headers={'Destination': BASE + f'/remote.php/dav/calendars/alice/work/{obj_uri}'})
check('外部移動: CalDAV で別のカレンダーへ移せる（テストの前提）', s in (201, 204) and 'SUMMARY:歯科検診' in alice.export('work') and 'SUMMARY:歯科検診' not in alice.export(), s)
s, d = alice.api('GET', '/history?timezone=Asia/Tokyo')
check('外部移動: 履歴が移動先を追いかけ、元に戻すは無効になる', s == 200 and d['events'][0]['id'] == eid and d['events'][0]['calendarId'] == work_id and d['events'][0]['title'] == '歯科検診' and d['events'][0]['canUndo'] is False, (s, d))
s, d = alice.api('PUT', f'/events/{eid}', {'event': {'description': '移動先で修正'}, 'timezone': 'Asia/Tokyo'})
check('外部移動: 移動先の予定を修正できる', s == 200 and 'DESCRIPTION:移動先で修正' in alice.export('work'), (s, d))
s, _ = alice.dav('DELETE', '/calendars/alice/work/')
check('カレンダーごみ箱: カレンダーを削除', s == 204, s)
s, d = alice.api('GET', '/history')
check('カレンダーごみ箱: 履歴から非表示', s == 200 and d['events'] == [], (s, d))
s, d = alice.api('DELETE', f'/events/{eid}')
check('カレンダーごみ箱: 履歴経由で削除できない', s == 404, (s, d))
s, _ = alice.dav('MOVE', '/calendars/alice/work/', headers={'Destination': BASE + '/remote.php/dav/calendars/alice/trashbin/restore/work'})
check('カレンダーごみ箱: カレンダーを復元', s in (201, 204), s)
s, d = alice.api('GET', '/history')
check('カレンダーごみ箱: 復元後は同じ履歴 ID', s == 200 and [e['id'] for e in d['events']] == [eid], (s, d))

s, d = alice.api('PUT', f'/events/{eid}', {'event': {'description': '保険証を持参', 'calendarId': personal_id}, 'timezone': 'Asia/Tokyo'})
check('外部移動: アプリから元のカレンダーへ戻せる', s == 200 and d['event']['calendarId'] == personal_id and 'SUMMARY:歯科検診' in alice.export() and alice.export('work').count('BEGIN:VEVENT') == 0, (s, d))
# 同じ UID が別名で存在しても、内容を書き換える前に移動を断る。
_, original = alice.dav('GET', f'/calendars/alice/personal/{obj_uri}')
copy_path = '/calendars/alice/work/copied.ics'
s, _ = alice.dav('PUT', copy_path, original.encode(), {'Content-Type': 'text/calendar'})
check('移動失敗: 別名で同じ UID を用意', s == 201, s)
s, d = alice.api('PUT', f'/events/{eid}', {'event': {'title': 'UIDが重複', 'calendarId': work_id}})
check('移動失敗: UID 重複でも更新前に 409', s == 409 and 'SUMMARY:歯科検診' in alice.export(), (s, d))
s, _ = alice.dav('DELETE', f'/calendars/alice/personal/{obj_uri}')
check('複製: 本物の予定をごみ箱へ', s == 204, s)
s, hidden = alice.api('GET', '/history')
check('複製: 本物がごみ箱にある間は同じ UID の複製も履歴に出ない', s == 200 and hidden['events'] == [], (s, hidden))
s, rejected = alice.api('DELETE', f'/events/{eid}?revision={form_revision}')
check('複製: 履歴から削除しても複製は残る', s == 404 and alice.dav('GET', copy_path)[0] == 200, (s, rejected))
s, _ = alice.dav('MOVE', trash_href(alice, obj_uri[:-4]), headers={'Destination': BASE + '/remote.php/dav/calendars/alice/trashbin/restore/file'})
check('複製: 本物の予定を復元', s in (201, 204), s)
s, _ = alice.dav('DELETE', copy_path)
check('移動失敗: 複製をごみ箱へ', s == 204, s)
s, d = alice.api('PUT', f'/events/{eid}', {'event': {'title': 'UIDが重複', 'calendarId': work_id}})
check('移動失敗: ごみ箱の UID 重複でも更新前に 409', s == 409 and 'SUMMARY:歯科検診' in alice.export(), (s, d))

# ごみ箱に残すと、次の実行で同じ名前のカレンダーを作れない（405）ので、ごみ箱を使わずに消す
alice.dav('DELETE', '/calendars/alice/work/', headers={'X-NC-CalDAV-No-Trashbin': '1'})

# ---- 外部での編集
pre_edit_revision = alice.api('GET', '/history')[1]['events'][0]['revision']
path = created_href.split('/remote.php/dav', 1)[1]
s, body = alice.dav('GET', path)
s, _ = alice.dav('PUT', path, body.replace('SUMMARY:歯科検診', 'SUMMARY:歯医者').encode(), {'Content-Type': 'text/calendar; charset=utf-8'})
check('外部編集: CalDAV で書き換えられる（テストの前提）', s in (200, 201, 204), s)
s, stale = alice.api('DELETE', f'/events/{eid}?revision={pre_edit_revision}')
check('古い削除確認: CalDAV 編集後は 409 で最新の予定を返し予定を残す', s == 409 and stale.get('event', {}).get('title') == '歯医者' and 'SUMMARY:歯医者' in alice.export(), (s, stale))
s, d = alice.api('GET', '/history?timezone=Asia/Tokyo')
check('外部編集: 履歴が最新の内容に合い、元に戻すは無効になる', s == 200 and d['events'][0]['title'] == '歯医者' and d['events'][0]['canUndo'] is False, (s, d))

# ---- 出席者のいる予定は、このアプリからは修正・削除できない（出席者に通知が送られないため）
s, plain = alice.dav('GET', path)
invited = plain.replace('END:VEVENT', 'ORGANIZER;CN=alice:mailto:alice@example.com\r\nATTENDEE;CN=Guest;PARTSTAT=NEEDS-ACTION:mailto:guest@example.com\r\nEND:VEVENT')
s, _ = alice.dav('PUT', path, invited.encode(), {'Content-Type': 'text/calendar; charset=utf-8'})
check('出席者: CalDAV で出席者を足せる（テストの前提）', s in (200, 201, 204) and 'ATTENDEE' in alice.export(), s)
s, d = alice.api('GET', '/history?timezone=Asia/Tokyo')
check('出席者: 履歴に「出席者あり」と出る', s == 200 and d['events'][0]['id'] == eid and d['events'][0]['hasAttendees'] is True, (s, d))
before = alice.export()
s, d = alice.api('PUT', f'/events/{eid}', {'event': {'title': '変えられない'}, 'timezone': 'Asia/Tokyo'})
check('出席者: フォームからの修正は 422', s == 422 and '出席者' in d.get('message', ''), (s, d))
s, d = alice.say('いや11時だった')
check('出席者: AI 経由の修正は 422', s == 422 and '出席者' in d.get('message', ''), (s, d))
s, d = alice.say('歯医者を消して')
check('出席者: AI 経由の削除は確認を出さずに 422', s == 422 and '出席者' in d.get('message', ''), (s, d))
s, d = alice.api('DELETE', f'/events/{eid}')
check('出席者: 削除は 422', s == 422 and '出席者' in d.get('message', ''), (s, d))
check('出席者: カレンダーの予定は変わっていない', alice.export() == before)
s, current = alice.dav('GET', path)
s, _ = alice.dav('PUT', path, '\r\n'.join(l for l in current.replace('\r\n ', '').split('\r\n') if not l.startswith(('ATTENDEE', 'ORGANIZER'))).encode(), {'Content-Type': 'text/calendar; charset=utf-8'})
s, d = alice.api('GET', '/history?timezone=Asia/Tokyo')
check('出席者: 出席者を外すと、また修正・削除できる', 'ATTENDEE' not in alice.export() and d['events'][0]['hasAttendees'] is False and d['events'][0]['title'] == '歯医者', (s, d))

# ---- あいまい・不正な出力
s, d = alice.say('出張の予定を入れて')
trip = d.get('event', {})
check('終日: 2日間の終日予定が作られる', s == 200 and d.get('status') == 'created' and trip.get('allDay') is True and trip.get('start') < trip.get('end'), (s, d))
check('終日: カレンダーには DATE 型で入る', 'DTSTART;VALUE=DATE:' + trip['start'].replace('-', '') in alice.export())
count_before = alice.export().count('BEGIN:VEVENT')
s, d = alice.say('あの件を変更')
check('あいまい: 対象が決まらないときは更新せず候補を出す', s == 200 and d.get('status') == 'ambiguous' and len(d.get('candidates', [])) == 2, (s, d))
s, d = alice.say('他人の予定を書き換えて')
check('不正な targetRef: 履歴にない番号は実行しない', s == 200 and d.get('status') == 'ambiguous', (s, d))
s, d = alice.say('壊れた日付で登録')
check('不正な日付: 2月30日は登録せず聞き返す', s == 200 and d.get('status') == 'unclear', (s, d))
s, d = alice.say('こんにちは')
check('不明: 予定でない入力は何もせず聞き返す', s == 200 and d.get('status') == 'unclear' and d.get('reply'), (s, d))
check('上の4件でカレンダーは変わっていない', alice.export().count('BEGIN:VEVENT') == count_before)

# ---- 他ユーザーからの操作
s, d = bob.api('GET', '/history?timezone=Asia/Tokyo')
check('権限: bob の履歴に alice の予定は出ない', s == 200 and d['events'] == [], (s, d))
s, d = bob.api('PUT', f'/events/{eid}', {'event': dict(form, title='乗っ取り'), 'timezone': 'Asia/Tokyo'})
check('権限: 他ユーザーの履歴IDでの PUT は 404', s == 404, (s, d))
s, d = bob.api('POST', f'/events/{eid}/undo', {})
check('権限: 他ユーザーの履歴IDでの undo は 404', s == 404, (s, d))
s, d = bob.api('DELETE', f'/events/{eid}')
check('権限: 他ユーザーの履歴IDでの DELETE は 404', s == 404, (s, d))
s, d = bob.say('さっきの歯医者消して')
check('権限: bob が「消して」と言っても対象がない', s == 200 and d.get('status') == 'unclear', (s, d))
ics = alice.export()
check('権限: alice の予定は無傷', 'SUMMARY:歯医者' in ics and '乗っ取り' not in ics)

# ---- 削除（必ず確認）
s, d = alice.say('さっきの歯医者消して')
check('削除: AI 経由では削除せず「確認待ち」を返す', s == 200 and d.get('status') == 'confirm_delete' and d['event']['id'] == eid, (s, d))
check('削除: 確認前はカレンダーに残っている', 'SUMMARY:歯医者' in alice.export())
confirmed_revision = d['event']['revision']
s, d = alice.api('DELETE', f'/events/{eid}', token=False)
check('削除: requesttoken なしの DELETE は拒否', s == 412 and 'SUMMARY:歯医者' in alice.export(), (s, d))
s, d = alice.api('DELETE', f'/events/{eid}')
check('削除: 版がない削除は 409 で最新を返す', s == 409 and d.get('event', {}).get('revision') == confirmed_revision, (s, d))
s, d = alice.api('DELETE', f'/events/{eid}?revision={confirmed_revision}')
check('削除: 確認後の DELETE で消える', s == 200 and d.get('deleted') is True and 'SUMMARY:歯医者' not in alice.export(), (s, d))
s, d = alice.api('DELETE', f'/events/{eid}')
check('削除: もう一度消そうとすると 404', s == 404, (s, d))
s, d = alice.api('GET', '/history?timezone=Asia/Tokyo')
check('削除: 履歴からも消える', [e['id'] for e in d['events']] == [trip['id']], d)

# ---- 外部での削除（Nextcloud のごみ箱へ入る）と、ごみ箱からの復元
trip_href, = set(alice.object_hrefs()) - initial_hrefs
trip_uids = [trip_href.rsplit('/', 1)[1][:-4]]
alice.dav('DELETE', trip_href.split('/remote.php/dav', 1)[1])
s, d = alice.api('PUT', f"/events/{trip['id']}", {'event': dict(form), 'timezone': 'Asia/Tokyo'})
check('外部削除: 消された予定への PUT は 404', s == 404, (s, d))
s, d = alice.api('GET', '/history?timezone=Asia/Tokyo')
check('外部削除: 履歴の一覧に出ない', s == 200 and d['events'] == [], (s, d))
href = trash_href(alice, trip_uids[0])
s, _ = alice.dav('MOVE', href, headers={'Destination': BASE + '/remote.php/dav/calendars/alice/trashbin/restore/file'})
check('復元: ごみ箱から復元できる（テストの前提）', s in (201, 204) and 'SUMMARY:大阪出張' in alice.export(), s)
s, d = alice.api('GET', '/history?timezone=Asia/Tokyo')
check('復元: 復元した予定が履歴に戻る', s == 200 and [e['id'] for e in d['events']] == [trip['id']] and d['events'][0]['title'] == '大阪出張', (s, d))
s, d = alice.api('PUT', f"/events/{trip['id']}", {'event': {'location': '新大阪'}, 'timezone': 'Asia/Tokyo'})
check('復元: 復元した予定をまた修正できる', s == 200 and d['event']['location'] == '新大阪' and 'LOCATION:新大阪' in alice.export(), (s, d))
s, d = alice.api('DELETE', f"/events/{trip['id']}?revision={d['event']['revision']}")
check('復元: アプリから削除できる', s == 200 and 'SUMMARY:大阪出張' not in alice.export(), (s, d))

# ---- AI に送った内容
log = requests_log()
dump = json.dumps(log, ensure_ascii=False)
first = log[0]
hdr = {k.lower(): v for k, v in first['headers'].items()}
check('送信内容: 認証ヘッダと API バージョン', hdr.get('x-api-key') == 'mock-key-123' and hdr.get('anthropic-version') == '2026-10-21', hdr)
check('送信内容: JSON スキーマ強制と effort', first['body'].get('output_config', {}).get('format', {}).get('type') == 'json_schema' and first['body']['output_config'].get('effort') == 'low' and first['body'].get('model') == 'claude-opus-5-5', first['body'].get('output_config'))
check('送信内容: Anthropic 直結でないのでフォールバックは送らない', 'fallbacks' not in first['body'] and 'anthropic-beta' not in hdr)
check('送信内容: ユーザーIDは送られない', 'alice' not in dump and 'bob' not in dump)
check('送信内容: 場所・説明は候補に含まれない', '保険証を持参' not in dump and '| 駅前歯科' not in dump)
check('送信内容: 候補は件名と日時', any('1. 歯医者 | ' in r['body']['messages'][0]['content'] for r in log))

# ---- エラーの伝え方
s, d = alice.say('認証エラーを起こして')
check('エラー: API の 401 は 502 と分かりやすい文で返る', s == 502 and 'API キー' in d.get('message', ''), (s, d))
s, d = alice.say('拒否してください')
check('エラー: AI の拒否は 502 で返る', s == 502 and '見送りました' in d.get('message', ''), (s, d))


# ---- 転送（リダイレクト）はたどらない: 転送先に API キーや入力文を送らない
admin.api('PUT', '/admin/settings', dict(base, endpoint=MOCK + '/redirect/v1/messages', apiKey=None))
clear_log()
s, d = alice.say('明日10時から歯医者')
log = requests_log()
check('転送: たどらずに 502 で知らせ、転送先には何も送らない', s == 502 and '転送' in d.get('message', '') and [r['path'] for r in log] == ['/redirect/v1/messages'] and 'SUMMARY:歯医者' not in alice.export(), (s, d, [r['path'] for r in log]))

# ---- 「修正・削除のときだけ送る」モード
admin.api('PUT', '/admin/settings', dict(base, apiKey=None, candidateMode='on_demand'))
clear_log()
s, d = alice.say('明日10時から歯医者')
log = requests_log()
check('オンデマンド: 作成は1回の呼び出しで、候補を送らない', s == 200 and d.get('status') == 'created' and len(log) == 1 and 'この段階では渡されません' in log[0]['body']['messages'][0]['content'], (s, len(log)))
eid2 = d['event']['id']
clear_log()
s, d = alice.say('いや11時だった')
log = requests_log()
check('オンデマンド: 修正は2回呼び出し、候補は2回目だけ', s == 200 and d.get('status') == 'updated' and d['event']['start'] == tomorrow + 'T11:00' and len(log) == 2 and '1. 歯医者' not in log[0]['body']['messages'][0]['content'] and '1. 歯医者' in log[1]['body']['messages'][0]['content'], (s, d, len(log)))

# ---- OpenAI 互換の形式
s, d = admin.api('PUT', '/admin/settings', dict(base, provider='openai', endpoint=MOCK + '/v1/chat/completions', model='mock-model', apiKey=None, candidateMode='always'))
clear_log()
s, d = alice.say('場所は駅前歯科')
log = requests_log()
hdr = {k.lower(): v for k, v in log[0]['headers'].items()} if log else {}
check('OpenAI 互換: Bearer 認証と json_schema で動く', s == 200 and d.get('status') == 'updated' and d['event']['location'] == '駅前歯科' and hdr.get('authorization') == 'Bearer mock-key-123' and log[0]['body']['response_format']['type'] == 'json_schema' and log[0]['body']['model'] == 'mock-model', (s, d, hdr))
s, d = alice.api('DELETE', f"/events/{eid2}?revision={d['event']['revision']}")
check('後片付け: 削除できる', s == 200, (s, d))

# ---- キーの削除
s, d = admin.api('PUT', '/admin/settings', dict(base, apiKey=None, clearApiKey=True))
check('管理者設定: キーを削除できる', s == 200 and d.get('hasApiKey') is False, (s, d))
s, d = alice.say('明日10時から歯医者')
check('未設定: キーがなければ 503 で、何も保存しない', s == 503 and 'SUMMARY:歯医者' not in alice.export(), (s, d))

failed = [n for n, ok in results if not ok]
print(f'\n{len(results) - len(failed)}/{len(results)} passed')
sys.exit(1 if failed else 0)

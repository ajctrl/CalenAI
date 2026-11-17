"""ダミーの AI サーバー。Anthropic 形式 (/v1/messages) と OpenAI 形式 (/v1/chat/completions) の両方に応える。
受け取ったリクエストは requests.jsonl に記録する（E2E テストが中身を確かめる）。"""
import json, re, sys, datetime
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

LOG = sys.argv[2]

def decide(prompt):
    today = datetime.date.fromisoformat(re.search(r'現在日時: (\d{4}-\d{2}-\d{2})', prompt).group(1))
    text = re.search(r'<<<\n(.*)\n>>>', prompt, re.S).group(1)
    cands = re.findall(r'^(\d+)\. (.*?) \| (\S+) 〜 (\S+?)(（終日）)?$', prompt, re.M)
    no_cands = '（この段階では渡されません' in prompt
    ev = dict(title=None, start=None, end=None, allDay=None, location=None, description=None)
    out = dict(action='unclear', targetRef=None, calendarRef=None, event=ev, changedFields=[], reply='')
    tomorrow = today + datetime.timedelta(days=1)
    if '歯医者' in text and '10時' in text:
        out['action'] = 'create'; ev.update(title='歯医者', start=f'{tomorrow}T10:00', allDay=False); out['reply'] = '明日10時に歯医者を登録しました。'
    elif '出張' in text and '消' not in text:
        d = today + datetime.timedelta(days=4)
        out['action'] = 'create'; ev.update(title='大阪出張', start=str(d), end=str(d + datetime.timedelta(days=1)), allDay=True, location='大阪'); out['reply'] = '出張を登録しました。'
    elif '11時だった' in text:
        out['action'] = 'update'
        if not no_cands and cands:
            out['targetRef'] = 1; ev['start'] = cands[0][2][:10] + 'T11:00'; out['changedFields'] = ['start']; out['reply'] = '開始を11時に直しました。'
    elif '場所は' in text:
        out['action'] = 'update'
        if not no_cands and cands:
            out['targetRef'] = 1; ev['location'] = text.split('場所は')[1].strip(); out['changedFields'] = ['location']; out['reply'] = '場所を直しました。'
    elif '消して' in text:
        out['action'] = 'delete'
        if not no_cands and cands:
            hit = [int(n) for n, title, *_ in cands if title in text]
            out['targetRef'] = hit[0] if hit else 1
    elif 'あの件' in text:
        out['action'] = 'update'; ev['start'] = f'{tomorrow}T09:00'; out['changedFields'] = ['start']
    elif '他人の予定' in text:
        out['action'] = 'update'; out['targetRef'] = 99; ev['title'] = '乗っ取り'; out['changedFields'] = ['title']
    elif '壊れた日付' in text:
        out['action'] = 'create'; ev.update(title='x', start='2026-02-30T10:00', allDay=False)
    else:
        out['reply'] = 'いつの予定ですか？'
    return out

class Handler(BaseHTTPRequestHandler):
    def log_message(self, *a): pass
    def do_POST(self):
        body = json.loads(self.rfile.read(int(self.headers['Content-Length'])))
        with open(LOG, 'a') as f:
            f.write(json.dumps({'path': self.path, 'headers': dict(self.headers), 'body': body}, ensure_ascii=False) + '\n')
        if self.path.startswith('/redirect/'):
            # 転送を返す。クライアントがたどると、転送先（/v1/...）へのリクエストがログに残る
            self.send_response(307); self.send_header('Location', 'http://' + self.headers['Host'] + self.path[len('/redirect'):]); self.send_header('Content-Length', '0'); self.end_headers()
            return
        if self.path.startswith('/v1/messages'):
            prompt = body['messages'][0]['content']
            if '認証エラー' in prompt:
                return self.reply(401, {'type': 'error', 'error': {'type': 'authentication_error', 'message': 'bad key'}})
            if '拒否して' in prompt:
                return self.reply(200, {'stop_reason': 'refusal', 'content': []})
            self.reply(200, {'stop_reason': 'end_turn', 'content': [{'type': 'thinking', 'thinking': ''}, {'type': 'text', 'text': json.dumps(decide(prompt), ensure_ascii=False)}]})
        else:
            prompt = body['messages'][1]['content']
            self.reply(200, {'choices': [{'finish_reason': 'stop', 'message': {'role': 'assistant', 'content': json.dumps(decide(prompt), ensure_ascii=False)}}]})
    def reply(self, status, data):
        raw = json.dumps(data, ensure_ascii=False).encode()
        self.send_response(status); self.send_header('Content-Type', 'application/json'); self.send_header('Content-Length', str(len(raw))); self.end_headers(); self.wfile.write(raw)

ThreadingHTTPServer(('0.0.0.0', int(sys.argv[1])), Handler).serve_forever()

<?php

declare(strict_types=1);

/** @var array $_ */
?>
<div id="calenai-admin" class="section calenai-admin">
	<h2>CalenAI（AI予定追加）</h2>
	<p class="settings-hint">
		予定の入力文を解析する AI API を設定します。API キーは暗号化して保存され、この画面には表示されません。
	</p>

	<form id="calenai-admin-form" class="calenai-admin__form" autocomplete="off">
		<div class="calenai-admin__row">
			<label for="calenai-provider">プロバイダ</label>
			<select id="calenai-provider">
				<option value="openai">OpenAI 互換（Chat Completions）</option>
				<option value="anthropic">Anthropic（Claude）</option>
				<option value="azure">Azure OpenAI</option>
			</select>
		</div>

		<div class="calenai-admin__row">
			<label for="calenai-endpoint">エンドポイント URL</label>
			<input type="url" id="calenai-endpoint" spellcheck="false">
			<p class="calenai-admin__help" id="calenai-endpoint-help"></p>
		</div>

		<div class="calenai-admin__row">
			<label for="calenai-model">モデル</label>
			<input type="text" id="calenai-model" spellcheck="false">
			<p class="calenai-admin__help" id="calenai-model-help"></p>
		</div>

		<div class="calenai-admin__row">
			<label for="calenai-apikey">API キー</label>
			<input type="password" id="calenai-apikey" autocomplete="new-password" spellcheck="false">
			<p class="calenai-admin__help" id="calenai-apikey-help"></p>
			<p class="calenai-admin__check" id="calenai-clearkey-row" hidden>
				<input type="checkbox" id="calenai-clearkey" class="checkbox">
				<label for="calenai-clearkey">保存済みのキーを削除する</label>
			</p>
		</div>

		<div class="calenai-admin__row" data-providers="anthropic">
			<label for="calenai-effort">effort（考える深さ）</label>
			<select id="calenai-effort">
				<option value="low">low（既定・速い）</option>
				<option value="medium">medium</option>
				<option value="high">high</option>
				<option value="xhigh">xhigh</option>
				<option value="max">max</option>
				<option value="">送らない（effort に対応していないモデル向け）</option>
			</select>
		</div>

		<div class="calenai-admin__row" data-providers="openai azure">
			<label for="calenai-jsonmode">JSON 出力の強制</label>
			<select id="calenai-jsonmode">
				<option value="schema">スキーマで強制（json_schema・推奨）</option>
				<option value="object">JSON モード（json_object）</option>
				<option value="none">強制しない（対応していないサーバー向け）</option>
			</select>
		</div>

		<div class="calenai-admin__row">
			<label for="calenai-candidates">「最近の予定」を AI に送るタイミング</label>
			<select id="calenai-candidates">
				<option value="always">毎回送る（既定・判定が正確）</option>
				<option value="on_demand">修正・削除のときだけ送る（送るデータが最小）</option>
			</select>
			<p class="calenai-admin__help">
				送るのは、このアプリで登録した最近の予定（最大20件）の件名と日時だけです。
				「修正・削除のときだけ」にすると、修正・削除では AI を2回呼び出すため少し遅くなり、
				「歯医者を明後日に変更」のような言い方が新しい予定として登録されやすくなります。
			</p>
		</div>

		<div class="calenai-admin__row">
			<p class="calenai-admin__check">
				<input type="checkbox" id="calenai-allowlocal" class="checkbox">
				<label for="calenai-allowlocal">ローカルアドレスへの接続を許可する（社内の推論サーバー向け。localhost や 192.168.x.x などローカル・プライベートの宛先には http:// も使えるようになります）</label>
			</p>
		</div>

		<div class="calenai-admin__actions">
			<button type="submit" id="calenai-save" class="primary">保存</button>
			<button type="button" id="calenai-test">保存して接続テスト</button>
			<span id="calenai-admin-status" class="calenai-admin__status" role="status"></span>
		</div>
	</form>

	<div class="calenai-admin__notice">
		<h3>データの扱いについて</h3>
		<ul>
			<li>AI に送るのは、入力文、現在日時、タイムゾーン、最近の予定の件名・日時です。ユーザー ID、予定の場所・説明は送りません。入力文や予定の内容は Nextcloud のログに残しません。</li>
			<li>利用者が「予定の内容からカレンダーを自動で選ぶ」を使っている場合（カレンダーが2つ以上あるときの既定）は、その利用者のカレンダーの名前と、利用者が書いた振り分けの説明も送ります。利用者は「AI予定追加」のページの「振り分けの設定」でこれを止められます。</li>
			<li>ZDR（ゼロデータ保持）は、AI の提供元との契約・設定が別に必要です。このアプリの設定だけでは有効になりません。</li>
			<li>Anthropic の Claude Fable / Mythos 系のモデルは、30日間のデータ保持が必須のため ZDR では使えません。ZDR で使う場合は Claude Opus 系などを選んでください。</li>
			<li>Anthropic に直接つなぐ場合、AI が安全上の理由で応答を見送ったときは、Anthropic 側で推奨の別モデルに自動で切り替えて再実行します（その分は切り替え先モデルの料金になります）。</li>
		</ul>
	</div>
</div>

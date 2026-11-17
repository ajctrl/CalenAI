/**
 * CalenAI — 管理画面（AI API の設定）。ビルド不要の素の JavaScript。
 * API キーはサーバーから返ってこない。空のまま保存すると、保存済みのキーが保たれる。
 * ただし送信先（スキーム・ホスト・ポート）を変えるときは、サーバーがキーの再入力を求める。
 */
(function() {
	'use strict'

	const form = document.getElementById('calenai-admin-form')
	if (!form) {
		return
	}

	const $ = function(id) {
		return document.getElementById(id)
	}
	const provider = $('calenai-provider')
	const endpoint = $('calenai-endpoint')
	const endpointHelp = $('calenai-endpoint-help')
	const model = $('calenai-model')
	const modelHelp = $('calenai-model-help')
	const apiKey = $('calenai-apikey')
	const apiKeyHelp = $('calenai-apikey-help')
	const clearKeyRow = $('calenai-clearkey-row')
	const clearKey = $('calenai-clearkey')
	const effort = $('calenai-effort')
	const jsonMode = $('calenai-jsonmode')
	const candidates = $('calenai-candidates')
	const allowLocal = $('calenai-allowlocal')
	const saveButton = $('calenai-save')
	const testButton = $('calenai-test')
	const status = $('calenai-admin-status')

	let defaults = { endpoints: {}, models: {} }
	/** 保存済みの設定（キーの送信先が変わるかを調べるのに使う） */
	let saved = { provider: '', endpoint: '', hasApiKey: false }

	/** 実際に使う URL の「スキーム://ホスト:ポート」。読み取れなければ空 */
	function originOf(providerName, customEndpoint) {
		try {
			return new URL(customEndpoint || defaults.endpoints[providerName] || '').origin
		} catch (e) {
			return ''
		}
	}

	/** キー欄の案内。送信先を変えようとしているときは、再入力が必要なことを先に伝える */
	function refreshKeyHelp() {
		if (!saved.hasApiKey) {
			apiKey.placeholder = ''
			apiKeyHelp.textContent = 'キーはまだ設定されていません。'
			return
		}
		const moved = originOf(provider.value, endpoint.value.trim()) !== originOf(saved.provider, saved.endpoint)
		apiKey.placeholder = moved ? '新しい送信先のキーを入力' : '設定済み（変更するときだけ入力）'
		apiKeyHelp.textContent = moved
			? '送信先が変わるので、API キーを入力し直してください。保存済みのキーは、別の送信先には送りません。'
			: 'キーは保存済みです。空のまま保存すると、今のキーをそのまま使います。'
	}

	// 通信は js/api.js（「AI予定追加」の画面と共通）
	function api(method, path, body) {
		return window.CalenAIApi.request(method, '/admin' + path, body)
	}

	function setStatus(text, kind) {
		status.textContent = text
		status.className = 'calenai-admin__status' + (kind ? ' calenai-admin__status--' + kind : '')
	}

	function setBusy(isBusy) {
		saveButton.disabled = isBusy
		testButton.disabled = isBusy
	}

	/** プロバイダに合わせて、説明文と表示する項目を切り替える */
	function refreshProvider() {
		const current = provider.value
		const defaultEndpoint = defaults.endpoints[current] || ''
		const defaultModel = defaults.models[current] || ''

		endpoint.placeholder = defaultEndpoint
		endpointHelp.textContent = current === 'azure'
			? 'デプロイの完全な URL を入力してください（例: https://＜リソース名＞.openai.azure.com/openai/deployments/＜デプロイ名＞/chat/completions?api-version=…）。'
			: '空のままなら既定の URL（' + defaultEndpoint + '）を使います。互換サーバーやプロキシを使うときだけ入力してください。'

		model.placeholder = defaultModel
		if (current === 'azure') {
			modelHelp.textContent = 'デプロイ URL でモデルが決まる場合は空のままで構いません。'
		} else if (current === 'openai') {
			modelHelp.textContent = '空のままなら既定のモデル（' + defaultModel + '）を使います。互換サーバーを使うときは、そのサーバーのモデル ID を入力してください。'
		} else {
			modelHelp.textContent = '空のままなら既定のモデル（' + defaultModel + '）を使います。'
		}

		form.querySelectorAll('[data-providers]').forEach(function(row) {
			row.hidden = row.dataset.providers.split(' ').indexOf(current) === -1
		})
		refreshKeyHelp()
	}

	function fill(settings) {
		defaults = settings.defaults || defaults
		provider.value = settings.provider
		endpoint.value = settings.endpoint
		model.value = settings.model
		effort.value = settings.effort
		jsonMode.value = settings.jsonMode
		candidates.value = settings.candidateMode
		allowLocal.checked = !!settings.allowLocal
		apiKey.value = ''
		clearKey.checked = false
		clearKeyRow.hidden = !settings.hasApiKey
		saved = { provider: settings.provider, endpoint: settings.endpoint, hasApiKey: !!settings.hasApiKey }
		refreshProvider()
	}

	async function save() {
		const settings = await api('PUT', '/settings', {
			provider: provider.value,
			endpoint: endpoint.value.trim(),
			model: model.value.trim(),
			apiKey: apiKey.value === '' ? null : apiKey.value,
			clearApiKey: clearKey.checked,
			effort: effort.value,
			jsonMode: jsonMode.value,
			allowLocal: allowLocal.checked,
			candidateMode: candidates.value,
		})
		fill(settings)
	}

	provider.addEventListener('change', refreshProvider)
	endpoint.addEventListener('input', refreshKeyHelp)

	form.addEventListener('submit', async function(e) {
		e.preventDefault()
		setBusy(true)
		setStatus('保存しています…')
		try {
			await save()
			setStatus('保存しました。', 'ok')
		} catch (err) {
			setStatus(err.message, 'error')
		} finally {
			setBusy(false)
		}
	})

	testButton.addEventListener('click', async function() {
		setBusy(true)
		setStatus('保存して、AI API に接続しています…')
		try {
			await save()
			const result = await api('POST', '/test')
			setStatus(result.message, result.ok ? 'ok' : 'error')
		} catch (err) {
			setStatus(err.message, 'error')
		} finally {
			setBusy(false)
		}
	})

	setBusy(true)
	api('GET', '/settings').then(function(settings) {
		fill(settings)
		setBusy(false)
	}, function(err) {
		setStatus('設定を読み込めませんでした。' + err.message, 'error')
	})
})()

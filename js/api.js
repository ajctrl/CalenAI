/**
 * CalenAI — サーバーとの通信。「AI予定追加」の画面（main.js）と管理画面（admin.js）が共通で使う。
 * どちらのスクリプトよりも先に読み込むこと。
 */
(function() {
	'use strict'

	class ApiError extends Error {

		constructor(message, status, data) {
			super(message)
			this.status = status
			this.data = data || {}
		}

	}

	function defaultMessage(status) {
		if (status === 401) {
			return 'ログインの有効期限が切れました。ページを再読み込みしてください。'
		}
		if (status === 412) {
			return 'ページの有効期限が切れました。再読み込みしてからもう一度お試しください。'
		}
		if (status === 429) {
			return '操作が多すぎます。少し待ってからお試しください。'
		}
		return '処理中にエラーが起きました。もう一度お試しください。'
	}

	/**
	 * @param {string} method HTTP メソッド
	 * @param {string} path /apps/calenai/api からのパス
	 * @param {object} [body] JSON で送る内容
	 * @return {Promise<object>} 応答の JSON。失敗したら ApiError（status と、応答の JSON を data に持つ）
	 */
	async function request(method, path, body) {
		const options = {
			method,
			credentials: 'same-origin',
			headers: {
				Accept: 'application/json',
				requesttoken: OC.requestToken,
			},
		}
		if (body !== undefined) {
			options.headers['Content-Type'] = 'application/json'
			options.body = JSON.stringify(body)
		}

		let response
		try {
			response = await fetch(OC.generateUrl('/apps/calenai/api' + path), options)
		} catch (e) {
			throw new ApiError('サーバーに接続できませんでした。通信状態を確かめてください。', 0)
		}

		let data = null
		try {
			data = await response.json()
		} catch (e) {
			data = null
		}
		if (!response.ok || data === null) {
			const message = data && typeof data.message === 'string' && data.message !== ''
				? data.message
				: defaultMessage(response.status)
			throw new ApiError(message, response.status, data)
		}
		return data
	}

	window.CalenAIApi = { request }
})()

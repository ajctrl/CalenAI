/**
 * CalenAI — AI予定追加の画面。ビルド不要の素の JavaScript。
 *
 * 入力 → すぐ保存 → 結果カードを表示。カードから［修正］［削除］、修正後は［元に戻す］ができる。
 * 削除だけは必ず確認ダイアログを出し、OK のときにだけ DELETE を送る。
 *
 * 2つの場所で動く:
 *   - 「AI予定追加」のページ（templates/main.php の #calenai の中に画面を作る）
 *   - Calendar アプリのページ（右下にボタンを足し、押すと同じ画面をパネルで開く）
 *
 * 文字はすべて textContent で入れる（innerHTML は使わない）。
 */
(function() {
	'use strict'

	// このページ自身に置き場所（#calenai）があれば通常のページ、なければ Calendar への差し込み。
	const embedded = document.getElementById('calenai') === null
	if (embedded && document.getElementById('calenai-fab') !== null) {
		return
	}

	let fabEl = null
	let panelEl = null
	const root = embedded ? buildFloatingPanel() : document.getElementById('calenai')
	buildInterface(root)

	const logEl = document.getElementById('calenai-log')
	const formEl = document.getElementById('calenai-form')
	const inputEl = document.getElementById('calenai-input')
	const sendEl = document.getElementById('calenai-send')
	const resetEl = document.getElementById('calenai-reset')
	const calendarEl = document.getElementById('calenai-calendar')
	const timezoneEl = document.getElementById('calenai-timezone')

	const WEEKDAYS = ['日', '月', '火', '水', '木', '金', '土']
	const FIELD_LABELS = {
		title: '件名',
		start: '開始',
		end: '終了',
		allDay: '終日',
		location: '場所',
		description: '説明',
		calendar: 'カレンダー',
	}

	const timezone = (function() {
		try {
			return Intl.DateTimeFormat().resolvedOptions().timeZone || ''
		} catch (e) {
			return ''
		}
	})()

	/** 予定 ID → いちばん新しいカード。操作ボタンが付くのはこのカードだけ。 */
	const latestCards = new Map()
	let busy = false
	let canWrite = true
	/** 書き込めるカレンダーの一覧（修正フォームの「カレンダー」と、振り分けの設定で使う） */
	let calendars = []
	let autoCalendar = true
	let dialogOpen = false

	// ---------------------------------------------------------------- 通信

	// 通信は js/api.js（管理画面と共通）。失敗すると、status と応答の JSON（data）を持つエラーが投げられる。
	const api = window.CalenAIApi.request

	// ---------------------------------------------------------------- 表示の部品

	function el(tag, className, text) {
		const node = document.createElement(tag)
		if (className) {
			node.className = className
		}
		if (text !== undefined && text !== null) {
			node.textContent = text
		}
		return node
	}

	function button(label, className, onClick) {
		const node = el('button', className, label)
		node.type = 'button'
		node.addEventListener('click', onClick)
		return node
	}

	function parseLocal(value) {
		const m = /^(\d{4})-(\d{2})-(\d{2})(?:[T ](\d{2}):(\d{2}))?/.exec(value || '')
		if (!m) {
			return null
		}
		return {
			year: Number(m[1]),
			month: Number(m[2]),
			day: Number(m[3]),
			hasTime: m[4] !== undefined,
			hour: m[4] === undefined ? 0 : Number(m[4]),
			minute: m[5] === undefined ? 0 : Number(m[5]),
		}
	}

	function dateLabel(p) {
		const weekday = WEEKDAYS[new Date(p.year, p.month - 1, p.day).getDay()]
		const year = p.year === new Date().getFullYear() ? '' : p.year + '年'
		return year + p.month + '月' + p.day + '日（' + weekday + '）'
	}

	function timeLabel(p) {
		return p.hour + ':' + String(p.minute).padStart(2, '0')
	}

	function sameDay(a, b) {
		return a.year === b.year && a.month === b.month && a.day === b.day
	}

	function whenLabel(event) {
		const start = parseLocal(event.start)
		const end = parseLocal(event.end)
		if (!start || !end) {
			return ''
		}
		if (event.allDay) {
			return sameDay(start, end)
				? dateLabel(start) + ' 終日'
				: dateLabel(start) + ' 〜 ' + dateLabel(end) + ' 終日'
		}
		return sameDay(start, end)
			? dateLabel(start) + ' ' + timeLabel(start) + '〜' + timeLabel(end)
			: dateLabel(start) + ' ' + timeLabel(start) + ' 〜 ' + dateLabel(end) + ' ' + timeLabel(end)
	}

	function changeValueLabel(field, value) {
		if (field === 'allDay') {
			return value ? 'あり' : 'なし'
		}
		if (field === 'start' || field === 'end') {
			const p = parseLocal(value)
			if (!p) {
				return '（なし）'
			}
			return p.hasTime ? dateLabel(p) + ' ' + timeLabel(p) : dateLabel(p)
		}
		return value === '' || value === null || value === undefined ? '（なし）' : String(value)
	}

	function scrollToBottom() {
		logEl.scrollTop = logEl.scrollHeight
	}

	let conversation = []
	let historyRequest = 0
	let eventVersion = 0
	let recentEntry = null
	let effectiveTimezone = timezone || 'UTC'
	const MAX_HISTORY_LENGTH = 12000

	/** 利用者の入力を右側に出す */
	function addUserEntry(text) {
		const instant = new Date()
		let localTime
		try {
			localTime = instant.toLocaleString('sv-SE', { timeZone: effectiveTimezone })
		} catch (err) {
			// The server may support a timezone name that the browser does not recognize.
			localTime = instant.toISOString()
		}
		conversation.push({ role: 'user', content: '[' + localTime + ' ' + effectiveTimezone + ']\n' + text })
		const entry = el('div', 'calenai-entry calenai-entry--user')
		entry.appendChild(el('div', 'calenai-bubble', text))
		logEl.appendChild(entry)
		scrollToBottom()
		return entry
	}

	/** アプリからの返事を左側に出す。カードや差分はこの中に足す。 */
	function addSystemEntry(text, kind) {
		const entry = el('div', 'calenai-entry calenai-entry--system' + (kind ? ' calenai-entry--' + kind : ''))
		if (text) {
			entry.appendChild(el('div', 'calenai-bubble', text))
		}
		logEl.appendChild(entry)
		scrollToBottom()
		return entry
	}

	function changesBlock(changes) {
		const list = el('ul', 'calenai-changes')
		changes.forEach(function(change) {
			const item = el('li')
			item.appendChild(el('span', 'calenai-changes__field', (FIELD_LABELS[change.field] || change.field) + ': '))
			item.appendChild(el('span', 'calenai-changes__from', changeValueLabel(change.field, change.from)))
			item.appendChild(document.createTextNode(' → '))
			item.appendChild(el('span', 'calenai-changes__to', changeValueLabel(change.field, change.to)))
			list.appendChild(item)
		})
		return list
	}

	// ---------------------------------------------------------------- 画面の組み立て

	/** 入力欄・やりとりの欄・設定の欄を root の中に作る */
	function buildInterface(container) {
		const inner = el('div', 'calenai__inner')

		const header = el('header', 'calenai__header')
		header.appendChild(el('h2', 'calenai__title', 'AI予定追加'))
		// 設定は一度決めれば変えないものなので、Calendar に差し込む小さなパネルでは見せない
		// （「AI予定追加」のページで変えられる。設定は共通）
		const settings = el('div', 'calenai__settings')
		settings.hidden = embedded
		settings.appendChild(settingSelect('保存先', 'calenai-calendar', '保存先のカレンダー'))
		settings.appendChild(settingSelect('タイムゾーン', 'calenai-timezone', '予定の日時を解釈するタイムゾーン'))
		const routingToggle = button('振り分けの設定', 'calenai__routing-toggle', toggleRouting)
		routingToggle.id = 'calenai-routing-toggle'
		routingToggle.hidden = true
		routingToggle.setAttribute('aria-expanded', 'false')
		routingToggle.setAttribute('aria-controls', 'calenai-routing')
		settings.appendChild(routingToggle)
		header.appendChild(settings)
		const reset = button('会話をリセット', null, resetConversation)
		reset.id = 'calenai-reset'
		reset.title = '次の入力から新しい会話を始めます'
		header.appendChild(reset)
		if (embedded) {
			const close = button('×', 'calenai__close', closePanel)
			close.title = '閉じる'
			close.setAttribute('aria-label', '閉じる')
			header.appendChild(close)
		}
		inner.appendChild(header)

		const routing = el('section', 'calenai__routing')
		routing.id = 'calenai-routing'
		routing.hidden = true
		inner.appendChild(routing)

		const log = el('div', 'calenai__log')
		log.id = 'calenai-log'
		log.setAttribute('role', 'log')
		log.setAttribute('aria-live', 'polite')
		inner.appendChild(log)

		const form = el('form', 'calenai__form')
		form.id = 'calenai-form'
		form.autocomplete = 'off'
		const input = el('textarea', 'calenai__input')
		input.id = 'calenai-input'
		input.rows = 2
		input.maxLength = 1000
		input.placeholder = '例: 来週火曜15時から1時間 田中さんと打合せ @会議室A'
		input.setAttribute('aria-label', '予定の内容')
		const send = el('button', 'primary calenai__send', '送信')
		send.id = 'calenai-send'
		send.type = 'submit'
		form.appendChild(input)
		form.appendChild(send)
		inner.appendChild(form)

		inner.appendChild(el('p', 'calenai__hint', 'Enter で送信、Shift+Enter で改行。入力するとすぐにカレンダーへ保存されます。間違っていたら「いや16時だった」「場所は会議室B」のように続けて入力すると直せます。'))
		container.appendChild(inner)
	}

	function settingSelect(labelText, id, ariaLabel) {
		const label = el('label', 'calenai__calendar')
		const caption = el('span', null, labelText)
		caption.id = id + '-label'
		label.appendChild(caption)
		const select = el('select')
		select.id = id
		select.disabled = true
		select.setAttribute('aria-label', ariaLabel)
		label.appendChild(select)
		return label
	}

	// ---------------------------------------------------------------- Calendar への差し込み

	/** 右下のボタンと、押すと開くパネルを作る。パネルの中身を入れる要素を返す。 */
	function buildFloatingPanel() {
		fabEl = button('AI', 'calenai-fab', togglePanel)
		fabEl.id = 'calenai-fab'
		fabEl.title = 'AIで予定を追加'
		fabEl.setAttribute('aria-label', 'AIで予定を追加')
		fabEl.setAttribute('aria-expanded', 'false')
		fabEl.setAttribute('aria-controls', 'calenai-panel')

		panelEl = el('div', 'calenai-panel')
		panelEl.id = 'calenai-panel'
		panelEl.hidden = true
		panelEl.setAttribute('role', 'dialog')
		panelEl.setAttribute('aria-label', 'AI予定追加')

		const container = el('div', 'calenai calenai--embedded')
		container.id = 'calenai'
		panelEl.appendChild(container)

		// パネルの中でのキー入力が Calendar のショートカット（c で新規作成など）に届かないようにする
		panelEl.addEventListener('keydown', function(e) {
			if (e.key === 'Escape' && !e.isComposing && !dialogOpen) {
				closePanel()
			}
			e.stopPropagation()
		})
		panelEl.addEventListener('keyup', function(e) {
			e.stopPropagation()
		})
		panelEl.addEventListener('keypress', function(e) {
			e.stopPropagation()
		})

		document.body.appendChild(panelEl)
		document.body.appendChild(fabEl)
		watchHostSidebar()
		return container
	}

	/**
	 * Calendar で予定を開くと、右側に編集用のサイドバーが出る。その保存ボタンなどに重ならないよう、
	 * サイドバーが開いている間はボタンとパネルをその幅だけ左へよける。
	 * よける余地がないほど狭い画面（スマホ）では、サイドバーが閉じるまで隠す。
	 */
	function watchHostSidebar() {
		const host = document.getElementById('content')
		if (!host || typeof MutationObserver !== 'function') {
			return
		}
		let scheduled = false
		const update = function() {
			scheduled = false
			const sidebar = document.querySelector('#app-sidebar-vue, aside.app-sidebar')
			const width = sidebar && sidebar.getClientRects().length > 0 ? sidebar.getBoundingClientRect().width : 0
			const noRoom = width > 0 && window.innerWidth - width < 460
			fabEl.classList.toggle('calenai-fab--hidden', noRoom)
			panelEl.classList.toggle('calenai-panel--hidden', noRoom)
			const offset = noRoom ? '0px' : Math.round(width) + 'px'
			fabEl.style.setProperty('--calenai-offset', offset)
			panelEl.style.setProperty('--calenai-offset', offset)
		}
		const schedule = function() {
			if (!scheduled) {
				scheduled = true
				window.requestAnimationFrame(update)
			}
		}
		new MutationObserver(schedule).observe(host, { childList: true, subtree: true, attributes: true, attributeFilter: ['style', 'class'] })
		window.addEventListener('resize', schedule)
		schedule()
	}

	let started = false

	function openPanel() {
		panelEl.hidden = false
		fabEl.classList.add('calenai-fab--open')
		fabEl.setAttribute('aria-expanded', 'true')
		if (!started) {
			// 開かれるまではサーバーに何も問い合わせない
			started = true
			start()
		}
		scrollToBottom()
		inputEl.focus()
	}

	function closePanel() {
		panelEl.hidden = true
		fabEl.classList.remove('calenai-fab--open')
		fabEl.setAttribute('aria-expanded', 'false')
		fabEl.focus()
	}

	function togglePanel() {
		if (panelEl.hidden) {
			openPanel()
		} else {
			closePanel()
		}
	}

	/**
	 * Calendar の予定表を、ページを読み直さずに最新にする。
	 *
	 * Calendar には外から「取り直して」と伝える公式の口がないので、Calendar 自身が
	 * 60秒ごとの確認で使っている更新処理（ストアの syncCalendar）を直接呼ぶ。
	 * Calendar の作りが変わって呼べなくなっても何もしない: その場合でも Calendar が
	 * 自分で最大60秒以内に反映する。
	 *
	 * @return {boolean} 呼べたら true
	 */
	function refreshHostCalendar() {
		try {
			const host = document.getElementById('content')
			const app = host && host.__vue_app__
			const pinia = app && app.config && app.config.globalProperties && app.config.globalProperties.$pinia
			const store = pinia && pinia._s && typeof pinia._s.get === 'function' ? pinia._s.get('calendars') : null
			if (!store || typeof store.syncCalendar !== 'function' || !Array.isArray(store.calendars)) {
				return false
			}
			store.calendars.forEach(function(calendar) {
				store.syncCalendar({ calendar })
			})
			return true
		} catch (e) {
			return false
		}
	}

	let slowRefreshNoticed = false

	/** 予定を作成・修正・削除した後に呼ぶ */
	function eventsChanged() {
		eventVersion++
		if (!embedded || refreshHostCalendar() || slowRefreshNoticed) {
			return
		}
		slowRefreshNoticed = true
		addSystemEntry('後ろの予定表には、1分ほどで反映されます。')
	}

	// ---------------------------------------------------------------- 予定カード

	/**
	 * 予定カードを作る。同じ予定の古いカードは操作できない表示（その時点の記録）に変える。
	 */
	function createCard(event) {
		const previous = latestCards.get(event.id)
		if (previous) {
			freezeCard(previous)
		}
		const card = el('div', 'calenai-card')
		card.calenaiEvent = event
		latestCards.set(event.id, card)
		renderCardView(card)
		return card
	}

	function freezeCard(card) {
		card.classList.add('calenai-card--old')
		card.querySelectorAll('.calenai-card__actions, .calenai-card__form').forEach(function(node) {
			node.remove()
		})
		if (!card.querySelector('.calenai-card__body')) {
			// 編集中だったカードは内容表示に戻す
			renderCardBody(card)
		}
	}

	function markCardDeleted(id, note) {
		const card = latestCards.get(id)
		if (!card) {
			return
		}
		latestCards.delete(id)
		freezeCard(card)
		card.classList.add('calenai-card--deleted')
		card.appendChild(el('div', 'calenai-card__note', note))
	}

	function renderCardBody(card) {
		const event = card.calenaiEvent
		card.textContent = ''
		const body = el('div', 'calenai-card__body')
		body.appendChild(el('div', 'calenai-card__title', event.title || '（無題）'))
		body.appendChild(el('div', 'calenai-card__when', whenLabel(event)))
		if (event.location) {
			const location = el('div', 'calenai-card__meta')
			location.appendChild(el('span', 'calenai-card__meta-label', '場所'))
			location.appendChild(el('span', null, event.location))
			body.appendChild(location)
		}
		if (event.description) {
			const description = el('div', 'calenai-card__meta calenai-card__meta--multiline')
			description.appendChild(el('span', 'calenai-card__meta-label', '説明'))
			description.appendChild(el('span', null, event.description))
			body.appendChild(description)
		}
		if (event.calendarName) {
			body.appendChild(el('div', 'calenai-card__calendar', 'カレンダー: ' + event.calendarName))
		}
		card.appendChild(body)
	}

	function renderCardView(card) {
		const event = card.calenaiEvent
		renderCardBody(card)

		if (event.hasAttendees) {
			// 出席者への通知はこのアプリからは送れないので、修正・削除は Calendar アプリに任せる
			card.appendChild(el('div', 'calenai-card__note', '出席者がいる予定です。変更・削除は Calendar アプリで行ってください（出席者に通知するため）。'))
			return
		}

		const actions = el('div', 'calenai-card__actions')
		actions.appendChild(button('修正', null, function() {
			renderCardForm(card)
		}))
		actions.appendChild(button('削除', 'calenai-button--danger', function() {
			confirmDelete(card.calenaiEvent)
		}))
		if (event.canUndo) {
			actions.appendChild(button('元に戻す', null, function() {
				undoEvent(card)
			}))
		}
		card.appendChild(actions)
	}

	function field(labelText, control) {
		const row = el('label', 'calenai-field')
		row.appendChild(el('span', 'calenai-field__label', labelText))
		row.appendChild(control)
		return row
	}

	function renderCardForm(card) {
		const event = card.calenaiEvent
		card.textContent = ''

		const form = el('form', 'calenai-card__form')
		form.autocomplete = 'off'

		const title = el('input')
		title.type = 'text'
		title.maxLength = 255
		title.required = true
		title.value = event.title

		const allDay = el('input', 'checkbox')
		allDay.type = 'checkbox'
		allDay.checked = !!event.allDay
		allDay.id = 'calenai-allday-' + event.id

		const start = el('input')
		const end = el('input')
		start.required = true
		end.required = true

		// 終日と時刻ありを行き来しても、入れた値を失わないように覚えておく
		const remembered = {
			timed: event.allDay
				? { start: event.start + 'T09:00', end: event.end + 'T10:00' }
				: { start: event.start, end: event.end },
			dates: {
				start: event.start.slice(0, 10),
				end: event.end.slice(0, 10),
			},
		}

		function applyAllDay() {
			const type = allDay.checked ? 'date' : 'datetime-local'
			start.type = type
			end.type = type
			const values = allDay.checked ? remembered.dates : remembered.timed
			start.value = values.start
			end.value = values.end
		}

		function remember() {
			const target = allDay.checked ? remembered.dates : remembered.timed
			target.start = start.value
			target.end = end.value
		}
		start.addEventListener('change', remember)
		end.addEventListener('change', remember)
		allDay.addEventListener('change', applyAllDay)
		applyAllDay()

		const location = el('input')
		location.type = 'text'
		location.maxLength = 255
		location.value = event.location || ''

		const description = el('textarea')
		description.rows = 2
		description.maxLength = 2000
		description.value = event.description || ''

		const allDayRow = el('div', 'calenai-field calenai-field--check')
		allDayRow.appendChild(allDay)
		const allDayLabel = el('label', null, '終日')
		allDayLabel.htmlFor = allDay.id
		allDayRow.appendChild(allDayLabel)

		const error = el('div', 'calenai-card__error')
		error.hidden = true

		const save = el('button', 'primary', '保存')
		save.type = 'submit'
		const cancel = button('キャンセル', null, function() {
			renderCardView(card)
		})
		const buttons = el('div', 'calenai-card__actions')
		buttons.appendChild(save)
		buttons.appendChild(cancel)

		form.appendChild(field('件名', title))
		form.appendChild(allDayRow)
		form.appendChild(field('開始', start))
		form.appendChild(field('終了', end))
		form.appendChild(field('場所', location))
		form.appendChild(field('説明', description))

		// 移せる先があるときだけ、別のカレンダーへ移せるようにする。
		// 今のカレンダーが一覧に無い（ごみ箱にある、後から作られた等）ときも、選択肢に入れて「移さない」を選べるようにする。
		let calendarSelect = null
		const choices = calendars.slice()
		if (!choices.some(function(calendar) { return calendar.id === event.calendarId })) {
			choices.unshift({ id: event.calendarId, name: event.calendarName || '（今のカレンダー）' })
		}
		if (choices.length > 1) {
			calendarSelect = el('select')
			choices.forEach(function(calendar) {
				const option = el('option', null, calendar.name)
				option.value = String(calendar.id)
				calendarSelect.appendChild(option)
			})
			calendarSelect.value = String(event.calendarId)
			form.appendChild(field('カレンダー', calendarSelect))
		}

		// 開いた時点の値。変えた項目だけを送る（触っていない項目をサーバーで整形し直さないため）
		const formValues = function() {
			return {
				title: title.value,
				start: start.value,
				end: end.value,
				allDay: allDay.checked,
				location: location.value,
				description: description.value,
			}
		}
		const initial = formValues()

		form.appendChild(error)
		form.appendChild(buttons)

		form.addEventListener('submit', async function(e) {
			e.preventDefault()
			if (!start.value || !end.value) {
				error.textContent = '開始と終了を入力してください。'
				error.hidden = false
				return
			}
			const history = conversation
			save.disabled = true
			cancel.disabled = true
			error.hidden = true
			try {
				const current = formValues()
				const changed = {}
				;['title', 'location', 'description'].forEach(function(key) {
					if (current[key] !== initial[key]) {
						changed[key] = current[key]
					}
				})
				// 日時は組で送る（片方だけ送ると、サーバーが元の長さを保とうとしてもう片方をずらすため）
				if (current.start !== initial.start || current.end !== initial.end || current.allDay !== initial.allDay) {
					changed.start = current.start
					changed.end = current.end
					changed.allDay = current.allDay
				}
				if (calendarSelect !== null && calendarSelect.value !== '' && Number(calendarSelect.value) !== event.calendarId) {
					changed.calendarId = Number(calendarSelect.value)
				}
				if (Object.keys(changed).length === 0) {
					renderCardView(card)
					return
				}
				const result = await api('PUT', '/events/' + event.id, {
					event: changed,
					displayTimezone: event.displayTimezone,
					etag: event.etag,
					revision: event.revision,
					timezone,
				})
				if (isPreviousConversation(history, true)) {
					return
				}
				recordCardAction(history, '修正後の予定', result.event)
				if (result.changes.length === 0) {
					card.calenaiEvent = result.event
					renderCardView(card)
					return
				}
				const entry = addSystemEntry('予定を修正しました。')
				entry.appendChild(changesBlock(result.changes))
				entry.appendChild(createCard(result.event))
				eventsChanged()
				scrollToBottom()
			} catch (err) {
				if (handleStaleEvent(card, err, history)) {
					return
				}
				save.disabled = false
				cancel.disabled = false
				error.textContent = err.message
				error.hidden = false
			}
		})

		card.appendChild(form)
		title.focus()
		// フォームは縦に長いので、保存ボタンまで見えるように寄せる
		card.scrollIntoView({ block: 'nearest' })
	}

	/**
	 * 予定が外部で消された（404）・変更された（409）ときの共通処理。
	 * @return {boolean} ここで処理したら true
	 */
	function handleStaleEvent(card, err, history, id = card.calenaiEvent.id) {
		if (isPreviousConversation(history, true) || showPartialResult(err, history)) {
			return true
		}
		if (err.status === 404) {
			markCardDeleted(id, 'この予定はカレンダーにありません')
			addSystemEntry(err.message, 'error')
			return true
		}
		if (err.status === 409 && err.data && err.data.event) {
			const entry = addSystemEntry(err.message, 'error')
			if (card) {
				card.calenaiEvent = err.data.event
				renderCardView(card)
			} else {
				entry.appendChild(createCard(err.data.event))
			}
			return true
		}
		return false
	}

	/**
	 * 内容は保存できたが、カレンダーの移動だけ失敗したとき。保存後の予定をカードに出して、画面を実物に合わせる。
	 * @return {boolean} ここで処理したら true
	 */
	function showPartialResult(err, history) {
		if (!err.data || err.data.partial !== true || !err.data.event) {
			return false
		}
		recordCardAction(history, '一部を保存した後の予定', err.data.event)
		const entry = addSystemEntry(err.message, 'error')
		entry.appendChild(createCard(err.data.event))
		eventsChanged()
		scrollToBottom()
		return true
	}

	async function undoEvent(card) {
		const history = conversation
		const event = card.calenaiEvent
		setCardBusy(card, true)
		try {
			const result = await api('POST', '/events/' + event.id + '/undo', { timezone, revision: event.revision })
			if (isPreviousConversation(history, true)) {
				return
			}
			recordCardAction(history, '修正を取り消した後の予定', result.event)
			const entry = addSystemEntry('直前の修正を元に戻しました。')
			if (result.changes.length > 0) {
				entry.appendChild(changesBlock(result.changes))
			}
			entry.appendChild(createCard(result.event))
			eventsChanged()
			scrollToBottom()
		} catch (err) {
			setCardBusy(card, false)
			if (!handleStaleEvent(card, err, history)) {
				addSystemEntry(err.message, 'error')
			}
		}
	}

	function setCardBusy(card, isBusy) {
		card.querySelectorAll('button').forEach(function(node) {
			node.disabled = isBusy
		})
	}

	// ---------------------------------------------------------------- 削除（必ず確認する）

	function askDestructive(text, title) {
		return new Promise(function(resolve) {
			// 開く前からある role="dialog"（埋め込み時の CalenAI パネル自身など）は待つ対象にしない
			const existingDialogs = new Set(document.querySelectorAll('[role="dialog"]'))
			const confirmDialogOpen = function() {
				return Array.prototype.some.call(document.querySelectorAll('[role="dialog"]'), function(node) {
					return !existingDialogs.has(node)
				})
			}
			dialogOpen = true
			const finish = function(answer) {
				dialogOpen = false
				resolve(answer === true)
			}

			if (!(window.OC && OC.dialogs && typeof OC.dialogs.confirmDestructive === 'function')) {
				finish(window.confirm(text))
				return
			}

			// OC.dialogs はボタンを押した後にもう一度 false で呼ぶことがあるので、最初の答えだけ採用する。
			// Esc などで閉じられて一度も呼ばれなかったときは「キャンセル」として扱う。
			let answer = null
			const shown = OC.dialogs.confirmDestructive(
				text,
				title,
				{
					type: OC.dialogs.YES_NO_BUTTONS,
					confirm: '削除する',
					confirmClasses: 'error',
					cancel: 'キャンセル',
				},
				function(value) {
					if (answer === null) {
						answer = value === true
					}
				},
				true,
			)

			// ダイアログが画面から消え終わってから先へ進む（最大1秒待つ）。閉じる途中で
			// カードのボタンを無効にしたり消したりすると、ダイアログ側がフォーカスを戻す先を見失うため。
			const afterClose = function() {
				const deadline = Date.now() + 1000
				const waitUntilGone = function() {
					if (!confirmDialogOpen() || Date.now() > deadline) {
						finish(answer === true)
					} else {
						window.setTimeout(waitUntilGone, 50)
					}
				}
				waitUntilGone()
			}
			if (shown && typeof shown.then === 'function') {
				shown.then(afterClose, afterClose)
			} else {
				afterClose()
			}
		})
	}

	async function confirmDelete(event, history = conversation) {
		const card = latestCards.get(event.id)
		const text = '「' + (event.title || '（無題）') + '」（' + whenLabel(event) + '）を削除します。よろしいですか？'
		const confirmed = await askDestructive(text, '予定の削除')
		if (isPreviousConversation(history, false)) {
			return
		}
		if (!confirmed) {
			recordCardAction(history, '削除をキャンセルした予定', event)
			addSystemEntry('削除をやめました。予定は残っています。')
			inputEl.focus()
			return
		}

		if (card) {
			setCardBusy(card, true)
		}
		try {
			const query = new URLSearchParams({ revision: event.revision, timezone })
			await api('DELETE', '/events/' + event.id + '?' + query)
			markCardDeleted(event.id, '削除しました')
			if (isPreviousConversation(history, true)) {
				return
			}
			recordCardAction(history, '削除した予定', event)
			addSystemEntry('「' + (event.title || '（無題）') + '」を削除しました。')
			eventsChanged()
		} catch (err) {
			if (card) {
				setCardBusy(card, false)
			}
			if (!handleStaleEvent(latestCards.get(event.id), err, history, event.id)) {
				addSystemEntry(err.message, 'error')
			}
		}
		inputEl.focus()
	}

	// ---------------------------------------------------------------- 入力の送信

	function resetConversation() {
		conversation = []
		addSystemEntry('ここから新しい会話です。')
		inputEl.focus()
	}

	function setBusy(isBusy) {
		busy = isBusy
		resetEl.disabled = isBusy
		sendEl.disabled = isBusy || !canWrite
		inputEl.disabled = !canWrite
		sendEl.textContent = isBusy ? '解析中…' : '送信'
	}

	function eventSummary(event) {
		return event.title + ' | ' + event.start + ' 〜 ' + event.end + (event.allDay ? '（終日）' : '')
	}

	// Ignore replies belonging to an earlier conversation. Reload committed changes
	// using the active timezone rather than rendering the old response's event.
	function isPreviousConversation(history, refresh) {
		if (history === conversation) {
			return false
		}
		if (refresh) {
			eventsChanged()
			loadHistory()
		}
		return true
	}

	function recordCardAction(history, action, event) {
		history.push({ role: 'user', source: 'card', content: '[カード操作結果] ' + action + '：' + eventSummary(event) })
	}

	function showResult(result, history) {
		const turn = { role: 'assistant', content: '[' + result.status + '] ' + result.reply }
		if (result.usedCandidates) {
			turn.source = 'candidates'
		}
		if (result.status === 'created' || result.status === 'updated') {
			turn.savedEvent = '\n保存した予定: ' + eventSummary(result.event)
		}
		history.push(turn)
		const entry = addSystemEntry(result.reply, result.status === 'unclear' ? 'question' : null)
		switch (result.status) {
		case 'created':
			entry.appendChild(createCard(result.event))
			eventsChanged()
			break
		case 'updated':
			if (result.changes && result.changes.length > 0) {
				entry.appendChild(changesBlock(result.changes))
			}
			entry.appendChild(createCard(result.event))
			eventsChanged()
			break
		case 'confirm_delete':
			entry.appendChild(createCard(result.event))
			scrollToBottom()
			confirmDelete(result.event, history)
			break
		case 'ambiguous':
			(result.candidates || []).forEach(function(candidate) {
				entry.appendChild(createCard(candidate))
			})
			break
		}
		scrollToBottom()
	}

	async function submitText() {
		const text = inputEl.value.trim()
		if (text === '' || busy || !canWrite) {
			return
		}
		const historyLength = conversation.reduce(function(length, turn) {
			return length + Array.from(turn.content).length + Array.from(turn.savedEvent || '').length
		}, 0)
		if (historyLength > MAX_HISTORY_LENGTH) {
			addSystemEntry('会話履歴が12,000文字を超えました。「会話をリセット」を押してから再送してください。', 'question')
			resetEl.focus()
			return
		}
		const activeConversation = conversation
		const history = activeConversation.slice()
		inputEl.value = ''
		addUserEntry(text)
		const pending = addSystemEntry('解析しています…', 'pending')
		setBusy(true)
		try {
			const result = await api('POST', '/message', { text, timezone, history })
			pending.remove()
			if (isPreviousConversation(activeConversation, result.status === 'created' || result.status === 'updated')) {
				return
			}
			showResult(result, activeConversation)
		} catch (err) {
			pending.remove()
			if (isPreviousConversation(activeConversation, true)) {
				return
			}
			activeConversation.push({ role: 'assistant', content: err.message })
			if (showPartialResult(err, activeConversation)) {
				return
			}
			const latest = err.data && err.data.event
			if (!latest || !handleStaleEvent(latestCards.get(latest.id), err, activeConversation, latest.id)) {
				addSystemEntry(err.message, 'error')
			}
			// 失敗したときは打ち直さなくて済むように入力を戻す
			if (inputEl.value === '') {
				inputEl.value = text
			}
		} finally {
			setBusy(false)
			if (!dialogOpen) {
				inputEl.focus()
			}
		}
	}

	formEl.addEventListener('submit', function(e) {
		e.preventDefault()
		submitText()
	})

	inputEl.addEventListener('keydown', function(e) {
		// 日本語入力の変換確定の Enter では送信しない
		if (e.key === 'Enter' && !e.shiftKey && !e.isComposing && e.keyCode !== 229) {
			e.preventDefault()
			submitText()
		}
	})

	// ---------------------------------------------------------------- 起動時の読み込み

	function timezoneNames() {
		try {
			if (typeof Intl.supportedValuesOf === 'function') {
				return Array.from(new Set(['UTC', ...Intl.supportedValuesOf('timeZone')]))
			}
		} catch (e) {
			// 下の短い一覧を使う
		}
		return ['Asia/Tokyo', 'Asia/Seoul', 'Asia/Shanghai', 'Asia/Singapore', 'Asia/Kolkata', 'Europe/London', 'Europe/Paris', 'Europe/Berlin', 'America/New_York', 'America/Chicago', 'America/Los_Angeles', 'Australia/Sydney', 'Pacific/Auckland', 'UTC']
	}

	/** タイムゾーンの選択欄を、サーバーが返した状態に合わせる */
	function renderTimezone(info) {
		effectiveTimezone = info.effective
		timezoneEl.textContent = ''
		const auto = el('option', null, info.setting === '' ? '自動（' + info.effective + '）' : '自動')
		auto.value = ''
		timezoneEl.appendChild(auto)

		const names = timezoneNames()
		if (info.setting !== '' && names.indexOf(info.setting) === -1) {
			names.unshift(info.setting)
		}
		names.forEach(function(name) {
			const option = el('option', null, name)
			option.value = name
			timezoneEl.appendChild(option)
		})
		timezoneEl.value = info.setting
		timezoneEl.disabled = false
	}

	/**
	 * ブラウザのプライバシー保護機能（Firefox の指紋採取防止など）は、本当のタイムゾーンの代わりに
	 * UTC や Atlantic/Reykjavik を報告する。そのまま使うと「明日」の日付や時刻がずれるので知らせる。
	 */
	function warnAboutMaskedTimezone(info) {
		if (!info.browserMasked || info.setting !== '') {
			return
		}
		const reported = 'ブラウザがタイムゾーンを「' + info.browser + '」と報告しています。プライバシー保護機能で実際と違う値に置き換えられているようです。'
		const where = embedded ? '「AI予定追加」のページの右上にある「タイムゾーン」' : '右上の「タイムゾーン」'
		if (info.source === 'browser') {
			addSystemEntry(reported + 'このままだと予定の日付や時刻がずれます。' + where + 'でお住まいの地域（日本なら Asia/Tokyo）を選んでください。', 'warning')
		} else {
			addSystemEntry(reported + '代わりに「' + info.effective + '」で日時を解釈します。違う場合は' + where + 'で選んでください。', 'warning')
		}
	}

	// ---------------------------------------------------------------- カレンダーの自動振り分け

	function toggleRouting() {
		const section = document.getElementById('calenai-routing')
		section.hidden = !section.hidden
		document.getElementById('calenai-routing-toggle').setAttribute('aria-expanded', section.hidden ? 'false' : 'true')
	}

	/**
	 * 「振り分けの設定」の欄を作る。カレンダーが1つだけなら振り分ける先がないので出さない。
	 */
	function renderRouting(data) {
		calendars = data.calendars
		autoCalendar = !!data.autoCalendar && calendars.length > 1

		// 自動で選ぶときの「保存先」は、どれにも当てはまらないときの行き先になる
		document.getElementById('calenai-calendar-label').textContent = autoCalendar ? '当てはまらないとき' : '保存先'
		document.getElementById('calenai-routing-toggle').hidden = calendars.length < 2

		const section = document.getElementById('calenai-routing')
		section.textContent = ''
		if (calendars.length < 2) {
			section.hidden = true
			return
		}

		const auto = el('input', 'checkbox')
		auto.type = 'checkbox'
		auto.id = 'calenai-routing-auto'
		auto.checked = !!data.autoCalendar
		const autoRow = el('div', 'calenai-field calenai-field--check')
		autoRow.appendChild(auto)
		const autoLabel = el('label', null, '予定の内容からカレンダーを自動で選ぶ')
		autoLabel.htmlFor = auto.id
		autoRow.appendChild(autoLabel)
		section.appendChild(autoRow)

		section.appendChild(el('p', 'calenai__routing-help', 'カレンダーごとに「ここに入れるもの」を書いておくと、内容に合うカレンダーへ入ります。書かなくても、カレンダーの名前から判断します。どれにも当てはまらない予定は、上の「当てはまらないとき」のカレンダーに入ります。入力文に「仕事のカレンダーに」と書けば、いつでもそのカレンダーに入ります。'))

		const inputs = []
		const list = el('div', 'calenai__routing-list')
		calendars.forEach(function(calendar) {
			const input = el('input')
			input.type = 'text'
			input.maxLength = 200
			input.value = calendar.hint || ''
			input.placeholder = '例: 会議、打合せ、出張、締め切り'
			input.dataset.calendarId = String(calendar.id)
			inputs.push(input)
			list.appendChild(field(calendar.name, input))
		})
		section.appendChild(list)

		section.appendChild(el('p', 'calenai__routing-help', '自動で選ぶ設定のときは、カレンダーの名前とここに書いた説明が、入力文と一緒に AI に送られます。自動で選ばない設定にすると送られず、予定はすべて「保存先」のカレンダーに入ります。'))

		const applyEnabled = function() {
			inputs.forEach(function(input) {
				input.disabled = !auto.checked
			})
		}
		auto.addEventListener('change', applyEnabled)
		applyEnabled()

		const status = el('span', 'calenai__routing-status')
		status.setAttribute('role', 'status')
		const save = button('保存', 'primary', async function() {
			save.disabled = true
			status.textContent = '保存しています…'
			const hints = {}
			inputs.forEach(function(input) {
				hints[input.dataset.calendarId] = input.value
			})
			try {
				const saved = await api('PUT', '/settings', { autoCalendar: auto.checked, hints, timezone })
				renderRouting(saved)
				document.getElementById('calenai-routing').querySelector('.calenai__routing-status').textContent = '保存しました。'
			} catch (err) {
				status.textContent = err.message
				save.disabled = false
			}
		})
		const actions = el('div', 'calenai-card__actions')
		actions.appendChild(save)
		actions.appendChild(status)
		section.appendChild(actions)
	}

	async function loadSettings() {
		try {
			const data = await api('GET', '/calendars?timezone=' + encodeURIComponent(timezone))
			calendarEl.textContent = ''
			data.calendars.forEach(function(calendar) {
				const option = el('option', null, calendar.name)
				option.value = String(calendar.id)
				option.selected = calendar.id === data.defaultCalendarId
				calendarEl.appendChild(option)
			})
			canWrite = data.calendars.length > 0
			calendarEl.disabled = !canWrite
			if (!canWrite) {
				calendarEl.appendChild(el('option', null, '（カレンダーなし）'))
				addSystemEntry('書き込めるカレンダーがありません。カレンダーアプリで自分のカレンダーを作成してからお使いください。', 'error')
			}
			renderRouting(data)
			renderTimezone(data.timezone)
			warnAboutMaskedTimezone(data.timezone)
		} catch (err) {
			addSystemEntry('設定を読み込めませんでした。' + err.message, 'error')
		}
		setBusy(false)
	}

	calendarEl.addEventListener('change', async function() {
		const calendarId = Number(calendarEl.value)
		const name = calendarEl.options[calendarEl.selectedIndex].textContent
		try {
			await api('PUT', '/settings', { calendarId, timezone })
			addSystemEntry('これからの予定は「' + name + '」に保存します。')
		} catch (err) {
			addSystemEntry(err.message, 'error')
			loadSettings()
		}
	})

	timezoneEl.addEventListener('change', async function() {
		timezoneEl.disabled = true
		try {
			const data = await api('PUT', '/settings', { timezoneSetting: timezoneEl.value, timezone })
			renderTimezone(data.timezone)
			// 表示中のカードは前のタイムゾーンの時刻なので、読み直す
			logEl.textContent = ''
			conversation = []
			latestCards.clear()
			addSystemEntry('これからは「' + data.timezone.effective + '」の時刻として日時を解釈・表示します。')
			warnAboutMaskedTimezone(data.timezone)
			await loadHistory()
		} catch (err) {
			addSystemEntry(err.message, 'error')
			loadSettings()
		}
	})

	async function loadHistory() {
		const request = ++historyRequest
		const version = eventVersion
		try {
			const data = await api('GET', '/history?timezone=' + encodeURIComponent(timezone))
			if (request !== historyRequest) {
				return
			}
			if (version !== eventVersion) {
				return loadHistory()
			}
			if (recentEntry) {
				for (const [id, card] of latestCards) {
					if (recentEntry.contains(card)) {
						latestCards.delete(id)
					}
				}
				recentEntry.remove()
			}
			if (data.events.length === 0) {
				recentEntry = addSystemEntry('予定の内容を文章で入力してください。例:「明日10時から歯医者」「来週火曜15時から1時間 田中さんと打合せ @会議室A」')
				return
			}
			recentEntry = addSystemEntry('最近このアプリで登録した予定です。')
			// 新しいものが入力欄の近く（下）に来るように、古い順に並べる
			data.events.slice().reverse().forEach(function(event) {
				recentEntry.appendChild(createCard(event))
			})
			scrollToBottom()
		} catch (err) {
			if (request === historyRequest) {
				addSystemEntry('最近の予定を読み込めませんでした。' + err.message, 'error')
			}
		}
	}

	function start() {
		setBusy(true)
		loadSettings().then(loadHistory)
	}

	if (!embedded) {
		start()
		inputEl.focus()
	}
})()

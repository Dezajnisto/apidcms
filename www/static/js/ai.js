/**
 * AI Assistant для CMS
 * Универсальный интерфейс для работы с DeepSeek в админке
 * v2 — с сохранением истории чата в sessionStorage
 */

var AIAssistant = {
    // Состояние
    modal: null,
    currentContext: {},
    messageHistory: [],
    
    // Ключ для sessionStorage (null = не сохранять, для контекстных чатов)
    storageKey: 'ai_chat_global',

    /**
     * Инициализация AI Assistant
     */
    init: function(options) {
        var hasSpecificMode = options && options.mode;
        
        if (hasSpecificMode) {
            // Контекстный чат (fill-form, template, table, content) — всегда с чистого листа
            // и не сохраняется между страницами
            this.clearSessionState();
            this.storageKey = null;
        } else {
            // Сквозной (глобальный) чат — сохраняется в sessionStorage
            this.storageKey = 'ai_chat_global';
        }
        
        this.currentContext = options || {};
        this.injectModal();
        this.injectStyles();
        
        // Восстанавливаем историю для глобального чата
        if (this.storageKey) {
            this.restoreMessages();
        }
    },

    /**
     * Сохранить сообщения в sessionStorage
     */
    saveMessages: function() {
        if (!this.storageKey) return;
        if (!this.modal || !this.modal.messages) return;
        try {
            var messages = [];
            var children = this.modal.messages.children;
            for (var i = 0; i < children.length; i++) {
                var el = children[i];
                if (el.id === 'ai-typing-indicator') continue;
                var bubble = el.querySelector('.ai-msg-bubble');
                if (bubble) {
                    messages.push({
                        role: el.classList.contains('user') ? 'user' : 'assistant',
                        html: bubble.innerHTML
                    });
                }
            }
            sessionStorage.setItem(this.storageKey, JSON.stringify(messages));
        } catch(e) {
            // sessionStorage недоступен или переполнен — игнорируем
        }
    },

    /**
     * Восстановить сообщения из sessionStorage
     */
    restoreMessages: function() {
        if (!this.storageKey) return;
        try {
            var saved = sessionStorage.getItem(this.storageKey);
            if (!saved) return;
            var messages = JSON.parse(saved);
            if (!messages || messages.length === 0) return;
            
            // Очищаем стандартное приветствие
            this.modal.messages.innerHTML = '';
            
            for (var i = 0; i < messages.length; i++) {
                var msg = messages[i];
                var div = document.createElement('div');
                div.className = 'ai-message ' + msg.role;
                var bubble = document.createElement('div');
                bubble.className = 'ai-msg-bubble';
                bubble.innerHTML = msg.html;
                div.appendChild(bubble);
                this.modal.messages.appendChild(div);
            }
            this.modal.messages.scrollTop = this.modal.messages.scrollHeight;
        } catch(e) {
            // Данные повреждены — показываем чистый чат
        }
    },

    /**
     * Очистить сохранённое состояние
     */
    clearSessionState: function() {
        try {
            sessionStorage.removeItem('ai_chat_global');
        } catch(e) {}
    },

    /**
     * Очистить чат
     */
    clearChat: function() {
        this.clearMessages();
        this.clearSessionState();
        var div = document.createElement('div');
        div.className = 'ai-message assistant';
        div.innerHTML = '<div class="ai-msg-bubble">Чат очищен. Чем могу помочь?</div>';
        this.modal.messages.appendChild(div);
    },

    /**
     * Удалить все сообщения из чата
     */
    clearMessages: function() {
        if (!this.modal) return;
        this.modal.messages.innerHTML = '';
    },

    /**
     * Заполнить поля формы сгенерированными значениями
     */
    fillFormFields: function(valuesJson) {
        var values = JSON.parse(valuesJson);
        var found = 0;
        var skipped = 0;
        for (var fieldName in values) {
            if (values.hasOwnProperty(fieldName)) {
                var input = document.querySelector('[name="' + fieldName + '"]');
                if (input) {
                    // Не затираем уже заполненные поля
                    if (input.value.trim() !== '') {
                        skipped++;
                        continue;
                    }
                    input.value = values[fieldName];
                    found++;
                    var event = new Event('input', { bubbles: true });
                    input.dispatchEvent(event);
                }
            }
        }
        var msg = '✅ Заполнено ' + found + ' полей';
        if (skipped > 0) {
            msg += ', пропущено ' + skipped + ' (уже заполнены)';
        }
        if (found + skipped < Object.keys(values).length) {
            msg += ' (из ' + Object.keys(values).length + ' — некоторые поля не найдены)';
        }
        this.addMessage(msg, 'assistant');
        this.close();
    },

    /**
     * Собрать текущие значения полей формы
     */
    collectFormValues: function() {
        var values = {};
        var inputs = document.querySelectorAll('form input, form textarea, form select');
        inputs.forEach(function(input) {
            if (input.name && input.name !== 'csrf_token' && input.type !== 'hidden') {
                values[input.name] = input.value;
            }
        });
        return values;
    },

    injectStyles: function() {
        if (document.getElementById('ai-styles')) return;
        var style = document.createElement('style');
        style.id = 'ai-styles';
        style.textContent = [
            '.ai-overlay { position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 9998; display: none; }',
            '.ai-overlay.active { display: block; }',
            '.ai-modal { position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); width: 700px; max-width: 90vw; height: 80vh; background: white; border-radius: 12px; box-shadow: 0 20px 60px rgba(0,0,0,0.3); z-index: 9999; display: none; flex-direction: column; overflow: hidden; }',
            '.ai-modal.active { display: flex; }',
            '.ai-modal-header { padding: 16px 20px; border-bottom: 1px solid #e5e7eb; display: flex; justify-content: space-between; align-items: center; background: #f9fafb; }',
            '.ai-modal-header h3 { margin: 0; font-size: 16px; font-weight: 600; color: #111827; }',
            '.ai-modal-close { background: none; border: none; font-size: 24px; cursor: pointer; color: #9ca3af; padding: 4px 8px; border-radius: 4px; }',
            '.ai-modal-close:hover { background: #e5e7eb; color: #374151; }',
            '.ai-modal-body { flex: 1; overflow-y: auto; padding: 20px; background: #f9fafb; }',
            '.ai-message { margin-bottom: 16px; display: flex; }',
            '.ai-message.user { justify-content: flex-end; }',
            '.ai-message.user .ai-msg-bubble { background: #3b82f6; color: white; border-bottom-right-radius: 4px; }',
            '.ai-message.assistant .ai-msg-bubble { background: white; border: 1px solid #e5e7eb; border-bottom-left-radius: 4px; }',
            '.ai-msg-bubble { max-width: 80%; padding: 12px 16px; border-radius: 12px; font-size: 14px; line-height: 1.5; white-space: pre-wrap; word-break: break-word; }',
            '.ai-msg-bubble code { background: rgba(0,0,0,0.08); padding: 2px 6px; border-radius: 3px; font-size: 13px; font-family: monospace; }',
            '.ai-msg-bubble pre { background: #1f2937; color: #f3f4f6; padding: 12px; border-radius: 8px; overflow-x: auto; font-size: 13px; margin: 8px 0; }',
            '.ai-msg-bubble pre code { background: transparent; padding: 0; color: inherit; }',
            '.ai-msg-bubble .ai-copy-btn { float: right; background: rgba(255,255,255,0.1); border: 1px solid rgba(255,255,255,0.2); color: #d1d5db; padding: 2px 8px; border-radius: 4px; cursor: pointer; font-size: 12px; }',
            '.ai-msg-bubble .ai-copy-btn:hover { background: rgba(255,255,255,0.2); }',
            '.ai-modal-footer { padding: 16px 20px; border-top: 1px solid #e5e7eb; background: white; }',
            '.ai-input-wrap { display: flex; gap: 8px; }',
            '.ai-input-wrap textarea { flex: 1; border: 1px solid #d1d5db; border-radius: 8px; padding: 10px 14px; font-size: 14px; resize: none; outline: none; font-family: inherit; min-height: 44px; max-height: 120px; }',
            '.ai-input-wrap textarea:focus { border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59,130,246,0.1); }',
            '.ai-send-btn { background: #3b82f6; color: white; border: none; border-radius: 8px; padding: 10px 18px; cursor: pointer; font-size: 14px; font-weight: 500; white-space: nowrap; }',
            '.ai-send-btn:hover { background: #2563eb; }',
            '.ai-send-btn:disabled { opacity: 0.5; cursor: not-allowed; }',
            '.ai-typing { padding: 12px 16px; background: white; border: 1px solid #e5e7eb; border-radius: 12px; border-bottom-left-radius: 4px; display: inline-flex; gap: 4px; }',
            '.ai-typing span { width: 8px; height: 8px; background: #d1d5db; border-radius: 50%; animation: ai-bounce 1.4s infinite both; }',
            '.ai-typing span:nth-child(2) { animation-delay: 0.16s; }',
            '.ai-typing span:nth-child(3) { animation-delay: 0.32s; }',
            '@keyframes ai-bounce { 0%,80%,100% { transform: scale(0.6); } 40% { transform: scale(1); } }',
            '.ai-btn { display: inline-flex; align-items: center; gap: 6px; padding: 6px 14px; background: linear-gradient(135deg, #6366f1, #8b5cf6); color: white; border: none; border-radius: 8px; cursor: pointer; font-size: 13px; font-weight: 500; transition: all 0.2s; }',
            '.ai-btn:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(99,102,241,0.4); }',
            '.ai-btn svg { width: 16px; height: 16px; }',
            '.ai-btn-sm { padding: 4px 10px; font-size: 12px; }',
            '.ai-btn-green { background: linear-gradient(135deg, #10b981, #059669); }',
            '.ai-btn-green:hover { box-shadow: 0 4px 12px rgba(16,185,129,0.4); }',
            '.ai-toolbar { display: flex; gap: 8px; margin-bottom: 12px; flex-wrap: wrap; }',
            '.ai-msg-bubble .ai-use-btn { display: inline-block; margin-top: 8px; padding: 6px 14px; background: #10b981; color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 13px; font-weight: 500; }',
            '.ai-msg-bubble .ai-use-btn:hover { background: #059669; }',
            '.ai-msg-bubble .ai-use-btn.ai-use-code { background: #6366f1; margin-left: 8px; }',
            '.ai-msg-bubble .ai-use-btn.ai-use-code:hover { background: #4f46e5; }',
            '.ai-error { color: #ef4444; background: #fef2f2; border: 1px solid #fecaca; padding: 10px 14px; border-radius: 8px; font-size: 14px; margin-bottom: 12px; }'
        ].join('\n');
        document.head.appendChild(style);
    },

    /**
     * Внедрить HTML модалки
     */
    injectModal: function() {
        if (document.getElementById('ai-modal-overlay')) return;

        var overlay = document.createElement('div');
        overlay.id = 'ai-modal-overlay';
        overlay.className = 'ai-overlay';
        overlay.onclick = function(e) {
            if (e.target === overlay) AIAssistant.close();
        };
        document.body.appendChild(overlay);

        var modal = document.createElement('div');
        modal.id = 'ai-modal';
        modal.className = 'ai-modal';
        modal.innerHTML = [
            '<div class="ai-modal-header">',
            '  <h3><svg style="width:18px;height:18px;display:inline;vertical-align:middle;margin-right:6px" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/></svg> AI Ассистент</h3>',
            '  <div class="flex items-center space-x-2">',
            '    <button class="ai-modal-close" onclick="AIAssistant.clearChat()" style="background:none;border:1px solid #d1d5db;border-radius:6px;color:#6b7280;cursor:pointer;font-size:13px;padding:4px 10px;"><i class="fas fa-eraser"></i> Очистить</button>',
            '    <button class="ai-modal-close" onclick="AIAssistant.close()">&times;</button>',
            '  </div>',
            '</div>',
            '<div class="ai-modal-body" id="ai-messages">',
            '  <div class="ai-message assistant"><div class="ai-msg-bubble">Привет! Я AI-ассистент. Могу помочь с шаблонами, таблицами, контентом. Что будем делать?</div></div>',
            '</div>',
            '<div class="ai-modal-footer">',
            '  <div class="ai-input-wrap">',
            '    <textarea id="ai-input" rows="1" placeholder="Напишите запрос..." oninput="this.style.height=\'auto\';this.style.height=Math.min(this.scrollHeight,120)+\'px\'" onkeydown="if(event.key==\'Enter\'&&!event.shiftKey){event.preventDefault();AIAssistant.send()}"></textarea>',
            '    <button class="ai-send-btn" id="ai-send-btn" onclick="AIAssistant.send()">Отправить</button>',
            '  </div>',
            '</div>'
        ].join('\n');
        document.body.appendChild(modal);

        this.modal = {
            overlay: overlay,
            modal: modal,
            messages: document.getElementById('ai-messages'),
            input: document.getElementById('ai-input'),
            sendBtn: document.getElementById('ai-send-btn')
        };
    },

    /**
     * Открыть модалку
     */
    open: function() {
        this.modal.overlay.classList.add('active');
        this.modal.modal.classList.add('active');
        setTimeout(function() {
            AIAssistant.modal.input.focus();
        }, 300);
    },

    /**
     * Закрыть модалку
     */
    close: function() {
        this.modal.overlay.classList.remove('active');
        this.modal.modal.classList.remove('active');
    },

    /**
     * Отправить сообщение AI
     */
    send: function() {
        var input = this.modal.input;
        var text = input.value.trim();
        if (!text) return;

        // Добавляем сообщение пользователя
        this.addMessage(text, 'user');
        input.value = '';
        input.style.height = 'auto';

        // Показываем индикатор печатания
        this.showTyping();

        // Отключаем кнопку
        this.modal.sendBtn.disabled = true;

        var self = this;

        // Определяем эндпоинт в зависимости от контекста
        var endpoint = '/admin/ai/assistant';
        var payload = {
            message: text,
            current_page: window.location.pathname
        };

        // Если есть контекст генерации шаблона
        if (this.currentContext.mode === 'template') {
            endpoint = '/admin/ai/generate-template';
            payload.prompt = text;
            payload.existing_content = this.currentContext.existingContent || '';
            payload.page_type = this.currentContext.pageType || '';
        } else if (this.currentContext.mode === 'table') {
            endpoint = '/admin/ai/generate-table';
            payload.prompt = text;
        } else if (this.currentContext.mode === 'content') {
            endpoint = '/admin/ai/generate-content';
            payload.prompt = text;
            payload.table = this.currentContext.tableName || '';
            payload.count = this.currentContext.count || 5;
        } else if (this.currentContext.mode === 'fill-form') {
            endpoint = '/admin/ai/fill-form';
            payload.prompt = text;
            payload.table = this.currentContext.tableName || '';
            payload.structure = this.currentContext.structure || [];
            payload.existing_values = this.currentContext.existingValues || {};
        }

        fetch(endpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            self.hideTyping();
            self.modal.sendBtn.disabled = false;

            if (data.error) {
                self.addMessage('❌ ' + data.error, 'assistant');
                return;
            }

            var response = data.response || data.template || '';

            // Форматируем ответ
            var formatted = self.formatResponse(response, data);
            self.addMessage(formatted, 'assistant');

            // Обновляем контекст для итеративной работы
            if (self.currentContext.mode === 'template' && data.template) {
                self.currentContext.existingContent = data.template;
            }
            if (self.currentContext.mode === 'fill-form' && data.values) {
                self.currentContext.existingValues = data.values;
            }
        })
        .catch(function(err) {
            self.hideTyping();
            self.modal.sendBtn.disabled = false;
            self.addMessage('❌ Ошибка соединения: ' + err.message, 'assistant');
        });
    },

    /**
     * Форматирование ответа (код -> pre, markdown)
     */
    formatResponse: function(text, data) {
        var html = '';

        // Проверяем, есть ли template в ответе (для режима template)
        if (this.currentContext.mode === 'template' && data.template) {
            html += '<div class="ai-toolbar">';
            html += '<button class="ai-use-btn ai-use-code" onclick="AIAssistant.useTemplate(\'' + this.escapeJs(data.template) + '\')">📋 Вставить в редактор</button>';
            html += '</div>';
        }

        // Проверяем, есть ли columns (для режима table)
        if (this.currentContext.mode === 'table' && data.columns && Array.isArray(data.columns)) {
            html += '<div class="ai-toolbar">';
            html += '<button class="ai-use-btn ai-use-code" onclick="AIAssistant.useTable(\'' + this.escapeJs(JSON.stringify(data.columns)) + '\')">📋 Применить структуру</button>';
            html += '</div>';
        }

        // Проверяем, есть ли records (для режима content)
        if (this.currentContext.mode === 'content' && data.records && Array.isArray(data.records)) {
            html += '<div class="ai-toolbar">';
            html += '<button class="ai-use-btn ai-use-code" onclick="AIAssistant.insertRecords(\'' + this.escapeJs(JSON.stringify(data.records)) + '\')">💾 Вставить ' + data.records.length + ' записей</button>';
            html += '</div>';
        }

        if (this.currentContext.mode === 'fill-form' && data.values) {
            html += '<div class="ai-toolbar">';
            html += '<button class="ai-use-btn" onclick="AIAssistant.fillFormFields(\'' + this.escapeJs(JSON.stringify(data.values)) + '\')">📋 Заполнить форму</button>';
            html += '</div>';
        }

        // Форматируем сам ответ (код в ``` обрамлении)
        var parts = text.split(/(```[\s\S]*?```)/g);
        for (var i = 0; i < parts.length; i++) {
            var part = parts[i];
            if (part.startsWith('```')) {
                var code = part.replace(/```\w*\n?/, '').replace(/```$/, '');
                var lang = part.match(/^```(\w*)/);
                lang = lang && lang[1] ? lang[1] : '';
                html += '<pre><code>' + this.escapeHtml(code) + '</code></pre>';
            } else {
                html += '<p style="margin:0 0 4px 0">' + this.escapeHtml(part).replace(/\n/g, '<br>') + '</p>';
            }
        }

        return html || '<p style="margin:0">' + this.escapeHtml(text).replace(/\n/g, '<br>') + '</p>';
    },

    /**
     * Использовать сгенерированный шаблон
     */
    useTemplate: function(template) {
        var textarea = document.getElementById(this.currentContext.textareaId || 'templateContent');
        if (textarea) {
            textarea.value = template;
            this.addMessage('✅ Шаблон вставлен в редактор! Не забудьте сохранить.', 'assistant');
        }
        this.close();
    },

    /**
     * Использовать структуру таблицы
     */
    useTable: function(columnsJson) {
        var columns = JSON.parse(columnsJson);
        var container = document.querySelector('[data-columns-container]');
        if (!container) {
            this.addMessage('⚠️ Не могу найти форму создания таблицы на этой странице. Откройте страницу создания таблицы и попробуйте снова.', 'assistant');
            return;
        }

        for (var i = 0; i < Math.max(columns.length, 2); i++) {
            // Логика добавления строк — зависит от реализации формы
        }

        this.addMessage('📋 Найдено ' + columns.length + ' колонок. Заполните форму вручную по этим данным:\n\n' + columnsJson, 'assistant');
    },

    /**
     * Вставить записи в таблицу
     */
    insertRecords: function(recordsJson) {
        var self = this;
        var records = JSON.parse(recordsJson);
        var table = this.currentContext.tableName || '';

        if (!table) {
            this.addMessage('⚠️ Не указана таблица для вставки', 'assistant');
            return;
        }

        fetch('/admin/ai/insert-content', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ table: table, records: records })
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                self.addMessage('✅ Успешно вставлено ' + data.inserted + ' из ' + data.total + ' записей!', 'assistant');
                if (data.errors && data.errors.length > 0) {
                    self.addMessage('⚠️ Ошибки: ' + data.errors.join('; '), 'assistant');
                }
            } else {
                self.addMessage('❌ ' + (data.error || 'Ошибка вставки'), 'assistant');
            }
        })
        .catch(function(err) {
            self.addMessage('❌ Ошибка: ' + err.message, 'assistant');
        });
    },

    /**
     * Добавить сообщение в чат
     * Для role='user' — экранирует HTML, чтобы код не рендерился
     * Для role='assistant' — вставляет как есть (уже отформатировано)
     */
    addMessage: function(content, role) {
        var div = document.createElement('div');
        div.className = 'ai-message ' + role;
        var bubble = document.createElement('div');
        bubble.className = 'ai-msg-bubble';
        
        // Пользовательские сообщения экранируем, чтобы Twig/HTML не рендерился
        if (role === 'user') {
            bubble.textContent = content;
        } else {
            bubble.innerHTML = content;
        }
        
        div.appendChild(bubble);
        this.modal.messages.appendChild(div);
        this.modal.messages.scrollTop = this.modal.messages.scrollHeight;
        
        // Сохраняем состояние после каждого сообщения
        this.saveMessages();
    },

    /**
     * Показать индикатор печатания
     */
    showTyping: function() {
        var div = document.createElement('div');
        div.className = 'ai-message assistant';
        div.id = 'ai-typing-indicator';
        div.innerHTML = '<div class="ai-typing"><span></span><span></span><span></span></div>';
        this.modal.messages.appendChild(div);
        this.modal.messages.scrollTop = this.modal.messages.scrollHeight;
    },

    /**
     * Скрыть индикатор печатания
     */
    hideTyping: function() {
        var el = document.getElementById('ai-typing-indicator');
        if (el) el.remove();
    },

    /**
     * Экранирование HTML
     */
    escapeHtml: function(text) {
        var div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    },

    /**
     * Экранирование для JS строки
     */
    escapeJs: function(text) {
        return text.replace(/\\/g, '\\\\').replace(/'/g, "\\'").replace(/"/g, '&quot;').replace(/\n/g, '\\n');
    }
};

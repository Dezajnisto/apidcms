/**
 * AI Chat — клиентская логика для ai-страниц
 * Отправляет сообщения на /ai-handler, рендерит ответы и быстрые ссылки
 */
(function() {
    'use strict';

    const chatMessages = document.getElementById('chat-messages');
    const chatForm = document.getElementById('chat-form');
    const chatInput = document.getElementById('chat-input');
    const chatSend = document.getElementById('chat-send');
    const quickLinksContainer = document.getElementById('quick-links');

    if (!chatForm || !chatInput) return;

    // История сообщений (хранится в памяти на время сессии)
    const messageHistory = [];

    /**
     * Добавить сообщение в чат
     */
    function addMessage(role, content, isHtml) {
        const container = document.createElement('div');
        container.className = 'flex gap-3' + (role === 'user' ? ' justify-end' : '');

        if (role === 'assistant') {
            container.innerHTML = `
                <div class="w-8 h-8 rounded-full bg-stone-200 flex items-center justify-center text-sm flex-shrink-0">🤖</div>
                <div class="ai-message bg-stone-50 rounded-2xl rounded-tl-sm px-4 py-3 max-w-[80%]">${isHtml ? content : escapeHtml(content)}</div>
            `;
        } else if (role === 'user') {
            container.innerHTML = `
                <div class="bg-stone-800 text-white rounded-2xl rounded-tr-sm px-4 py-3 max-w-[80%]">${escapeHtml(content)}</div>
            `;
        } else if (role === 'error') {
            container.innerHTML = `
                <div class="w-8 h-8 rounded-full bg-red-100 flex items-center justify-center text-sm flex-shrink-0">⚠️</div>
                <div class="bg-red-50 text-red-700 rounded-2xl rounded-tl-sm px-4 py-3 max-w-[80%] text-sm">${escapeHtml(content)}</div>
            `;
        }

        chatMessages.appendChild(container);
        scrollToBottom();
    }

    /**
     * Показать индикатор печати
     */
    function showTyping() {
        const container = document.createElement('div');
        container.className = 'flex gap-3';
        container.id = 'typing-indicator';
        container.innerHTML = `
            <div class="w-8 h-8 rounded-full bg-stone-200 flex items-center justify-center text-sm flex-shrink-0">🤖</div>
            <div class="bg-stone-50 rounded-2xl rounded-tl-sm px-4 py-3 flex items-center gap-1">
                <span class="typing-dot"></span>
                <span class="typing-dot"></span>
                <span class="typing-dot"></span>
            </div>
        `;
        chatMessages.appendChild(container);
        scrollToBottom();
    }

    /**
     * Убрать индикатор печати
     */
    function hideTyping() {
        const indicator = document.getElementById('typing-indicator');
        if (indicator) indicator.remove();
    }

    /**
     * Показать быстрые ссылки
     */
    function showQuickLinks(links) {
        quickLinksContainer.innerHTML = '';
        if (!links || !links.length) return;

        links.forEach(function(link) {
            const btn = document.createElement('button');
            btn.className = 'quick-link-btn';
            btn.textContent = link.label || link.title || link;
            btn.addEventListener('click', function() {
                chatInput.value = link.label || link.title || link;
                chatForm.dispatchEvent(new Event('submit'));
            });
            quickLinksContainer.appendChild(btn);
        });
    }

    /**
     * Прокрутка вниз
     */
    function scrollToBottom() {
        chatMessages.scrollTop = chatMessages.scrollHeight;
    }

    /**
     * Экранирование HTML
     */
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML.replace(/\n/g, '<br>');
    }

    /**
     * Простой Markdown → HTML (жирный, курсив, ссылки, списки, код)
     */
    function markdownToHtml(text) {
        if (!text) return '';
        
        let html = text
            // Заголовки
            .replace(/^### (.+)$/gm, '<h4 class="text-sm font-semibold mt-2 mb-1">$1</h4>')
            .replace(/^## (.+)$/gm, '<h3 class="text-base font-semibold mt-3 mb-1">$1</h3>')
            .replace(/^# (.+)$/gm, '<h2 class="text-lg font-semibold mt-3 mb-1">$1</h2>')
            // Жирный и курсив
            .replace(/\*\*\*(.+?)\*\*\*/g, '<strong><em>$1</em></strong>')
            .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
            .replace(/\*(.+?)\*/g, '<em>$1</em>')
            // Ссылки
            .replace(/\[([^\]]+)\]\(([^)]+)\)/g, '<a href="$2" target="_blank" rel="noopener">$1</a>')
            // Код
            .replace(/```([\s\S]*?)```/g, '<pre>$1</pre>')
            .replace(/`([^`]+)`/g, '<code>$1</code>')
            // Таблицы (упрощённо)
            .replace(/^\|(.+)\|$/gm, function(match) {
                const cells = match.split('|').filter(function(c) { return c.trim(); });
                if (cells.length === 0) return match;
                const tag = match.includes('---') ? '' : 'td';
                const cellHtml = cells.map(function(c) { return '<' + tag + '>' + c.trim() + '</' + tag + '>'; }).join('');
                return '<tr>' + cellHtml + '</tr>';
            })
            // Горизонтальная черта
            .replace(/^---$/gm, '<hr class="my-3 border-stone-200">')
            // Маркированные списки
            .replace(/^- (.+)$/gm, '<li>$1</li>')
            // Нумерованные списки
            .replace(/^\d+\. (.+)$/gm, '<li>$1</li>')
            // Переносы строк
            .replace(/\n\n/g, '</p><p>')
            .replace(/\n/g, '<br>');

        // Оборачиваем списки
        html = html.replace(/(<li>[\s\S]*?<\/li>)/g, function(match) {
            if (match.indexOf('•') !== -1 || match.indexOf('<br>•') !== -1) return match;
            return '<ul class="list-disc pl-5 my-1">' + match + '</ul>';
        });

        // Убираем двойные обёртки
        html = '<p>' + html + '</p>';
        html = html.replace(/<p><\/p>/g, '');
        html = html.replace(/<p><ul/g, '<ul');
        html = html.replace(/<\/ul><\/p>/g, '</ul>');
        html = html.replace(/<p><ol/g, '<ol');
        html = html.replace(/<\/ol><\/p>/g, '</ol>');
        html = html.replace(/<p><pre/g, '<pre');
        html = html.replace(/<\/pre><\/p>/g, '</pre>');

        return html;
    }

    /**
     * Отправка сообщения
     */
    async function sendMessage(message) {
        if (!message.trim()) return;

        // Показываем сообщение пользователя
        addMessage('user', message);
        messageHistory.push({ role: 'user', content: message });

        // Очищаем поле
        chatInput.value = '';
        chatInput.focus();

        // Показываем индикатор
        showTyping();
        setFormDisabled(true);

        try {
            const response = await fetch('/ai-handler', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    message: message,
                    history: messageHistory.slice(0, -1) // Последнее уже на сервере
                })
            });

            const data = await response.json();
            hideTyping();

            if (data.success) {
                // Конвертируем markdown в HTML
                const html = markdownToHtml(data.response);
                addMessage('assistant', html, true);
                messageHistory.push({ role: 'assistant', content: data.response });

                // Быстрые ссылки
                if (data.quick_links && data.quick_links.length) {
                    showQuickLinks(data.quick_links);
                }
            } else {
                addMessage('error', data.error || 'Произошла ошибка');
            }
        } catch (err) {
            hideTyping();
            addMessage('error', 'Не удалось связаться с сервером. Попробуйте позже.');
        }

        setFormDisabled(false);
    }

    /**
     * Блокировка/разблокировка формы
     */
    function setFormDisabled(disabled) {
        chatInput.disabled = disabled;
        chatSend.disabled = disabled;
        chatSend.textContent = disabled ? '...' : 'Отправить';
    }

    // Обработчик отправки формы
    chatForm.addEventListener('submit', function(e) {
        e.preventDefault();
        sendMessage(chatInput.value);
    });

    // Фокус на поле ввода при загрузке
    chatInput.focus();

    // Отправка по Enter (Shift+Enter — перенос строки)
    chatInput.addEventListener('keydown', function(e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            sendMessage(chatInput.value);
        }
    });
})();

/**
 * AJAX обработчик для форм CMS
 */
class FormAjaxHandler {
    constructor() {
        this.init();
    }

    init() {
        // Обработчик для всех форм с data-table
        document.addEventListener('submit', (e) => {
            const form = e.target;
            if (form.hasAttribute('data-table') && !form.hasAttribute('data-ajax-disabled')) {
                e.preventDefault();
                this.handleFormSubmit(form);
            }
        });

        // Инициализация существующих форм
        this.initializeExistingForms();
    }

    initializeExistingForms() {
        document.querySelectorAll('form[data-table]').forEach(form => {
            if (!form.hasAttribute('data-ajax-initialized')) {
                form.setAttribute('data-ajax-initialized', 'true');
            }
        });
    }

    async handleFormSubmit(form) {
        const submitBtn = form.querySelector('button[type="submit"]');
        const originalText = submitBtn.textContent;
        const formData = new FormData(form);

        // Показываем индикатор загрузки
        this.setLoadingState(submitBtn, true);

        try {
            const response = await fetch(form.action, {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });

            if (response.redirected) {
                // Если редирект (при обычной отправке), переходим
                window.location.href = response.url;
                return;
            }

            const result = await response.json();

            if (result.success) {
                this.showSuccess(form, result.message);
            } else {
                this.showError(form, result.message || 'Ошибка отправки формы');
                this.setLoadingState(submitBtn, false, originalText);
            }

        } catch (error) {
            console.error('Form submission error:', error);
            this.showError(form, 'Ошибка соединения');
            this.setLoadingState(submitBtn, false, originalText);
        }
    }

    setLoadingState(button, isLoading, originalText = null) {
        if (isLoading) {
            button.disabled = true;
            button.setAttribute('data-original-text', button.textContent);
            button.innerHTML = `
                <span class="flex items-center">
                    <svg class="animate-spin -ml-1 mr-2 h-4 w-4 text-white" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    Отправка...
                </span>
            `;
        } else {
            button.disabled = false;
            button.textContent = originalText || button.getAttribute('data-original-text');
        }
    }

    showSuccess(form, message = 'Форма успешно отправлена!') {
        // Сохраняем оригинальное содержимое формы
        const originalForm = form.innerHTML;
        form.setAttribute('data-original-content', originalForm);
        
        // Показываем сообщение об успехе
        form.innerHTML = `
            <div class="bg-green-50 border border-green-200 rounded-lg p-4">
                <div class="flex items-center">
                    <svg class="w-5 h-5 text-green-500 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                    </svg>
                    <span class="text-green-800 font-medium">${message}</span>
                </div>
                <button type="button" onclick="formAjaxHandler.resetForm(this.closest('form'))" 
                        class="mt-3 text-sm text-green-600 hover:text-green-800 underline">
                    Отправить еще раз
                </button>
            </div>
        `;

        // Автоматическое скрытие через 5 секунд
        setTimeout(() => {
            if (form.querySelector('.bg-green-50')) {
                this.resetForm(form);
            }
        }, 5000);
    }

    showError(form, message = 'Ошибка отправки формы') {
        // Показываем ошибку над формой
        const errorDiv = document.createElement('div');
        errorDiv.className = 'mb-4 bg-red-50 border border-red-200 rounded-lg p-4';
        errorDiv.innerHTML = `
            <div class="flex items-center">
                <svg class="w-5 h-5 text-red-500 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
                <span class="text-red-800">${message}</span>
            </div>
        `;

        form.parentNode.insertBefore(errorDiv, form);

        // Автоматическое скрытие ошибки через 5 секунд
        setTimeout(() => {
            if (errorDiv.parentNode) {
                errorDiv.remove();
            }
        }, 5000);
    }

    resetForm(form) {
        const originalContent = form.getAttribute('data-original-content');
        if (originalContent) {
            form.innerHTML = originalContent;
            form.removeAttribute('data-original-content');
        }
        
        // Сбрасываем значения полей
        form.reset();
        
        // Удаляем все сообщения об ошибках
        form.querySelectorAll('.bg-red-50').forEach(el => el.remove());
    }

    // Метод для ручной инициализации конкретной формы
    initForm(formElement) {
        formElement.setAttribute('data-ajax-initialized', 'true');
    }
}

// Инициализация
const formAjaxHandler = new FormAjaxHandler();

// Экспорт для глобального доступа
window.formAjaxHandler = formAjaxHandler;
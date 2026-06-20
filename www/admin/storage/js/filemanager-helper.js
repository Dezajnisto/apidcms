/**
 * Вспомогательные функции для работы с файловым менеджером
 */

class FileManagerHelper {
    /**
     * Открывает файловый менеджер во всплывающем окне
     * @param {string} callback - имя функции обратного вызова
     * @param {object} options - опции
     */
    static openFileManager(callback = 'selectFile', options = {}) {
        const width = options.width || 1000;
        const height = options.height || 700;
        const left = (screen.width - width) / 2;
        const top = (screen.height - height) / 2;
        
        const url = `/admin/filemanager/popup?callback=${callback}`;
        
        window.open(url, 'fileManager', `
            width=${width},
            height=${height},
            left=${left},
            top=${top},
            menubar=no,
            toolbar=no,
            location=no,
            status=no,
            scrollbars=yes,
            resizable=yes
        `);
    }

    /**
     * Стандартная функция обратного вызова для выбора файла
     * @param {object} file - выбранный файл
     */
    static selectFile(file) {
        console.log('Выбран файл:', file);
        // Эта функция будет переопределяться пользователем
        alert(`Выбран файл: ${file.name}\nПуть: ${file.path}`);
    }

    /**
     * Создает поле ввода с кнопкой выбора файла
     * @param {string} inputId - ID поля ввода
     * @param {object} options - опции
     */
    static createFileInput(inputId, options = {}) {
        const input = document.getElementById(inputId);
        if (!input) return;

        // Создаем контейнер
        const container = document.createElement('div');
        container.className = 'flex space-x-2';
        
        // Перемещаем input в контейнер
        input.parentNode.insertBefore(container, input);
        container.appendChild(input);
        
        // Добавляем кнопку
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'px-3 py-2 bg-blue-600 text-white rounded hover:bg-blue-700 text-sm';
        button.innerHTML = '<i class="fas fa-folder-open mr-1"></i> Выбрать файл';
        button.onclick = () => {
            // Сохраняем callback для этого поля
            window.fileInputCallback = (file) => {
                input.value = file.path; // или file.url в зависимости от потребностей
            };
            this.openFileManager('fileInputCallback');
        };
        
        container.appendChild(button);
    }
}

// Глобальные функции для обратной совместимости
window.openFileManager = FileManagerHelper.openFileManager;
window.selectFile = FileManagerHelper.selectFile;
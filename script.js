document.addEventListener('DOMContentLoaded', () => {
    const dropZone = document.getElementById('drop-zone');
    const fileInput = document.getElementById('file-input');
    const uploadLink = document.getElementById('upload-link');
    const uploadPrompt = document.getElementById('upload-prompt');
    const previewContainer = document.getElementById('preview-container');
    const imagePreview = document.getElementById('image-preview');
    const resetBtn = document.getElementById('btn-reset');
    const analyzeBtn = document.getElementById('btn-analyze');
    const btnText = document.getElementById('btn-text');
    const loader = document.getElementById('btn-loader');
    const resultSection = document.getElementById('result-section');
    const statusMessage = document.getElementById('status-message');

    const resName = document.getElementById('plant-name');
    const resDisease = document.getElementById('plant-disease');
    const resAction = document.getElementById('plant-action');

    let imageDataUrl = '';

    const MAX_FILE_SIZE = 12 * 1024 * 1024;
    const MAX_IMAGE_SIDE = 1600;

    function showStatus(message) {
        statusMessage.textContent = message;
        statusMessage.hidden = !message;
    }

    function setLoading(loading) {
        analyzeBtn.disabled = loading || !imageDataUrl;
        loader.hidden = !loading;
        btnText.textContent = loading
            ? 'ИИ изучает фото, подождите...'
            : 'Определить проблему';
    }

    function resetApp() {
        imageDataUrl = '';
        fileInput.value = '';
        imagePreview.removeAttribute('src');
        previewContainer.hidden = true;
        uploadPrompt.hidden = false;
        resultSection.hidden = true;
        showStatus('');
        setLoading(false);
    }

    function resizeImage(file) {
        return new Promise((resolve, reject) => {
            const reader = new FileReader();

            reader.onload = () => {
                const img = new Image();

                img.onload = () => {
                    const scale = Math.min(1, MAX_IMAGE_SIDE / Math.max(img.width, img.height));
                    const width = Math.max(1, Math.round(img.width * scale));
                    const height = Math.max(1, Math.round(img.height * scale));

                    const canvas = document.createElement('canvas');
                    canvas.width = width;
                    canvas.height = height;

                    const ctx = canvas.getContext('2d');
                    if (!ctx) {
                        reject(new Error('Не удалось подготовить изображение.'));
                        return;
                    }

                    ctx.drawImage(img, 0, 0, width, height);

                    // JPEG заметно уменьшает размер запроса и ускоряет анализ.
                    resolve(canvas.toDataURL('image/jpeg', 0.85));
                };

                img.onerror = () => reject(new Error('Не удалось открыть изображение.'));
                img.src = reader.result;
            };

            reader.onerror = () => reject(new Error('Не удалось прочитать файл.'));
            reader.readAsDataURL(file);
        });
    }

    async function handleFile(file) {
        if (!file) return;

        if (!file.type.startsWith('image/')) {
            showStatus('Пожалуйста, выберите фотографию JPG, PNG или WEBP.');
            return;
        }

        if (file.size > MAX_FILE_SIZE) {
            showStatus('Фотография слишком большая. Максимальный размер — 12 МБ.');
            return;
        }

        try {
            showStatus('Подготавливаю фотографию...');
            imageDataUrl = await resizeImage(file);

            imagePreview.src = imageDataUrl;
            previewContainer.hidden = false;
            uploadPrompt.hidden = true;
            resultSection.hidden = true;
            showStatus('');
            setLoading(false);
        } catch (error) {
            console.error(error);
            resetApp();
            showStatus(error.message || 'Не удалось обработать фотографию.');
        }
    }

    // Важно: предотвращаем стандартное поведение браузера при Drag & Drop.
    ['dragenter', 'dragover'].forEach(eventName => {
        dropZone.addEventListener(eventName, event => {
            event.preventDefault();
            event.stopPropagation();
            dropZone.classList.add('dragover');
        });
    });

    ['dragleave', 'drop'].forEach(eventName => {
        dropZone.addEventListener(eventName, event => {
            event.preventDefault();
            event.stopPropagation();
            dropZone.classList.remove('dragover');
        });
    });

    dropZone.addEventListener('drop', event => {
        const file = event.dataTransfer?.files?.[0];
        if (file) handleFile(file);
    });

    dropZone.addEventListener('click', event => {
        if (event.target === resetBtn || resetBtn.contains(event.target)) return;
        fileInput.click();
    });

    dropZone.addEventListener('keydown', event => {
        if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            fileInput.click();
        }
    });

    uploadLink.addEventListener('click', event => {
        event.stopPropagation();
        fileInput.click();
    });

    fileInput.addEventListener('change', event => {
        handleFile(event.target.files?.[0]);
    });

    resetBtn.addEventListener('click', event => {
        event.preventDefault();
        event.stopPropagation();
        resetApp();
    });

    analyzeBtn.addEventListener('click', async () => {
        if (!imageDataUrl) return;

        setLoading(true);
        resultSection.hidden = true;
        showStatus('');

        try {
            const response = await fetch('api.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                },
                body: JSON.stringify({
                    garden_image: imageDataUrl.split(',')[1]
                })
            });

            const data = await response.json().catch(() => ({}));

            if (!response.ok) {
                throw new Error(data.error || `Ошибка сервера: ${response.status}`);
            }

            if (!data.success || !data.result) {
                throw new Error(data.error || 'ИИ не вернул результат анализа.');
            }

            const result = data.result;

            resName.textContent = result.name || 'Не удалось определить';
            resDisease.textContent = result.disease || 'Здорово или не определено';
            resAction.textContent = result.action || 'Рекомендации не получены';

            resultSection.hidden = false;
        } catch (error) {
            console.error(error);
            showStatus(
                'Не удалось выполнить анализ. ' +
                (error.message || 'Проверьте настройки API на сервере.')
            );
        } finally {
            setLoading(false);
        }
    });
});

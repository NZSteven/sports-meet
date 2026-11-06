(() => {
    async function preparePhoto(file) {
        if (!/^(image\/(jpeg|png|webp))$/i.test(file.type) && !/\.(jpe?g|png|webp)$/i.test(file.name)) return file;

        let source;
        let objectUrl;
        try {
            if (typeof createImageBitmap === 'function') {
                try { source = await createImageBitmap(file, {imageOrientation:'from-image'}); } catch (error) {}
            }
            if (!source) {
                objectUrl = URL.createObjectURL(file);
                source = new Image();
                source.src = objectUrl;
                await source.decode();
            }
            const width = source.naturalWidth || source.width;
            const height = source.naturalHeight || source.height;
            const scale = Math.min(1, 1920 / width, 1080 / height);
            if (scale === 1 && file.size <= 512 * 1024) return file;

            const canvas = document.createElement('canvas');
            canvas.width = Math.max(1, Math.floor(width * scale));
            canvas.height = Math.max(1, Math.floor(height * scale));
            const context = canvas.getContext('2d', {alpha:false});
            if (!context) throw new Error('浏览器无法缩小这张照片。');
            context.fillStyle = '#fff';
            context.fillRect(0, 0, canvas.width, canvas.height);
            context.drawImage(source, 0, 0, canvas.width, canvas.height);
            const resized = await new Promise(resolve => canvas.toBlob(resolve, 'image/jpeg', 0.89));
            if (!resized) throw new Error('浏览器无法压缩这张照片。');
            return resized;
        } catch (error) {
            throw new Error('无法在浏览器缩小这张照片，请换一个较小的版本。');
        } finally {
            source?.close?.();
            if (objectUrl) URL.revokeObjectURL(objectUrl);
        }
    }

    document.querySelectorAll('.sm-upload-form').forEach(form => {
        const input = form.querySelector('input[type="file"]');
        const previews = form.querySelector('.sm-photo-previews');
        const status = form.querySelector('.sm-preview-status');
        const submit = form.querySelector('button[type="submit"]');
        if (!input || !previews || !status) return;

        let uploading = false;
        const updateSubmit = () => {
            if (submit) submit.disabled = uploading || input.files.length < 1 || input.files.length > 5;
        };
        let urls = [];
        const render = () => {
            urls.forEach(url => URL.revokeObjectURL(url));
            urls = [];
            previews.replaceChildren();
            const files = Array.from(input.files);
            const tooMany = files.length > 5;
            input.setCustomValidity(tooMany ? '每次最多选择 5 张照片，请重新选择。' : '');
            updateSubmit();
            status.textContent = tooMany ? '每次最多选择 5 张照片，请重新选择。' : (files.length ? `已选 ${files.length} 张照片` : '');

            files.slice(0, 5).forEach(file => {
                const figure = document.createElement('figure');
                const img = document.createElement('img');
                const caption = document.createElement('figcaption');
                const url = URL.createObjectURL(file);
                urls.push(url);
                img.src = url;
                img.alt = '';
                img.addEventListener('error', () => img.remove(), {once:true});
                caption.textContent = file.name;
                figure.append(img, caption);
                previews.append(figure);
            });
        };
        input.addEventListener('change', render);
        window.addEventListener('pageshow', render);
        render();

        const progress = form.querySelector('.sm-upload-progress');
        const results = form.querySelector('.sm-upload-results');
        if (!progress || !results || typeof fetch !== 'function' || typeof AbortController !== 'function') return;
        input.addEventListener('invalid', () => {
            progress.textContent = input.files.length ? input.validationMessage : '请先选择照片，再点击上传。';
        });
        input.addEventListener('change', () => { if (!uploading) progress.textContent = ''; });
        form.addEventListener('submit', async event => {
            if (uploading) { event.preventDefault(); return; }
            const files = Array.from(input.files);
            if (files.length < 1 || files.length > 5) return;

            let url;
            let body;
            try {
                // A named form control called "action" shadows form.action.
                url = new URL(form.getAttribute('action'), location.href);
                body = new FormData(form);
            } catch (error) {
                return; // Let the normal form submission handle incompatible browsers.
            }
            event.preventDefault();
            url.searchParams.set('action', body.get('action'));
            url.searchParams.set('sm_async', '1');
            body.delete(input.name);
            const controls = Array.from(form.elements).filter(element => !element.disabled && element.type !== 'hidden');
            controls.forEach(element => { element.disabled = true; });
            uploading = true;
            results.replaceChildren();
            let saved = 0;
            let pending = 0;
            try {
                for (const [index, file] of files.entries()) {
                    progress.textContent = `正在准备第 ${index + 1} / ${files.length} 张…`;
                    const result = document.createElement('li');
                    let timeout;
                    try {
                        const prepared = await preparePhoto(file);
                        const filename = prepared === file ? file.name : file.name.replace(/\.[^.]+$/, '') + '.jpg';
                        body.set(input.name, prepared, filename);
                        progress.textContent = `正在上传第 ${index + 1} / ${files.length} 张…`;
                        const controller = new AbortController();
                        timeout = setTimeout(() => controller.abort(), 90000);
                        const response = await fetch(url, {method:'POST', body, credentials:'same-origin', signal:controller.signal});
                        const reply = await response.json().catch(() => null);
                        if (!response.ok || !reply?.success) {
                            const message = reply?.data?.message || (response.status === 413
                                ? '文件超过主机的上传限制。' : `服务器未确认上传（HTTP ${response.status}）。`);
                            throw new Error(message);
                        }
                        saved++;
                        if (!reply.data.media_ready) pending++;
                        result.textContent = `${file.name} · ${reply.data.media_ready ? '已上传并加入媒体库' : '已保存，媒体库登记待完成'}`;
                    } catch (error) {
                        const message = error.name === 'AbortError' ? '等待超时，请先查看最近照片再决定是否重传。'
                            : error instanceof TypeError ? '连接中断，未能确认上传结果。' : error.message;
                        result.textContent = `${file.name} · ${message}`;
                        result.className = 'sm-upload-result-error';
                    } finally {
                        clearTimeout(timeout);
                    }
                    results.append(result);
                }
                progress.textContent = `已确认保存 ${saved} / ${files.length} 张。${pending ? `其中 ${pending} 张正在等待媒体库登记。` : ''}`;
                input.value = '';
                render();
            } finally {
                uploading = false;
                controls.forEach(element => { element.disabled = false; });
                updateSubmit();
            }
        });
    });

    const admin = document.querySelector('[data-sm-photo-admin]');
    if (admin) {
        admin.querySelectorAll('[data-sm-author-select]').forEach(select => {
            const label = select.closest('label');
            const group = document.createElement('div');
            group.className = 'sm-author-search';
            const search = document.createElement('input');
            search.type = 'search';
            search.placeholder = '输入作者姓名';
            search.setAttribute('aria-label', '搜索作者姓名');
            label.before(group);
            group.append(search, label);
            const options = Array.from(select.options);
            const normalizedName = option => option.text.trim().toLocaleLowerCase();
            search.addEventListener('input', () => {
                const term = search.value.trim().toLocaleLowerCase();
                const selected = select.value;
                select.replaceChildren(...options.filter((option, index) =>
                    index === 0 || option.value === selected || normalizedName(option).includes(term)
                ));
                select.value = selected;
            });
        });
        const params = new URLSearchParams(location.search);
        const key = 'sports-meet-moderation-scroll-' + params.get('tab');
        admin.addEventListener('submit', event => {
            if (event.target.method !== 'post') return;
            for (const name of ['sm_photo_page', 'admin_message_page', 'sm_author', 'sm_pin']) {
                if (!params.has(name)) continue;
                const field = document.createElement('input');
                field.type = 'hidden';
                field.name = name;
                field.value = params.get(name);
                event.target.append(field);
            }
            const settings = Array.from(admin.querySelectorAll('.sm-photo-admin-settings details[open]'), item => item.id);
            try { sessionStorage.setItem(key, JSON.stringify({y: window.scrollY, settings})); } catch {}
        });
        try {
            const saved = sessionStorage.getItem(key);
            sessionStorage.removeItem(key);
            if (saved !== null) {
                const {y, settings} = JSON.parse(saved);
                for (const id of settings) {
                    const panel = document.getElementById(id);
                    if (panel) panel.open = true;
                }
                requestAnimationFrame(() => window.scrollTo(0, y));
            }
        } catch {}
    }

    const form = document.getElementById('sm-batch-review');
    if (!form) return;
    const selectAll = form.querySelector('[data-sm-select-all]');
    const checkboxes = Array.from(form.elements).filter(element => element.name === 'photo_ids[]');
    const submit = form.querySelector('button[type="submit"]');
    const status = form.querySelector('[data-sm-selected]');
    form.querySelector('[data-sm-select-label]').hidden = false;

    const update = () => {
        const count = checkboxes.filter(checkbox => checkbox.checked).length;
        selectAll.checked = count > 0 && count === checkboxes.length;
        selectAll.indeterminate = count > 0 && count < checkboxes.length;
        submit.disabled = count === 0;
        status.textContent = `已选 ${count} 张`;
    };
    selectAll.addEventListener('change', () => {
        checkboxes.forEach(checkbox => { checkbox.checked = selectAll.checked; });
        update();
    });
    checkboxes.forEach(checkbox => checkbox.addEventListener('change', update));
    update();
})();

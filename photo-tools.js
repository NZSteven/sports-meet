(() => {
    document.querySelectorAll('.sm-upload-form').forEach(form => {
        const input = form.querySelector('input[type="file"]');
        const previews = form.querySelector('.sm-photo-previews');
        const status = form.querySelector('.sm-preview-status');
        if (!input || !previews || !status) return;

        let urls = [];
        const render = () => {
            urls.forEach(url => URL.revokeObjectURL(url));
            urls = [];
            previews.replaceChildren();
            const files = Array.from(input.files);
            const tooMany = files.length > 5;
            input.setCustomValidity(tooMany ? '每次最多选择 5 张照片，请重新选择。' : '');
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
        if (!progress || !results || typeof fetch !== 'function') return;
        let uploading = false;
        form.addEventListener('submit', async event => {
            event.preventDefault();
            if (uploading) return;
            const files = Array.from(input.files);
            if (files.length < 1 || files.length > 5) return;

            const url = new URL(form.action);
            const body = new FormData(form);
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
                    progress.textContent = `正在上传第 ${index + 1} / ${files.length} 张…`;
                    const result = document.createElement('li');
                    body.set(input.name, file);
                    try {
                        const response = await fetch(url, {method:'POST', body, credentials:'same-origin'});
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
                        result.textContent = `${file.name} · ${error instanceof TypeError ? '连接中断，未能确认上传结果。' : error.message}`;
                        result.className = 'sm-upload-result-error';
                    }
                    results.append(result);
                }
                progress.textContent = `已确认保存 ${saved} / ${files.length} 张。${pending ? `其中 ${pending} 张正在等待媒体库登记。` : ''}`;
                input.value = '';
                render();
            } finally {
                uploading = false;
                controls.forEach(element => { element.disabled = false; });
            }
        });
    });

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

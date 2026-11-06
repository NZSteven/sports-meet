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

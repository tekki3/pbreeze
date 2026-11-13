'use strict';

(() => {
    const input = document.getElementById('manual-search');
    const results = document.getElementById('search-results');
    const status = document.getElementById('search-status');
    const clear = document.getElementById('clear-search');
    const units = [...document.querySelectorAll('[data-search-unit]')].map(node => {
        const target = node.id || node.querySelector('[id]')?.id;
        const copy = node.cloneNode(true);
        copy.querySelectorAll('[data-search-unit], .search, .code-toolbar').forEach(child => child.remove());
        return { id: target, title: node.dataset.title, text: copy.textContent.toLocaleLowerCase('en-US') };
    }).filter(unit => unit.id);

    document.getElementById('search-panel').hidden = false;
    const print = document.getElementById('print');
    print.hidden = false;
    print.addEventListener('click', () => window.print());

    const search = () => {
        const terms = input.value.trim().toLocaleLowerCase('en-US').split(/\s+/).filter(Boolean);
        results.replaceChildren();
        clear.hidden = terms.length === 0;
        if (!terms.length) {
            status.textContent = '';
            return;
        }
        const matches = units.filter(unit => terms.every(term => unit.text.includes(term) || unit.title.toLocaleLowerCase('en-US').includes(term)));
        matches.sort((a, b) => Number(b.title.toLocaleLowerCase('en-US').includes(terms.join(' '))) - Number(a.title.toLocaleLowerCase('en-US').includes(terms.join(' '))));
        status.textContent = matches.length
            ? `${matches.length} matching sections${matches.length > 50 ? '; showing the first 50. Add another word to narrow your search.' : '.'}`
            : 'No matches. Try a function name, command, or broader topic.';
        for (const match of matches.slice(0, 50)) {
            const item = document.createElement('li');
            const link = document.createElement('a');
            link.href = `#${match.id}`;
            link.textContent = match.title;
            link.addEventListener('click', () => {
                const target = document.getElementById(match.id);
                target.setAttribute('tabindex', '-1');
                target.focus({ preventScroll: true });
            });
            item.append(link);
            results.append(item);
        }
    };
    input.addEventListener('input', search);
    input.addEventListener('keydown', event => {
        if (event.key === 'Escape') {
            input.value = '';
            search();
        }
    });
    clear.addEventListener('click', () => {
        input.value = '';
        search();
        input.focus();
    });

    document.querySelectorAll('.copy-code').forEach(button => { button.hidden = false; });
    document.addEventListener('click', async event => {
        const button = event.target.closest('.copy-code');
        if (!button) return;
        const example = button.closest('.code-example');
        const code = example.querySelector('pre code');
        const feedback = example.querySelector('.copy-status');
        button.disabled = true;
        feedback.textContent = '';
        try {
            if (!navigator.clipboard?.writeText) throw new Error('Clipboard unavailable.');
            await navigator.clipboard.writeText(code.textContent);
            feedback.textContent = 'Copied.';
        } catch {
            const selection = window.getSelection();
            if (selection) {
                const range = document.createRange();
                range.selectNodeContents(code);
                selection.removeAllRanges();
                selection.addRange(range);
                feedback.textContent = 'Clipboard unavailable. Code selected; use your browser\u2019s Copy command.';
            } else {
                feedback.textContent = 'Clipboard unavailable. Select the code and copy it manually.';
            }
        } finally {
            button.disabled = false;
        }
    });

    const mobile = window.matchMedia('(max-width: 760px)');
    const contents = document.querySelector('.contents');
    const setContents = () => { contents.open = !mobile.matches; };
    setContents();
    mobile.addEventListener('change', setContents);
    const links = [...document.querySelectorAll('.sidebar a')];
    const active = id => {
        const link = links.find(item => item.hash === `#${id}`);
        if (!link) return;
        document.querySelector('.sidebar [aria-current]')?.removeAttribute('aria-current');
        link.setAttribute('aria-current', 'location');
    };
    const revealLocation = () => {
        const target = document.getElementById(location.hash.slice(1) || 'start');
        if (!target) return;
        const section = target.closest('section');
        const id = section?.id || section?.querySelector('h2[id]')?.id;
        const link = links.find(item => item.hash === `#${target.id}`)
            || links.find(item => item.hash === `#${id}`);
        if (!link) return;
        active(link.hash.slice(1));
        const group = link.closest('.nav-group, .class-nav');
        if (group) group.open = true;
    };
    links.forEach(link => {
        link.addEventListener('click', () => {
            active(link.hash.slice(1));
            if (mobile.matches) contents.open = false;
        });
    });
    window.addEventListener('hashchange', revealLocation);
    revealLocation();
    if ('IntersectionObserver' in window) {
        const visible = new Set();
        const observer = new IntersectionObserver(entries => {
            entries.forEach(entry => {
                if (entry.isIntersecting) visible.add(entry.target);
                else visible.delete(entry.target);
            });
            const section = [...visible].sort((a, b) => a.getBoundingClientRect().top - b.getBoundingClientRect().top)[0];
            if (section) active(section.id || section.querySelector('h2[id]')?.id);
        }, { rootMargin: '-90px 0px -55% 0px' });
        document.querySelectorAll('main > section').forEach(section => observer.observe(section));
    }
})();

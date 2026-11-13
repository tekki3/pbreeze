'use strict';

(() => {
    const input = document.getElementById('manual-search');
    const results = document.getElementById('search-results');
    const status = document.getElementById('search-status');
    const clear = document.getElementById('clear-search');
    const units = [...document.querySelectorAll('[data-search-unit]')].map(node => {
        const target = node.id || node.querySelector('[id]')?.id;
        const copy = node.cloneNode(true);
        copy.querySelectorAll('[data-search-unit], .search').forEach(child => child.remove());
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

    const mobile = window.matchMedia('(max-width: 760px)');
    const contents = document.querySelector('.contents');
    const setContents = () => { contents.open = !mobile.matches; };
    setContents();
    mobile.addEventListener('change', setContents);
    document.querySelectorAll('.sidebar a').forEach(link => {
        link.addEventListener('click', () => {
            document.querySelector('.sidebar [aria-current]')?.removeAttribute('aria-current');
            link.setAttribute('aria-current', 'location');
            if (mobile.matches) contents.open = false;
        });
    });
})();

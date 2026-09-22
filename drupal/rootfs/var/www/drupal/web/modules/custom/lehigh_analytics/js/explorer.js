(function (Drupal, drupalSettings, once) {
  'use strict';
  Drupal.behaviors.lehighUsageExplorer = {
    attach(context) {
      once('lehigh-usage-explorer', '[data-lehigh-explorer]', context).forEach((root) => {
        const settings = drupalSettings.lehighAnalytics;
        const find = (selector) => root.querySelector(selector);
        const all = (selector) => root.querySelectorAll(selector);
        const format = (value) => Number(value).toLocaleString();
        const make = (tag, text, className) => {
          const element = document.createElement(tag);
          if (text !== undefined) element.textContent = text;
          if (className) element.className = className;
          return element;
        };
        let params = new URLSearchParams(window.location.search);
        let payload;
        let rank = 'views';
        let dataRequest;
        let optionsRequest;
        let searchTimer;
        const labels = new Map();
        const period = find('[name="period"]');
        const field = find('[data-field]');
        const value = find('[data-value]');
        const status = find('[data-status]');
        const result = find('[data-results]');
        // Only known criteria travel to the public endpoints or shared links.
        for (const key of [...params.keys()]) {
          if (key !== 'period' && !/^filters\[field_[a-z0-9_]+\]\[\d*\]$/.test(key)) params.delete(key);
        }
        if (![...period.options].some((option) => option.value === params.get('period'))) params.set('period', 'all');
        period.value = params.get('period');
        function exportLinks() {
          all('[data-export]').forEach((link) => {
            link.href = `${settings.exportUrl.replace(/[^/]+$/, link.dataset.export)}?${params}`;
          });
        }
        function chips() {
          const container = find('[data-chips]');
          container.replaceChildren();
          for (const [key, id] of params) {
            if (key === 'period') continue;
            const name = key.match(/^filters\[([^\]]+)\]/)?.[1];
            if (!name) continue;
            const fieldLabel = [...field.options].find((option) => option.value === name)?.textContent || name;
            const button = make('button', `${fieldLabel}: ${labels.get(`${name}:${id}`) || `#${id}`} ×`);
            button.type = 'button';
            button.setAttribute('aria-label', `Remove ${button.textContent.slice(0, -2)}`);
            button.addEventListener('click', () => {
              params.delete(key, id);
              load();
            });
            container.append(button);
          }
        }
        function addFilter(name, id, label) {
          if (!id) return;
          const key = `filters[${name}][]`;
          if (!params.getAll(key).includes(String(id))) params.append(key, id);
          labels.set(`${name}:${id}`, label);
          load();
        }
        async function options() {
          optionsRequest?.abort();
          optionsRequest = new AbortController();
          value.disabled = true;
          const url = new URL(settings.optionsUrl.replace(/[^/]+$/, field.value), window.location.origin);
          url.searchParams.set('q', find('[data-search]').value);
          try {
            const response = await fetch(url, { signal: optionsRequest.signal });
            if (!response.ok) throw new Error('Could not load metadata choices.');
            const rows = await response.json();
            value.replaceChildren(make('option', rows.length ? 'Choose a value…' : 'No matching values'));
            value.firstChild.value = '';
            rows.forEach((row) => {
              const option = make('option', row.label);
              option.value = row.id;
              value.append(option);
              labels.set(`${field.value}:${row.id}`, row.label);
            });
            chips();
            value.disabled = false;
          }
          catch (error) {
            if (error.name !== 'AbortError') {
              value.replaceChildren(make('option', 'Unable to load values'));
              value.firstChild.value = '';
            }
          }
        }
        function chart(name, rows, modelIds = []) {
          const container = find(`[data-chart="${name}"]`);
          container.replaceChildren();
          if (!rows.length) { container.append(make('p', 'No recorded usage for these filters.', 'lu-hint')); return; }
          const items = rows.slice(0, name === 'collections' ? 10 : rows.length).map((row, index) => name === 'collections'
            ? { label: row[1], views: row[3], downloads: row[4], id: row[0] }
            : { label: row[0], views: row[1], downloads: row[2], id: modelIds[index] });
          const max = Math.max(...items.flatMap((item) => [item.views, item.downloads]), 1);
          items.forEach((item) => {
            const bar = make(item.id ? 'button' : 'div', undefined, 'lu-bar');
            if (item.id) {
              bar.type = 'button';
              bar.addEventListener('click', () => addFilter(name === 'collections' ? 'field_member_of' : 'field_model', item.id, item.label));
            }
            const head = make('span', undefined, 'lu-bar-head');
            head.append(make('span', item.label), make('small', `${format(item.views)} / ${format(item.downloads)}`));
            bar.append(head);
            bar.setAttribute('aria-label', `${item.label}: ${format(item.views)} page views, ${format(item.downloads)} downloads${item.id ? '. Add filter' : ''}`);
            [item.views, item.downloads].forEach((count) => {
              const track = make('span', undefined, 'lu-track');
              const fill = make('i');
              fill.style.width = `${count / max * 100}%`;
              track.append(fill);
              bar.append(track);
            });
            container.append(bar);
          });
        }
        function works() {
          if (!payload) return;
          const table = find('[data-works]');
          const search = find('[data-work-search]').value.trim().toLocaleLowerCase();
          table.replaceChildren();
          const rows = payload[rank].rows;
          let shown = 0;
          rows.forEach((row, index) => {
            if (!row[1].toLocaleLowerCase().includes(search)) return;
            shown++;
            const tr = make('tr');
            const title = make('td');
            const link = make('a', row[1]);
            link.href = settings.nodeUrl.replace('NODE', row[0]);
            title.append(link);
            tr.append(make('td', String(index + 1)), title, make('td', format(row[2])), make('td', format(row[3])));
            table.append(tr);
          });
          if (!shown) { const tr = make('tr'); const td = make('td', 'No matching documents.'); td.colSpan = 4; tr.append(td); table.append(tr); }
          find('[data-work-count]').textContent = `${shown} of ${rows.length} documents`;
          find('[data-works-export]').dataset.export = rank;
          exportLinks();
        }
        function render() {
          const summary = payload.summary.rows[0];
          find('[data-kpi="views"]').textContent = format(summary[0]);
          find('[data-kpi="downloads"]').textContent = format(summary[1]);
          find('[data-kpi="countries"]').textContent = format(payload.countries.rows.filter((row) => row[0] !== 'Unknown').length);
          find('[data-kpi="collections"]').textContent = format(payload.collections.rows.length);
          find('[data-updated]').textContent = `ENTITY METRICS · Updated ${new Date(payload.updated).toLocaleString()}`;
          find('[data-coverage]').textContent = `First matching recorded event: ${summary[2]} UTC. Last matching event: ${summary[3]} UTC.`;
          chart('types', payload.types.rows, payload.types.ids);
          chart('collections', payload.collections.rows);
          const countries = find('[data-countries]');
          countries.replaceChildren();
          let regionNames;
          try { regionNames = new Intl.DisplayNames([document.documentElement.lang || 'en'], { type: 'region' }); } catch (_) { /* Codes remain readable. */ }
          payload.countries.rows.forEach((row) => {
            let label = row[0];
            if (/^[A-Z]{2}$/.test(label) && regionNames) label = regionNames.of(label);
            const tr = make('tr');
            tr.append(make('td', label), make('td', format(row[1])), make('td', format(row[2])));
            countries.append(tr);
          });
          const unknown = payload.countries.rows.find((row) => row[0] === 'Unknown') || ['', 0, 0];
          find('[data-geo-note]').textContent = `Ranked by page views. Unknown location: ${format(unknown[1])} views, ${format(unknown[2])} downloads.`;
          works();
        }
        async function load(updateHistory = true) {
          dataRequest?.abort();
          dataRequest = new AbortController();
          params.set('period', period.value);
          if (updateHistory) window.history.replaceState(null, '', `${window.location.pathname}?${params}${window.location.hash}`);
          chips();
          exportLinks();
          status.textContent = 'Loading saved usage totals…';
          result.hidden = true;
          result.setAttribute('aria-busy', 'true');
          try {
            const response = await fetch(`${settings.dataUrl}?${params}`, { signal: dataRequest.signal });
            if (!response.ok) {
              let message = 'Unable to load usage. Please try again.';
              if (response.status === 503) message = 'Usage data is being prepared. Please try again later.';
              if (response.status === 400) message = 'These filters are invalid. Reset the filters and try again.';
              throw new Error(message);
            }
            payload = await response.json();
            render();
            result.hidden = false;
            result.setAttribute('aria-busy', 'false');
            status.textContent = payload.period;
          }
          catch (error) {
            if (error.name !== 'AbortError') { status.textContent = error.message; result.setAttribute('aria-busy', 'false'); }
          }
        }
        find('[data-filters]').addEventListener('submit', (event) => { event.preventDefault(); addFilter(field.value, value.value, value.selectedOptions[0]?.textContent); });
        period.addEventListener('change', () => load());
        field.addEventListener('change', () => { find('[data-search]').value = ''; options(); });
        find('[data-search]').addEventListener('input', () => { clearTimeout(searchTimer); searchTimer = setTimeout(options, 250); });
        find('[data-reset]').addEventListener('click', () => { params = new URLSearchParams(); period.value = 'all'; find('[data-work-search]').value = ''; load(); });
        all('[data-rank]').forEach((button) => button.addEventListener('click', () => {
          rank = button.dataset.rank;
          all('[data-rank]').forEach((item) => item.setAttribute('aria-pressed', String(item === button)));
          works();
        }));
        find('[data-work-search]').addEventListener('input', works);
        find('[data-theme]').addEventListener('click', (event) => {
          const dark = root.toggleAttribute('data-dark');
          event.currentTarget.setAttribute('aria-pressed', String(dark));
          event.currentTarget.textContent = dark ? 'Light mode' : 'Dark mode';
        });
        find('[data-copy]').addEventListener('click', async () => {
          try { await navigator.clipboard.writeText(window.location.href); status.textContent = 'Link copied with the current filters.'; }
          catch (_) { status.textContent = 'Copy the URL from your address bar to share these filters.'; }
        });
        options();
        load(false);
      });
    }
  };
})(Drupal, drupalSettings, once);

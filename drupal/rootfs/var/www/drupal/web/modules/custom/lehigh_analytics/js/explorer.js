(function (Drupal, drupalSettings, once, $) {
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
        let errors = {};
        let rank = 'views';
        let dataRequest;
        let optionsRequest;
        let fieldsRequest;
        let optionRows = [];
        let appendOptions = false;
        const labels = new Map();
        const period = find('[name="period"]');
        const field = find('[data-field]');
        const fieldLabels = new Map([...field.options].map((option) => [option.value, option.textContent]));
        const search = find('[data-search]');
        const moreOptions = find('[data-more-options]');
        const optionsStatus = find('[data-options-status]');
        const status = find('[data-status]');
        const result = find('[data-results]');
        // Only known criteria travel to the public endpoints or shared links.
        const initialParams = params;
        params = new URLSearchParams();
        for (const [key, id] of initialParams) {
          const name = key.match(/^filters\[(field_[a-z0-9_]+)\]\[\d*\]$/)?.[1];
          if (key === 'period') params.set(key, id);
          else if (fieldLabels.has(name)) params.append(`filters[${name}][]`, id);
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
            const fieldLabel = fieldLabels.get(name) || name;
            const button = make('button', `${fieldLabel}: ${labels.get(`${name}:${id}`) || `#${id}`} ×`, 'btn btn-sm btn-outline-primary rounded-pill');
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
          if (params.getAll(key).includes(String(id))) return;
          if (params.getAll(key).length >= 50) { optionsStatus.textContent = 'Remove a selected value before adding another (50 per field).'; return; }
          params.append(key, id);
          labels.set(`${name}:${id}`, label);
          load();
        }
        function resetOptions() {
          optionsRequest?.abort();
          optionRows = [];
          appendOptions = false;
          moreOptions.hidden = true;
          $(search).autocomplete('close');
        }
        async function refreshFields() {
          fieldsRequest?.abort();
          const request = new AbortController();
          fieldsRequest = request;
          resetOptions();
          find('[data-retry-filters]').hidden = true;
          field.disabled = search.disabled = true;
          optionsStatus.textContent = 'Loading available filters…';
          const url = new URL(settings.fieldsUrl, window.location.origin);
          url.search = params.toString();
          try {
            const response = await fetch(url, { signal: request.signal });
            if (!response.ok) throw new Error('Could not load filters. Please retry.');
            const available = await response.json();
            if (request !== fieldsRequest) return;
            const selected = field.value;
            field.replaceChildren();
            available.forEach((name) => {
              if (!fieldLabels.has(name)) return;
              const option = make('option', fieldLabels.get(name));
              option.value = name;
              field.append(option);
            });
            if (available.includes(selected)) field.value = selected;
            field.disabled = search.disabled = !field.options.length;
            optionsStatus.textContent = field.options.length ? 'Type to search, or press Down to browse matching values.' : 'No metadata choices have usage for these filters.';
          }
          catch (error) {
            if (request === fieldsRequest && error.name !== 'AbortError') {
              optionsStatus.textContent = error.message;
              find('[data-retry-filters]').hidden = false;
            }
          }
        }
        $(search).autocomplete({
          minLength: 0,
          delay: 250,
          appendTo: root,
          source: async (query, respond) => {
            optionsRequest?.abort();
            const request = new AbortController();
            optionsRequest = request;
            const name = field.value;
            const offset = appendOptions ? optionRows.length : 0;
            appendOptions = false;
            moreOptions.hidden = true;
            if (search.disabled || !name) { respond([]); return; }
            const url = new URL(settings.optionsUrl.replace(/[^/]+$/, name), window.location.origin);
            url.search = params.toString();
            url.searchParams.set('q', query.term);
            url.searchParams.set('offset', offset);
            optionsStatus.textContent = 'Searching matching values…';
            try {
              const response = await fetch(url, { signal: request.signal });
              if (!response.ok) throw new Error('Could not load values. Type to retry.');
              const data = await response.json();
              if (request !== optionsRequest || request.signal.aborted) { respond([]); return; }
              optionRows = offset ? optionRows.concat(data.rows) : data.rows;
              const selected = params.getAll(`filters[${name}][]`);
              const choices = optionRows.filter((row) => !selected.includes(String(row.id)));
              optionRows.forEach((row) => labels.set(`${name}:${row.id}`, row.label));
              chips();
              moreOptions.hidden = !data.more;
              optionsStatus.textContent = data.more ? 'More matches available. Keep typing or show more matches.' : (choices.length ? `${choices.length} matching values. Select a value to add it.` : 'No more matching values.');
              respond(choices.map((row) => ({ label: row.label, value: String(row.id) })));
            }
            catch (error) {
              respond([]);
              if (request === optionsRequest && error.name !== 'AbortError') optionsStatus.textContent = error.message;
            }
          },
          focus: () => false,
          select: (event, ui) => {
            search.value = '';
            addFilter(field.value, ui.item.value, ui.item.label);
            return false;
          },
        });
        moreOptions.addEventListener('click', () => {
          appendOptions = true;
          search.focus();
          $(search).autocomplete('search', search.value);
        });
        find('[data-retry-filters]').addEventListener('click', refreshFields);
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
          find('[data-works-export]').dataset.export = rank;
          exportLinks();
          if (!payload[rank]) {
            const tr = make('tr');
            const td = make('td', errors[rank] || 'Loading document rankings…');
            td.colSpan = 4;
            tr.append(td);
            table.append(tr);
            find('[data-work-count]').textContent = '';
            return;
          }
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
            tr.append(make('td', String(index + 1)), title, make('td', format(row[2]), 'text-end'), make('td', format(row[3]), 'text-end'));
            table.append(tr);
          });
          if (!shown) { const tr = make('tr'); const td = make('td', 'No matching documents.'); td.colSpan = 4; tr.append(td); table.append(tr); }
          find('[data-work-count]').textContent = `${shown} of ${rows.length} documents`;
          find('[data-works-export]').dataset.export = rank;
          exportLinks();
        }
        function render() {
          const summary = payload.summary?.rows[0];
          find('[data-kpi="views"]').textContent = summary ? format(summary[0]) : '—';
          find('[data-kpi="downloads"]').textContent = summary ? format(summary[1]) : '—';
          find('[data-kpi="countries"]').textContent = payload.countries ? format(payload.countries.rows.filter((row) => row[0] !== 'Unknown').length) : '—';
          find('[data-kpi="collections"]').textContent = payload.collections ? format(payload.collections.rows.length) : '—';
          find('[data-updated]').textContent = payload.updated ? `ENTITY METRICS · Updated ${new Date(payload.updated).toLocaleString()}` : 'Loading recorded usage…';
          find('[data-coverage]').textContent = summary ? `First matching recorded event: ${summary[2]} UTC. Last matching event: ${summary[3]} UTC.` : (errors.summary || '');
          for (const name of ['types', 'collections']) {
            if (payload[name]) chart(name, payload[name].rows, payload[name].ids);
            else find(`[data-chart="${name}"]`).textContent = errors[name] || 'Loading usage…';
          }
          const countries = find('[data-countries]');
          countries.replaceChildren();
          find('[data-geo-note]').textContent = '';
          if (payload.countries) {
            let regionNames;
            try { regionNames = new Intl.DisplayNames([document.documentElement.lang || 'en'], { type: 'region' }); } catch (_) { /* Codes remain readable. */ }
            payload.countries.rows.filter((row) => row[0] !== 'Unknown').forEach((row) => {
              let label = row[0];
              if (/^[A-Z]{2}$/.test(label) && regionNames) label = regionNames.of(label);
              const tr = make('tr');
              tr.append(make('td', label), make('td', format(row[1]), 'text-end'), make('td', format(row[2]), 'text-end'));
              countries.append(tr);
            });
            const unknown = payload.countries.rows.find((row) => row[0] === 'Unknown') || ['', 0, 0];
            find('[data-geo-note]').textContent = `Ranked by page views. Unknown location: ${format(unknown[1])} views, ${format(unknown[2])} downloads.`;
          }
          else {
            const tr = make('tr');
            const td = make('td', errors.countries || 'Loading country and territory usage…');
            td.colSpan = 3;
            tr.append(td);
            countries.append(tr);
          }
          works();
        }
        async function load(updateHistory = true) {
          dataRequest?.abort();
          const request = new AbortController();
          dataRequest = request;
          params.set('period', period.value);
          refreshFields();
          if (updateHistory) window.history.replaceState(null, '', `${window.location.pathname}?${params}${window.location.hash}`);
          chips();
          exportLinks();
          payload = {};
          errors = {};
          render();
          result.hidden = false;
          find('[data-retry]').hidden = true;
          status.textContent = 'Loading saved usage totals…';
          const names = { summary: 'usage totals', views: 'page-view rankings', types: 'content types', downloads: 'download rankings', collections: 'collections', countries: 'countries and territories' };
          const queue = Object.keys(names);
          const query = new URLSearchParams(params);
          let completed = 0;
          // Limit database concurrency; each response renders independently.
          async function worker() {
            while (queue.length && !request.signal.aborted) {
              const report = queue.shift();
              const url = new URL(settings.dataUrl, window.location.origin);
              url.search = query.toString();
              url.searchParams.set('report', report);
              try {
                const response = await fetch(url, { signal: request.signal });
                if (!response.ok) {
                  let message = 'Unable to load this report. Please retry.';
                  if (response.status === 503) message = 'Usage data is being prepared. Please try again later.';
                  if (response.status === 400) message = 'These filters are invalid. Reset the filters and try again.';
                  throw new Error(message);
                }
                const data = await response.json();
                if (dataRequest !== request) return;
                if (!data[report]) throw new Error('Unexpected report response. Please retry.');
                Object.assign(payload, data);
              }
              catch (error) {
                if (request.signal.aborted || dataRequest !== request) return;
                errors[report] = error.message;
              }
              completed++;
              render();
              const failed = Object.keys(errors);
              status.textContent = `${payload.period || period.selectedOptions[0].textContent} · ${completed - failed.length} of 6 reports loaded.${failed.length ? ` Could not load ${failed.map((name) => names[name]).join(', ')}.` : ''}`;
              find('[data-retry]').hidden = failed.length === 0;
            }
          }
          await Promise.all([worker(), worker()]);
        }
        find('[data-retry]').addEventListener('click', () => load());
        find('[data-filters]').addEventListener('submit', (event) => event.preventDefault());
        period.addEventListener('change', () => load());
        field.addEventListener('change', () => { search.value = ''; resetOptions(); optionsStatus.textContent = ''; search.focus(); $(search).autocomplete('search', ''); });
        search.addEventListener('input', resetOptions);
        find('[data-reset]').addEventListener('click', () => { params = new URLSearchParams(); period.value = 'all'; search.value = ''; find('[data-work-search]').value = ''; load(); });
        all('[data-rank]').forEach((button) => button.addEventListener('click', () => {
          rank = button.dataset.rank;
          all('[data-rank]').forEach((item) => {
            item.setAttribute('aria-pressed', String(item === button));
            item.classList.toggle('active', item === button);
          });
          works();
        }));
        find('[data-work-search]').addEventListener('input', works);
        find('[data-theme]').addEventListener('click', (event) => {
          const dark = root.dataset.bsTheme !== 'dark';
          root.dataset.bsTheme = dark ? 'dark' : 'light';
          event.currentTarget.setAttribute('aria-pressed', String(dark));
          event.currentTarget.textContent = dark ? 'Light mode' : 'Dark mode';
        });
        find('[data-copy]').addEventListener('click', async () => {
          try { await navigator.clipboard.writeText(window.location.href); status.textContent = 'Link copied with the current filters.'; }
          catch (_) { status.textContent = 'Copy the URL from your address bar to share these filters.'; }
        });
        load(false);
      });
    }
  };
})(Drupal, drupalSettings, once, jQuery);

(() => {
  'use strict';

  const boot = window.TRAVEL_SYNC_BOOT;
  const screen = document.getElementById('screen');
  if (!boot || !screen) return;

  const state = {
    step: 1,
    catalog: [],
    connections: [],
    settings: {},
    setupComplete: false,
    serviceTypes: {},
    syncRuns: [],
    syncRunsLoaded: false,
    support: {},
    processes: [],
    fields: [],
    categories: [],
    stages: {},
    selectedProvider: null,
    mappings: {},
    dirtyMappings: {},
    expenseCatalog: {},
    expenseFields: [],
    expenseMappings: [],
    expenseMappingsLoaded: false,
    expenseCategories: [],
    expenseStages: [],
    users: [],
    usersLoaded: false,
    usersError: '',
    forceRouteStagesRefresh: false,
    forceExpenseStageRefresh: false,
    forceUsersRefresh: false,
    renderToken: 0
  };
  const pendingReads = new Map();

  const copy = {
    1: ['Шаг 1 из 6', 'Подключите тревел-сервисы', 'Можно подключить один сервис или несколько. Секреты шифруются отдельно для вашего портала.'],
    2: ['Шаг 2 из 6', 'Выберите смарт-процесс', 'Все карточки создаются в одном смарт-процессе, а типы услуг распределяются по его воронкам.'],
    3: ['Шаг 3 из 6', 'Настройте маршруты услуг', 'Выберите, в какую воронку и начальную стадию направлять авиабилеты, проживание, поезда и другие услуги.'],
    4: ['Шаг 4 из 6', 'Сопоставьте поля', 'Укажите, в какие поля Bitrix24 записывать нормализованные данные каждого провайдера.'],
    5: ['Шаг 5 из 6', 'Настройте затраты', 'Создавайте одну карточку затраты для билета и связанных с ним дополнительных услуг.'],
    6: ['Шаг 6 из 6', 'Запуск и журнал', 'Настройте расписание, включите синхронизацию и проверьте результат ручным запуском.']
  };
  const cacheTtlMs = 10 * 60 * 1000;

  function readCache(name) {
    try {
      const raw = window.sessionStorage.getItem(`travel-sync:${boot.memberId}:${name}`);
      if (!raw) return null;
      const cached = JSON.parse(raw);
      if (!cached || Date.now() - Number(cached.savedAt || 0) > cacheTtlMs) return null;
      return cached.value;
    } catch {
      return null;
    }
  }

  function writeCache(name, value) {
    try {
      window.sessionStorage.setItem(`travel-sync:${boot.memberId}:${name}`, JSON.stringify({
        savedAt: Date.now(),
        value
      }));
    } catch {
      // Storage can be disabled inside a third-party iframe; in-memory state still works.
    }
  }

  async function api(action, options = {}) {
    const query = new URLSearchParams({ action, ...(options.query || {}) });
    const controller = new AbortController();
    const timeoutMs = Number(options.timeoutMs || 30000);
    const timer = window.setTimeout(() => controller.abort(), timeoutMs);
    try {
      const response = await fetch(`api.php?${query}`, {
        method: options.method || 'GET',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': boot.csrf },
        body: options.body ? JSON.stringify(options.body) : undefined,
        signal: controller.signal
      });
      const raw = await response.text();
      let payload;
      try {
        payload = JSON.parse(raw);
      } catch {
        throw new Error(`Сервер приложения вернул некорректный ответ (HTTP ${response.status}).`);
      }
      if (!response.ok || !payload.success) {
        const message = payload.error?.message || `Ошибка запроса (HTTP ${response.status})`;
        const requestId = payload.error?.requestId ? ` · запрос ${payload.error.requestId}` : '';
        throw new Error(`${message}${requestId}`);
      }
      return payload.data;
    } catch (error) {
      if (error.name === 'AbortError') {
        throw new Error(`Сервер приложения не ответил за ${Math.round(timeoutMs / 1000)} секунд.`);
      }
      throw error;
    } finally {
      window.clearTimeout(timer);
    }
  }

  function singleFlight(key, loader) {
    if (pendingReads.has(key)) return pendingReads.get(key);
    const promise = Promise.resolve().then(loader).finally(() => pendingReads.delete(key));
    pendingReads.set(key, promise);
    return promise;
  }

  function bitrixBoolean(value) {
    if (typeof value === 'boolean') return value;
    if (typeof value === 'number') return value !== 0;
    return ['Y', 'YES', 'TRUE', '1'].includes(String(value ?? '').trim().toUpperCase());
  }

  function normalizeSmartProcessFields(result) {
    const fields = result?.fields || result || {};
    return Object.entries(fields)
      .filter(([, field]) => field && typeof field === 'object' && !bitrixBoolean(field.isReadOnly))
      .map(([code, field]) => ({
        code,
        title: field.title || field.formLabel || code,
        type: String(field.type || 'string').toLowerCase(),
        required: bitrixBoolean(field.isRequired),
        multiple: bitrixBoolean(field.isMultiple)
      }))
      .sort((left, right) => left.title.localeCompare(right.title, 'ru'));
  }

  function normalizeSmartProcesses(result) {
    const processes = result?.types || result || [];
    return (Array.isArray(processes) ? processes : Object.values(processes))
      .filter(process => process && typeof process === 'object' && Number(process.entityTypeId) > 0)
      .map(process => ({
        id: Number(process.id || 0),
        entityTypeId: Number(process.entityTypeId),
        title: String(process.title || `Смарт-процесс ${process.entityTypeId}`)
      }))
      .sort((left, right) => left.title.localeCompare(right.title, 'ru'));
  }

  function normalizeCategories(result) {
    const categories = result?.categories || result || [];
    return (Array.isArray(categories) ? categories : Object.values(categories))
      .filter(category => category && typeof category === 'object' && Number(category.id) >= 0)
      .map(category => ({
        id: Number(category.id),
        name: String(category.name || category.title || `Воронка ${category.id}`),
        isDefault: bitrixBoolean(category.isDefault)
      }));
  }

  function normalizeStages(result) {
    const stages = result?.statuses || result || [];
    return (Array.isArray(stages) ? stages : Object.values(stages))
      .filter(stage => stage && typeof stage === 'object')
      .map(stage => ({
        id: String(stage.STATUS_ID || stage.statusId || stage.id || ''),
        name: String(stage.NAME || stage.name || stage.title || ''),
        sort: Number(stage.SORT || stage.sort || 0)
      }))
      .filter(stage => stage.id !== '')
      .sort((left, right) => left.sort - right.sort);
  }

  function normalizeUsers(result) {
    const users = result?.users || result || [];
    const unique = new Map();
    (Array.isArray(users) ? users : Object.values(users)).forEach(user => {
      if (!user || typeof user !== 'object') return;
      const id = Number(user.ID || user.id || 0);
      const active = user.ACTIVE ?? user.active ?? true;
      if (id <= 0 || active === false || String(active).toUpperCase() === 'N') return;
      const name = String(user.name || [user.NAME, user.LAST_NAME].filter(Boolean).join(' ') || `Пользователь ${id}`).trim();
      unique.set(id, {
        id,
        name,
        position: String(user.position || user.WORK_POSITION || '').trim()
      });
    });
    return [...unique.values()].sort((left, right) => left.name.localeCompare(right.name, 'ru'));
  }

  async function loadSmartProcesses(forceRefresh = false) {
    const cached = readCache('processes');
    if (!forceRefresh && Array.isArray(cached)) return cached;
    const processes = await singleFlight(`processes:${forceRefresh ? 'refresh' : 'normal'}`, async () =>
      normalizeSmartProcesses(await api('smart-processes', {
        query: forceRefresh ? { refresh: 1 } : {},
        timeoutMs: 20000
      }))
    );
    writeCache('processes', processes);
    return processes;
  }

  async function loadSmartProcessFields(entityTypeId, forceRefresh = false) {
    const cacheName = `fields:${entityTypeId}`;
    const cached = readCache(cacheName);
    if (!forceRefresh && Array.isArray(cached)) return cached;
    const fields = await singleFlight(`${cacheName}:${forceRefresh ? 'refresh' : 'normal'}`, async () =>
      normalizeSmartProcessFields(await api('smart-process-fields', {
        query: { entityTypeId, ...(forceRefresh ? { refresh: 1 } : {}) },
        timeoutMs: 25000
      }))
    );
    writeCache(cacheName, fields);
    return fields;
  }

  const escapeHtml = value => String(value ?? '').replace(/[&<>"']/g, char => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
  })[char]);

  const connected = code => state.connections.some(item => item.provider === code && Number(item.enabled) === 1);
  const connection = code => state.connections.find(item => item.provider === code);

  function setStatus(message, error = false) {
    const element = document.getElementById('save-status');
    element.textContent = message;
    element.classList.toggle('error-text', error);
  }

  function updateNavigation() {
    document.querySelectorAll('.step').forEach(button => {
      const number = Number(button.dataset.step);
      button.classList.toggle('active', number === state.step);
      button.classList.toggle('done', number < state.step);
    });
    const [kicker, title, description] = copy[state.step];
    document.getElementById('step-kicker').textContent = kicker;
    document.getElementById('step-title').textContent = title;
    document.getElementById('step-description').textContent = description;
    document.getElementById('back').disabled = state.step === 1;
    document.getElementById('next').textContent = state.step === 6 ? 'Сохранить настройки' : 'Продолжить';
    setStatus('');
  }

  function renderProviders() {
    const resumePanel = state.setupComplete ? `<section class="resume-panel">
      <div><strong>Настройка уже выполнена</strong><p>Подключения, смарт-процесс, воронки и сопоставление полей сохранены. Их можно изменить ниже или сразу перейти к запуску.</p></div>
      <button class="button primary" id="resume-sync" type="button">Перейти к запуску</button>
    </section>` : '';
    screen.innerHTML = `${resumePanel}<div class="provider-grid">${state.catalog.map(provider => {
      const current = connection(provider.code);
      const checked = connected(provider.code);
      const health = current?.last_error
        ? `<span class="health bad">${current?.verified_at ? 'Подключение подтверждалось ранее · последняя проверка с ошибкой' : 'Ошибка проверки'}</span>`
        : current?.verified_at ? `<span class="health good">Проверено</span>` : '';
      return `<article class="provider-card ${checked ? 'connected' : ''}">
        <div class="provider-heading">
          <div><span class="provider-code">${escapeHtml(provider.code)}</span><h3>${escapeHtml(provider.title)}</h3></div>
          <span class="connection-badge">${checked ? 'Подключён' : 'Не подключён'}</span>
        </div>
        <p>${escapeHtml(provider.description)}</p>${health}
        <form class="provider-form" data-provider="${escapeHtml(provider.code)}">
          ${provider.credentials.map(field => `<label><span>${escapeHtml(field.label)}</span>
            ${field.type === 'select'
              ? `<select name="${escapeHtml(field.key)}" ${field.required ? 'required' : ''}>
                  ${(field.options || []).map(option => `<option value="${escapeHtml(option.value)}"
                    ${String(option.value) === String(field.default || '') ? 'selected' : ''}>${escapeHtml(option.label)}</option>`).join('')}
                </select>`
              : `<input name="${escapeHtml(field.key)}" type="${escapeHtml(field.type)}"
                  value="${escapeHtml(field.default || '')}"
                  placeholder="${current ? 'Введите заново для изменения' : ''}" ${field.required ? 'required' : ''}>`}
          </label>`).join('')}
          <label class="toggle-row"><input name="enabled" type="checkbox" ${checked ? 'checked' : ''}>
            <span>Использовать в синхронизации</span></label>
          <div class="button-row">
            <button class="button small" type="submit">${current ? 'Обновить ключи' : 'Сохранить подключение'}</button>
            ${current ? `<button class="button small ghost test-provider" type="button">Проверить</button>` : ''}
          </div>
          <p class="form-status muted">${current?.last_error ? escapeHtml(current.last_error) : ''}</p>
        </form>
      </article>`;
    }).join('')}</div>`;

    screen.querySelector('#resume-sync')?.addEventListener('click', async () => {
      state.step = 6;
      await render();
    });

    screen.querySelectorAll('.provider-form').forEach(form => {
      form.addEventListener('submit', async event => {
        event.preventDefault();
        const provider = form.dataset.provider;
        const definition = state.catalog.find(item => item.code === provider);
        const credentials = Object.fromEntries(definition.credentials.map(field => [field.key, form.elements[field.key].value]));
        const status = form.querySelector('.form-status');
        status.textContent = 'Сохраняем…';
        try {
          await api('providers.save', { method: 'POST', body: { provider, credentials, enabled: form.elements.enabled.checked } });
          state.connections = state.connections.filter(item => item.provider !== provider);
          state.connections.push({ provider, enabled: form.elements.enabled.checked ? 1 : 0, verified_at: null, last_error: null });
          renderProviders();
        } catch (error) {
          status.textContent = error.message;
          status.classList.add('error-text');
        }
      });
      form.querySelector('.test-provider')?.addEventListener('click', async () => {
        const provider = form.dataset.provider;
        const status = form.querySelector('.form-status');
        status.textContent = 'Проверяем соединение…';
        try {
          await api('providers.test', { method: 'POST', body: { provider }, timeoutMs: 70000 });
          const current = connection(provider);
          if (current) {
            current.verified_at = new Date().toISOString();
            current.last_error = null;
          }
          status.textContent = 'Соединение установлено';
          renderProviders();
        } catch (error) {
          status.textContent = error.message;
          status.classList.add('error-text');
        }
      });
    });
  }

  async function renderProcesses(renderToken) {
    screen.innerHTML = '<div class="loader">Получаем смарт-процессы из Bitrix24…</div>';
    try {
      if (state.processes.length === 0) state.processes = await loadSmartProcesses();
    } catch (error) {
      if (renderToken !== state.renderToken || state.step !== 2) return;
      screen.innerHTML = `<div class="empty-state error-text"><h3>Не удалось обновить список смарт-процессов</h3><p>${escapeHtml(error.message)}</p><button type="button" class="button retry-processes">Повторить</button></div>`;
      screen.querySelector('.retry-processes').addEventListener('click', async () => {
        state.processes = await loadSmartProcesses(true);
        await render();
      });
      return;
    }
    if (renderToken !== state.renderToken || state.step !== 2) return;
    screen.innerHTML = `<div class="selection-card">
      <label class="large-field"><span>Смарт-процесс</span><select id="entity-type">
        <option value="">Выберите процесс</option>
        ${state.processes.map(process => `<option value="${process.entityTypeId}" ${Number(state.settings.entityTypeId) === process.entityTypeId ? 'selected' : ''}>${escapeHtml(process.title)} · ${process.entityTypeId}</option>`).join('')}
      </select></label>
      <button type="button" class="button ghost refresh-processes">Обновить список из Bitrix24</button>
      <div class="hint"><strong>Как это работает</strong><p>Например, в процессе «Командировки» могут быть воронки «Билеты» и «Хостелы». На следующем шаге приложение распределит услуги между ними.</p></div>
    </div>`;
    screen.querySelector('.refresh-processes').addEventListener('click', async event => {
      event.currentTarget.disabled = true;
      try {
        state.processes = await loadSmartProcesses(true);
        await render();
      } catch (error) {
        event.currentTarget.disabled = false;
        setStatus(error.message, true);
      }
    });
  }

  async function saveProcessSelection() {
    const value = document.getElementById('entity-type')?.value;
    if (!value) throw new Error('Выберите смарт-процесс.');
    const processChanged = Number(state.settings.entityTypeId) !== Number(value);
    if (processChanged) {
      state.categories = [];
      state.stages = {};
      state.fields = [];
      state.settings.serviceRoutes = {};
      // Existing mappings target fields of the previous entity, therefore an
      // actual process switch must be completed before cron can safely resume.
      state.settings.enabled = false;
    }
    state.settings.entityTypeId = Number(value);
    await saveSettings();
  }

  async function loadStages(categoryId, forceRefresh = false) {
    const key = String(categoryId);
    if (!state.stages[key] || forceRefresh) {
      const entityTypeId = Number(state.settings.entityTypeId);
      const cached = readCache(`stages:${entityTypeId}:${categoryId}`);
      state.stages[key] = !forceRefresh && Array.isArray(cached) ? cached : await singleFlight(
        `stages:${entityTypeId}:${categoryId}:${forceRefresh ? 'refresh' : 'normal'}`,
        async () => normalizeStages(await api('smart-process-stages', {
          query: { entityTypeId, categoryId, ...(forceRefresh ? { refresh: 1 } : {}) },
          timeoutMs: 20000
        }))
      );
      writeCache(`stages:${entityTypeId}:${categoryId}`, state.stages[key]);
    }
    return state.stages[key];
  }

  async function renderRoutes(renderToken) {
    if (!state.settings.entityTypeId) {
      screen.innerHTML = '<div class="empty-state"><h3>Сначала выберите смарт-процесс</h3></div>';
      return;
    }
    screen.innerHTML = '<div class="loader">Получаем воронки и стадии…</div>';
    if (state.categories.length === 0) {
      const entityTypeId = Number(state.settings.entityTypeId);
      const cached = readCache(`categories:${entityTypeId}`);
      try {
        state.categories = Array.isArray(cached) ? cached : await loadEntityCategories(entityTypeId);
      } catch (error) {
        if (renderToken !== state.renderToken || state.step !== 3) return;
        screen.innerHTML = `<div class="empty-state error-text"><h3>Не удалось обновить воронки</h3><p>${escapeHtml(error.message)}</p><button type="button" class="button retry-categories">Повторить</button></div>`;
        screen.querySelector('.retry-categories').addEventListener('click', async () => {
          state.categories = await loadEntityCategories(entityTypeId, true);
          await render();
        });
        return;
      }
      writeCache(`categories:${entityTypeId}`, state.categories);
    }
    if (renderToken !== state.renderToken || state.step !== 3) return;
    const routes = state.settings.serviceRoutes || {};

    screen.innerHTML = `<div class="route-list">
      <div class="route-head"><span>Тип услуги</span><span>Воронка</span><span>Начальная стадия</span></div>
      ${Object.entries(state.serviceTypes).map(([type, label]) => {
        const route = routes[type] || {};
        const stages = state.stages[String(route.categoryId)] || [];
        const defaultEnabled = Object.keys(routes).length === 0 && ['air', 'accommodation'].includes(type);
        return `<div class="route-row" data-type="${escapeHtml(type)}" data-saved-stage="${escapeHtml(route.stageId || '')}">
          <label class="route-toggle"><input class="route-enabled" type="checkbox" ${(route.enabled ?? defaultEnabled) ? 'checked' : ''}><span><strong>${escapeHtml(label)}</strong><small>${escapeHtml(type)}</small></span></label>
          <select class="route-category"><option value="">Не синхронизировать</option>
            ${state.categories.map(category => `<option value="${category.id}" ${String(route.categoryId) === String(category.id) ? 'selected' : ''}>${escapeHtml(category.name)}</option>`).join('')}
          </select>
          <select class="route-stage"><option value="">${route.categoryId && !state.stages[String(route.categoryId)] ? 'Стадии загружаются…' : 'Стадия по умолчанию'}</option>
            ${stages.map(stage => `<option value="${escapeHtml(stage.id)}" ${route.stageId === stage.id ? 'selected' : ''}>${escapeHtml(stage.name)}</option>`).join('')}
          </select>
        </div>`;
      }).join('')}
      <div class="hint compact"><strong>Пример:</strong> «Авиабилеты» → воронка «Билеты», «Проживание / отель / хостел» → воронка «Хостелы».</div>
      <button type="button" class="button ghost refresh-routes">Обновить воронки и стадии</button>
    </div>`;

    screen.querySelectorAll('.route-category').forEach(select => {
      select.addEventListener('change', async event => {
        const row = event.target.closest('.route-row');
        const stage = row.querySelector('.route-stage');
        stage.innerHTML = '<option value="">Загружаем стадии…</option>';
        if (event.target.value === '') {
          stage.innerHTML = '<option value="">Стадия по умолчанию</option>';
          return;
        }
        try {
          const stages = await loadStages(Number(event.target.value));
          stage.innerHTML = `<option value="">Стадия по умолчанию</option>${stages.map(item => `<option value="${escapeHtml(item.id)}">${escapeHtml(item.name)}</option>`).join('')}`;
          row.dataset.savedStage = '';
          row.querySelector('.route-enabled').checked = true;
        } catch (error) {
          stage.innerHTML = `<option value="">Не удалось загрузить стадии</option>`;
          setStatus(error.message, true);
        }
      });
    });
    screen.querySelector('.refresh-routes').addEventListener('click', async event => {
      event.currentTarget.disabled = true;
      try {
        const entityTypeId = Number(state.settings.entityTypeId);
        state.categories = await loadEntityCategories(entityTypeId, true);
        state.stages = {};
        state.forceRouteStagesRefresh = true;
        await render();
      } catch (error) {
        event.currentTarget.disabled = false;
        setStatus(error.message, true);
      }
    });
    const forceStagesRefresh = state.forceRouteStagesRefresh;
    state.forceRouteStagesRefresh = false;
    void hydrateRouteStages(renderToken, forceStagesRefresh);
  }

  async function hydrateRouteStages(renderToken, forceRefresh = false) {
    const categoryIds = [...new Set([...screen.querySelectorAll('.route-category')]
      .map(select => select.value)
      .filter(Boolean))];
    for (const categoryId of categoryIds) {
      try {
        const stages = await loadStages(Number(categoryId), forceRefresh);
        if (renderToken !== state.renderToken || state.step !== 3) return;
        screen.querySelectorAll('.route-row').forEach(row => {
          if (row.querySelector('.route-category')?.value !== String(categoryId)) return;
          const select = row.querySelector('.route-stage');
          const selected = row.dataset.savedStage || select.value;
          select.innerHTML = `<option value="">Стадия по умолчанию</option>${stages.map(item => `<option value="${escapeHtml(item.id)}" ${selected === item.id ? 'selected' : ''}>${escapeHtml(item.name)}</option>`).join('')}`;
        });
      } catch (error) {
        if (renderToken !== state.renderToken || state.step !== 3) return;
        screen.querySelectorAll('.route-row').forEach(row => {
          if (row.querySelector('.route-category')?.value === String(categoryId)) {
            row.querySelector('.route-stage').innerHTML = '<option value="">Не удалось загрузить стадии</option>';
          }
        });
        setStatus(error.message, true);
      }
    }
  }

  async function saveRoutes() {
    const routes = {};
    screen.querySelectorAll('.route-row').forEach(row => {
      const category = row.querySelector('.route-category').value;
      routes[row.dataset.type] = {
        enabled: row.querySelector('.route-enabled').checked && category !== '',
        categoryId: category === '' ? null : Number(category),
        stageId: row.querySelector('.route-stage').value
      };
    });
    if (!Object.values(routes).some(route => route.enabled)) {
      throw new Error('Выберите хотя бы одну воронку для типа услуги.');
    }
    state.settings.serviceRoutes = routes;
    await saveSettings();
  }

  async function renderMappings(renderToken) {
    const providers = state.catalog.filter(item => connected(item.code));
    if (providers.length === 0) {
      screen.innerHTML = '<div class="empty-state"><h3>Нет подключённых провайдеров</h3><p>Вернитесь на первый шаг и сохраните ключи.</p></div>';
      return;
    }
    screen.innerHTML = '<div class="loader">Получаем поля смарт-процесса и настройки сопоставления…</div>';
    if (!state.selectedProvider || !connected(state.selectedProvider)) state.selectedProvider = providers[0].code;
    const selectedProvider = state.selectedProvider;
    const [fieldsResult, mappingsResult] = await Promise.allSettled([
      state.fields.length > 0
        ? Promise.resolve(state.fields)
        : loadSmartProcessFields(state.settings.entityTypeId),
      Object.prototype.hasOwnProperty.call(state.mappings, selectedProvider)
        ? Promise.resolve(state.mappings[selectedProvider])
        : api('mappings.get', { query: { provider: selectedProvider }, timeoutMs: 15000 })
    ]);
    if (mappingsResult.status === 'rejected') throw mappingsResult.reason;
    if (fieldsResult.status === 'rejected') {
      if (renderToken !== state.renderToken || state.step !== 4) return;
      screen.innerHTML = `<div class="empty-state error-text"><h3>Не удалось обновить поля смарт-процесса</h3><p>${escapeHtml(fieldsResult.reason?.message || fieldsResult.reason)}</p><button type="button" class="button retry-fields">Повторить</button></div>`;
      screen.querySelector('.retry-fields').addEventListener('click', async () => {
        state.fields = await loadSmartProcessFields(state.settings.entityTypeId, true);
        await render();
      });
      return;
    }
    state.fields = fieldsResult.value;
    state.mappings[selectedProvider] = mappingsResult.value;
    if (renderToken !== state.renderToken || state.step !== 4) return;
    const definition = state.catalog.find(item => item.code === selectedProvider);
    const saved = Object.fromEntries(state.mappings[selectedProvider].map(item => [item.sourceField, item]));
    screen.innerHTML = `<div class="mapping-toolbar">
      <label><span>Провайдер</span><select id="mapping-provider">${providers.map(item => `<option value="${item.code}" ${item.code === state.selectedProvider ? 'selected' : ''}>${escapeHtml(item.title)}</option>`).join('')}</select></label>
      <span>${definition.fields.length} полей · обязательный ID: <code>${escapeHtml(definition.identityField)}</code></span>
    </div>
    <div class="hint compact">Поля нормализованного формата общие для Trivio, Авиасейлс и Smartway. Созданное здесь поле Bitrix24 можно выбрать для любого подключённого сервиса без повторного создания. Для «Виды доп. услуг» выберите множественную строку, для документов допуслуг Trivio — отдельное множественное поле «Файл». Стоимость билета включает связанные допуслуги и переданные сервисом сборы. <button type="button" class="button small ghost refresh-fields">Обновить поля</button></div>
    <div class="mapping-table"><div class="mapping-head"><span>Поле источника</span><span>Поле Bitrix24</span><span>Преобразование</span></div>
      ${definition.fields.map(source => {
        const current = saved[source] || {};
        const fieldDefinition = definition.fieldDefinitions?.[source] || {
          label: source,
          type: source === 'ticket_file' ? 'file' : 'string',
          transform: source === 'ticket_file' ? 'file' : 'string'
        };
        const isFile = fieldDefinition.type === 'file';
        const isMultiple = !!fieldDefinition.multiple;
        const targetFields = state.fields.filter(field => {
          if (isFile) return field.type === 'file' && (!isMultiple || field.multiple);
          if (source === 'additional_service_types') return field.type === 'string' && field.multiple;
          return true;
        });
        const reusableField = targetFields.find(field => field.title === fieldDefinition.label && field.type === fieldDefinition.type && (!isMultiple || field.multiple));
        return `<div class="mapping-row ${source === definition.identityField ? 'identity-row' : ''}" data-source="${escapeHtml(source)}">
          <div class="mapping-source"><strong>${escapeHtml(fieldDefinition.label)}${source === definition.identityField ? ' · обязательное' : ''}</strong><code>${escapeHtml(source)}</code></div>
          <div class="mapping-target"><select class="target-field"><option value="">${source === 'ticket_file' && targetFields.length === 0 ? 'Нет доступного поля типа «Файл»' : 'Не передавать'}</option>${targetFields.map(field => `<option value="${escapeHtml(field.code)}" ${current.targetField === field.code ? 'selected' : ''}>${escapeHtml(field.title)} · ${escapeHtml(field.type)}${field.multiple ? ' · множественное' : ''}</option>`).join('')}</select>
            <button type="button" class="secondary create-field" data-existing-code="${escapeHtml(reusableField?.code || '')}">${reusableField ? 'Выбрать готовое' : 'Создать поле'}</button>
          </div>
          ${isFile
            ? `<select class="transform">${!isMultiple ? `<option value="file" ${current.transform !== 'file_multiple' ? 'selected' : ''}>Одиночный файл</option>` : ''}<option value="file_multiple" ${isMultiple || current.transform === 'file_multiple' ? 'selected' : ''}>Множественное файловое поле</option></select>`
            : source === 'additional_service_types'
              ? `<select class="transform"><option value="string_multiple">Множественная строка</option></select>`
            : `<select class="transform"><option value="">Без изменений</option><option value="string" ${current.transform === 'string' ? 'selected' : ''}>Строка</option><option value="date" ${current.transform === 'date' ? 'selected' : ''}>Дата</option><option value="money" ${current.transform === 'money' ? 'selected' : ''}>Деньги</option><option value="boolean" ${current.transform === 'boolean' ? 'selected' : ''}>Да / Нет</option></select>`}
        </div>`;
      }).join('')}
    </div>`;
    document.getElementById('mapping-provider').addEventListener('change', async event => {
      const previousProvider = state.selectedProvider;
      const nextProvider = event.target.value;
      captureCurrentMappingDraft();
      state.selectedProvider = nextProvider;
      try {
        await render();
      } catch (error) {
        state.selectedProvider = previousProvider;
        await render();
        setStatus(error.message, true);
      }
    });
    screen.querySelector('.refresh-fields').addEventListener('click', async event => {
      event.currentTarget.disabled = true;
      try {
        captureCurrentMappingDraft();
        state.fields = await loadSmartProcessFields(state.settings.entityTypeId, true);
        await render();
      } catch (error) {
        event.currentTarget.disabled = false;
        setStatus(error.message, true);
      }
    });
    screen.querySelectorAll('.create-field').forEach(button => {
      button.addEventListener('click', async () => {
        const row = button.closest('.mapping-row');
        const sourceField = row?.dataset.source || '';
        const fieldDefinition = definition.fieldDefinitions?.[sourceField] || {};
        if (!sourceField) return;
        try {
          captureCurrentMappingDraft();
          button.disabled = true;
          const existingCode = button.dataset.existingCode;
          let targetField = existingCode;
          let created = false;
          if (!targetField) {
            button.textContent = 'Создаём…';
            const result = await api('smart-process-fields.create', {
              method: 'POST',
              timeoutMs: 45000,
              body: { entityTypeId: state.settings.entityTypeId, sourceField }
            });
            state.fields = result.fields;
            writeCache(`fields:${state.settings.entityTypeId}`, state.fields);
            targetField = result.field.code;
            created = result.created;
          }
          const mappings = (state.mappings[state.selectedProvider] || [])
            .filter(item => item.sourceField !== sourceField);
          mappings.push({
            sourceField,
            targetField,
            transform: fieldDefinition.transform || (sourceField === 'ticket_file' ? 'file' : 'string')
          });
          state.mappings[state.selectedProvider] = mappings;
          state.dirtyMappings[state.selectedProvider] = true;
          await render();
          setStatus(created ? 'Поле создано и выбрано. Сохраните сопоставления.' : 'Готовое поле выбрано. Сохраните сопоставления.');
        } catch (error) {
          button.disabled = false;
          button.textContent = button.dataset.existingCode ? 'Выбрать готовое' : 'Создать поле';
          setStatus(error.message, true);
        }
      });
    });
    screen.querySelectorAll('.mapping-row[data-source="ticket_file"] .target-field, .mapping-row[data-source="additional_service_files"] .target-field').forEach(select => {
      select.addEventListener('change', () => {
        const field = state.fields.find(item => item.code === select.value);
        const transform = select.closest('.mapping-row')?.querySelector('.transform');
        if (transform && field) transform.value = field.multiple ? 'file_multiple' : 'file';
      });
    });
  }

  function currentMappingRows() {
    return [...screen.querySelectorAll('.mapping-row')].map(row => {
      const sourceField = row.dataset.source;
      const targetField = row.querySelector('.target-field').value;
      const targetDefinition = state.fields.find(field => field.code === targetField);
      return {
        sourceField,
        targetField,
        transform: ['ticket_file', 'additional_service_files'].includes(sourceField) && targetDefinition
          ? (targetDefinition.multiple ? 'file_multiple' : 'file')
          : row.querySelector('.transform').value
      };
    }).filter(item => item.targetField);
  }

  function captureCurrentMappingDraft() {
    if (!state.selectedProvider || !screen.querySelector('.mapping-row')) return;
    state.mappings[state.selectedProvider] = currentMappingRows();
    state.dirtyMappings[state.selectedProvider] = true;
  }

  async function saveProviderMappings(provider) {
    const mappings = state.mappings[provider] || [];
    if (!mappings.some(item => item.sourceField === 'external_id')) {
      const title = state.catalog.find(item => item.code === provider)?.title || provider;
      throw new Error(`Для «${title}» сопоставьте обязательное поле external_id — оно защищает от дублей.`);
    }
    await api('mappings.save', { method: 'POST', body: { provider, mappings } });
    state.dirtyMappings[provider] = false;
  }

  async function saveDirtyMappings() {
    captureCurrentMappingDraft();
    for (const provider of Object.keys(state.dirtyMappings).filter(code => state.dirtyMappings[code])) {
      await saveProviderMappings(provider);
    }
  }

  async function loadEntityCategories(entityTypeId, forceRefresh = false) {
    const cacheName = `categories:${entityTypeId}`;
    const cached = readCache(cacheName);
    if (!forceRefresh && Array.isArray(cached)) return cached;
    const categories = await singleFlight(`${cacheName}:${forceRefresh ? 'refresh' : 'normal'}`, async () =>
      normalizeCategories(await api('smart-process-categories', {
        query: { entityTypeId, ...(forceRefresh ? { refresh: 1 } : {}) },
        timeoutMs: 20000
      }))
    );
    writeCache(cacheName, categories);
    return categories;
  }

  async function loadEntityStages(entityTypeId, categoryId, forceRefresh = false) {
    const cacheName = `stages:${entityTypeId}:${categoryId}`;
    const cached = readCache(cacheName);
    if (!forceRefresh && Array.isArray(cached)) return cached;
    const stages = await singleFlight(`${cacheName}:${forceRefresh ? 'refresh' : 'normal'}`, async () =>
      normalizeStages(await api('smart-process-stages', {
        query: { entityTypeId, categoryId, ...(forceRefresh ? { refresh: 1 } : {}) },
        timeoutMs: 20000
      }))
    );
    writeCache(cacheName, stages);
    return stages;
  }

  async function ensureUsers(forceRefresh = false) {
    if (state.usersLoaded && !forceRefresh) return;
    try {
      const cached = readCache('users');
      state.users = !forceRefresh && Array.isArray(cached) ? cached : await singleFlight(
        `users:${forceRefresh ? 'refresh' : 'normal'}`,
        async () => {
          const users = normalizeUsers(await api('bitrix-users', {
            query: forceRefresh ? { refresh: 1 } : {},
            timeoutMs: 30000
          }));
          if (users.length === 0) throw new Error('Активные сотрудники не найдены.');
          return users;
        }
      );
      writeCache('users', state.users);
      state.usersError = '';
      state.usersLoaded = true;
    } catch (error) {
      state.users = [];
      state.usersError = error.message;
      state.usersLoaded = false;
    }
  }

  function currentExpenseMappings() {
    return [...screen.querySelectorAll('.expense-mapping-row')].map(row => ({
      sourceField: row.dataset.source,
      targetField: row.querySelector('.target-field').value,
      transform: row.querySelector('.transform').value
    })).filter(item => item.targetField);
  }

  function currentExpenseSettings() {
    const current = state.settings.expenseSync || {};
    const entity = document.getElementById('expense-entity');
    if (!entity) return current;
    const category = document.getElementById('expense-category');
    return {
      enabled: document.getElementById('expense-enabled').checked,
      entityTypeId: Number(entity.value) || null,
      categoryId: category.value === '' ? null : Number(category.value),
      stageId: document.getElementById('expense-stage').value,
      assignedById: Number(document.getElementById('expense-assigned').value) || null,
      titleTemplate: document.getElementById('expense-title').value.trim(),
      accommodationTitleTemplate: document.getElementById('expense-accommodation-title').value.trim(),
      createItems: document.getElementById('expense-create').checked,
      updateItems: document.getElementById('expense-update').checked,
      preserveTitleOnUpdate: document.getElementById('expense-preserve-title').checked
    };
  }

  function responsibleOptions(selectedId) {
    const selected = Number(selectedId || 0);
    const options = state.users.map(user => `<option value="${Number(user.id)}" ${selected === Number(user.id) ? 'selected' : ''}>${escapeHtml(user.name)}${user.position ? ` · ${escapeHtml(user.position)}` : ''}</option>`).join('');
    if (selected > 0 && !state.users.some(user => Number(user.id) === selected)) {
      return `<option value="${selected}" selected>Пользователь ${selected} · сохранённое значение</option>${options}`;
    }
    return options;
  }

  function replaceResponsibleOptions(select, selectedId) {
    if (!select) return;
    const current = Number(select.value || selectedId || 0);
    select.innerHTML = `<option value="">По умолчанию Bitrix24</option>${responsibleOptions(current)}`;
    if (current > 0) select.value = String(current);
  }

  async function renderExpenses(renderToken) {
    screen.innerHTML = '<div class="loader">Загружаем настройку затрат…</div>';
    const initialLoads = [];
    if (state.processes.length === 0) {
      initialLoads.push(loadSmartProcesses().then(processes => { state.processes = processes; }));
    }
    if (!state.expenseMappingsLoaded) {
      initialLoads.push(api('expense-mappings.get', { timeoutMs: 15000 }).then(mappings => {
        state.expenseMappings = mappings;
        state.expenseMappingsLoaded = true;
      }));
    }
    try {
      await Promise.all(initialLoads);
    } catch (error) {
      if (renderToken !== state.renderToken || state.step !== 5) return;
      screen.innerHTML = `<div class="empty-state error-text"><h3>Не удалось загрузить настройки затрат</h3><p>${escapeHtml(error.message)}</p><button type="button" class="button retry-expenses">Повторить</button></div>`;
      screen.querySelector('.retry-expenses').addEventListener('click', render);
      return;
    }
    const expense = {
      enabled: false,
      entityTypeId: null,
      categoryId: null,
      stageId: '',
      assignedById: null,
      titleTemplate: 'Затрата: билет {ticket_number} — {passenger}',
      accommodationTitleTemplate: 'Затрата: проживание {hotel_name} — {passenger}',
      createItems: true,
      updateItems: true,
      preserveTitleOnUpdate: false,
      ...(state.settings.expenseSync || {})
    };
    const entityTypeId = Number(expense.entityTypeId || 0);
    if (entityTypeId > 0) {
      const isMainEntity = entityTypeId === Number(state.settings.entityTypeId || 0);
      try {
        const [categories, fields] = await Promise.all([
          state.expenseCategories.length
            ? state.expenseCategories
            : (isMainEntity && state.categories.length ? state.categories : loadEntityCategories(entityTypeId)),
          state.expenseFields.length
            ? state.expenseFields
            : (isMainEntity && state.fields.length ? state.fields : loadSmartProcessFields(entityTypeId))
        ]);
        state.expenseCategories = categories;
        state.expenseFields = fields;
      } catch (error) {
        if (renderToken !== state.renderToken || state.step !== 5) return;
        screen.innerHTML = `<div class="empty-state error-text"><h3>Не удалось обновить справочники затрат</h3><p>${escapeHtml(error.message)}</p><button type="button" class="button retry-expense-metadata">Повторить</button></div>`;
        screen.querySelector('.retry-expense-metadata').addEventListener('click', async () => {
          state.expenseCategories = await loadEntityCategories(entityTypeId, true);
          state.expenseFields = await loadSmartProcessFields(entityTypeId, true);
          await render();
        });
        return;
      }
    }
    if (renderToken !== state.renderToken || state.step !== 5) return;

    const definitions = state.expenseCatalog.fieldDefinitions || {};
    const identityField = state.expenseCatalog.identityField || 'expense_external_id';
    const saved = Object.fromEntries(state.expenseMappings.map(item => [item.sourceField, item]));
    const expenseResponsibleOptions = responsibleOptions(expense.assignedById);
    screen.innerHTML = `<section class="expense-panel">
      <div class="expense-heading"><div><span class="provider-code">Финансовый учёт</span><h3>Синхронизация затрат</h3><p>Одна карточка затраты создаётся на билет или проживание. Возвраты уменьшают фактическую сумму, а штрафы и невозвратная часть сохраняются отдельно.</p></div>
        <label class="toggle-row"><input id="expense-enabled" type="checkbox" ${expense.enabled ? 'checked' : ''}><span>Создавать карточки затрат</span></label></div>
      <div class="expense-settings-grid">
        <label class="large-field"><span>Смарт-процесс затрат</span><select id="expense-entity"><option value="">Выберите процесс</option>${state.processes.map(process => `<option value="${process.entityTypeId}" ${entityTypeId === process.entityTypeId ? 'selected' : ''}>${escapeHtml(process.title)} · ${process.entityTypeId}</option>`).join('')}</select></label>
        <label class="large-field"><span>Воронка</span><select id="expense-category"><option value="">Выберите воронку</option>${state.expenseCategories.map(category => `<option value="${category.id}" ${String(expense.categoryId) === String(category.id) ? 'selected' : ''}>${escapeHtml(category.name)}</option>`).join('')}</select></label>
        <label class="large-field"><span>Начальная стадия</span><select id="expense-stage"><option value="">${expense.categoryId && state.expenseStages.length === 0 ? 'Стадии загружаются…' : 'Стадия по умолчанию'}</option>${state.expenseStages.map(stage => `<option value="${escapeHtml(stage.id)}" ${String(expense.stageId || '') === stage.id ? 'selected' : ''}>${escapeHtml(stage.name)}</option>`).join('')}</select></label>
        <label class="large-field"><span>Ответственный для новых карточек затрат</span><select id="expense-assigned"><option value="">По умолчанию Bitrix24</option>${expenseResponsibleOptions}</select><small id="expense-users-status">${state.usersLoaded ? 'При обновлении ответственный не изменяется.' : 'Сотрудники загружаются отдельно и не блокируют форму. При обновлении ответственный не изменяется.'}</small></label>
        <label class="large-field wide"><span>Шаблон названия карточки затраты</span><input id="expense-title" value="${escapeHtml(expense.titleTemplate)}"></label>
        <label class="large-field wide"><span>Шаблон названия затраты на проживание / хостел</span><input id="expense-accommodation-title" value="${escapeHtml(expense.accommodationTitleTemplate)}"></label>
        <label class="rule-toggle"><input id="expense-create" type="checkbox" ${expense.createItems ? 'checked' : ''}><span><strong>Создавать новые затраты</strong></span></label>
        <label class="rule-toggle"><input id="expense-update" type="checkbox" ${expense.updateItems ? 'checked' : ''}><span><strong>Обновлять сумму и данные</strong></span></label>
        <label class="rule-toggle"><input id="expense-preserve-title" type="checkbox" ${expense.preserveTitleOnUpdate ? 'checked' : ''}><span><strong>Название только при создании</strong></span></label>
      </div>
      <div class="hint compact"><strong>Расчёт:</strong> фактическая затрата = первоначальная покупка + допуслуги − возвращённая сумма. Trivio передаёт сумму возврата и удержания отдельно. Если Aviasales или Smartway сообщает только статус отмены без суммы возврата, прежняя сумма сохраняется, а поле «Точность расчёта» показывает, что операция требует проверки.</div>
      <div class="hint compact"><strong>Правило объединения:</strong> сначала номер билета, затем ID услуги, затем единственный билет того же заказа и пассажира. Проживание создаётся отдельной затратой. Допуслуга без однозначно найденного билета не создаёт отдельную затрату.</div>
      <button type="button" class="button ghost refresh-expense-metadata">Обновить справочники Bitrix24</button>
    </section>
    ${entityTypeId > 0 ? `<div class="mapping-toolbar expense-mapping-toolbar"><strong>Поля карточки затраты</strong><span>${(state.expenseCatalog.fields || []).length} полей · обязательный ID: <code>${escapeHtml(identityField)}</code></span></div>
    <div class="mapping-table"><div class="mapping-head"><span>Поле затраты</span><span>Поле Bitrix24</span><span>Преобразование</span></div>
      ${(state.expenseCatalog.fields || []).map(source => {
        const current = saved[source] || {};
        const definition = definitions[source] || { label: source, type: 'string', transform: 'string' };
        const reusableField = state.expenseFields.find(field => field.title === definition.label && field.type === definition.type);
        return `<div class="mapping-row expense-mapping-row ${source === identityField ? 'identity-row' : ''}" data-source="${escapeHtml(source)}">
          <div class="mapping-source"><strong>${escapeHtml(definition.label)}${source === identityField ? ' · обязательное' : ''}</strong><code>${escapeHtml(source)}</code></div>
          <div class="mapping-target"><select class="target-field"><option value="">Не передавать</option>${state.expenseFields.map(field => `<option value="${escapeHtml(field.code)}" ${current.targetField === field.code ? 'selected' : ''}>${escapeHtml(field.title)} · ${escapeHtml(field.type)}</option>`).join('')}</select>
            <button type="button" class="secondary create-expense-field" data-existing-code="${escapeHtml(reusableField?.code || '')}">${reusableField ? 'Выбрать готовое' : 'Создать поле'}</button></div>
          <select class="transform"><option value="">Без изменений</option><option value="string" ${(current.transform || definition.transform) === 'string' ? 'selected' : ''}>Строка</option><option value="date" ${(current.transform || definition.transform) === 'date' ? 'selected' : ''}>Дата</option><option value="money" ${(current.transform || definition.transform) === 'money' ? 'selected' : ''}>Деньги</option></select>
        </div>`;
      }).join('')}
    </div>` : '<div class="empty-state compact-empty"><h3>Выберите смарт-процесс затрат</h3><p>После выбора появятся воронки и сопоставление полей.</p></div>'}`;

    document.getElementById('expense-entity').addEventListener('change', async event => {
      state.settings.expenseSync = { ...expense, entityTypeId: Number(event.target.value) || null, categoryId: null, stageId: '', enabled: false };
      state.expenseCategories = [];
      state.expenseStages = [];
      state.expenseFields = [];
      state.expenseMappings = [];
      state.expenseMappingsLoaded = true;
      await render();
    });
    screen.querySelector('.refresh-expense-metadata').addEventListener('click', async event => {
      event.currentTarget.disabled = true;
      try {
        state.settings.expenseSync = currentExpenseSettings();
        const selectedEntityTypeId = Number(document.getElementById('expense-entity').value || 0);
        state.processes = await loadSmartProcesses(true);
        state.forceUsersRefresh = true;
        if (selectedEntityTypeId > 0) {
          [state.expenseCategories, state.expenseFields] = await Promise.all([
            loadEntityCategories(selectedEntityTypeId, true),
            loadSmartProcessFields(selectedEntityTypeId, true)
          ]);
          state.expenseStages = [];
          state.forceExpenseStageRefresh = true;
        }
        await render();
      } catch (error) {
        event.currentTarget.disabled = false;
        setStatus(error.message, true);
      }
    });
    document.getElementById('expense-category').addEventListener('change', async event => {
      const stage = document.getElementById('expense-stage');
      if (!event.target.value) {
        stage.innerHTML = '<option value="">Стадия по умолчанию</option>';
        return;
      }
      stage.innerHTML = '<option value="">Загружаем стадии…</option>';
      try {
        state.expenseStages = await loadEntityStages(entityTypeId, Number(event.target.value));
        stage.innerHTML = `<option value="">Стадия по умолчанию</option>${state.expenseStages.map(item => `<option value="${escapeHtml(item.id)}">${escapeHtml(item.name)}</option>`).join('')}`;
      } catch (error) {
        stage.innerHTML = '<option value="">Не удалось загрузить стадии</option>';
        setStatus(error.message, true);
      }
    });
    screen.querySelectorAll('.create-expense-field').forEach(button => button.addEventListener('click', async () => {
      const row = button.closest('.expense-mapping-row');
      const sourceField = row.dataset.source;
      const definition = definitions[sourceField] || {};
      try {
        state.settings.expenseSync = currentExpenseSettings();
        state.expenseMappings = currentExpenseMappings();
        button.disabled = true;
        let targetField = button.dataset.existingCode;
        let created = false;
        if (!targetField) {
          button.textContent = 'Создаём…';
          const result = await api('expense-fields.create', {
            method: 'POST', timeoutMs: 45000, body: { entityTypeId, sourceField }
          });
          state.expenseFields = result.fields;
          writeCache(`fields:${entityTypeId}`, state.expenseFields);
          targetField = result.field.code;
          created = result.created;
        }
        state.expenseMappings = state.expenseMappings.filter(item => item.sourceField !== sourceField);
        state.expenseMappings.push({ sourceField, targetField, transform: definition.transform || 'string' });
        await render();
        setStatus(created ? 'Поле затраты создано и выбрано.' : 'Готовое поле выбрано.');
      } catch (error) {
        button.disabled = false;
        setStatus(error.message, true);
      }
    }));

    if (entityTypeId > 0 && expense.categoryId !== null && expense.categoryId !== '') {
      const forceExpenseStageRefresh = state.forceExpenseStageRefresh;
      state.forceExpenseStageRefresh = false;
      void loadEntityStages(entityTypeId, Number(expense.categoryId), forceExpenseStageRefresh).then(stages => {
        if (renderToken !== state.renderToken || state.step !== 5) return;
        state.expenseStages = stages;
        const select = document.getElementById('expense-stage');
        const selected = String(expense.stageId || select?.value || '');
        if (select) {
          select.innerHTML = `<option value="">Стадия по умолчанию</option>${stages.map(item => `<option value="${escapeHtml(item.id)}" ${selected === item.id ? 'selected' : ''}>${escapeHtml(item.name)}</option>`).join('')}`;
        }
      }).catch(error => {
        if (renderToken === state.renderToken && state.step === 5) setStatus(error.message, true);
      });
    }
    const forceUsersRefresh = state.forceUsersRefresh;
    state.forceUsersRefresh = false;
    void ensureUsers(forceUsersRefresh).then(() => {
      if (renderToken !== state.renderToken || state.step !== 5) return;
      replaceResponsibleOptions(document.getElementById('expense-assigned'), expense.assignedById);
      const status = document.getElementById('expense-users-status');
      if (status) status.textContent = state.usersError || '';
    });
  }

  async function saveExpenses() {
    const enabled = document.getElementById('expense-enabled').checked;
    const entityTypeId = Number(document.getElementById('expense-entity').value) || null;
    const categoryValue = document.getElementById('expense-category').value;
    state.expenseMappings = currentExpenseMappings();
    const identityField = state.expenseCatalog.identityField || 'expense_external_id';
    if (enabled && (!entityTypeId || categoryValue === '')) {
      throw new Error('Для затрат выберите смарт-процесс и воронку.');
    }
    if (enabled && !state.expenseMappings.some(item => item.sourceField === identityField)) {
      throw new Error('Для затрат сопоставьте обязательное поле «Уникальный ID затраты».');
    }
    await api('expense-mappings.save', { method: 'POST', body: { mappings: state.expenseMappings } });
    state.settings.expenseSync = currentExpenseSettings();
    await saveSettings();
  }

  function formatRun(run) {
    const stats = run.stats || {};
    return `<tr><td>${escapeHtml(new Date(run.started_at).toLocaleString('ru-RU'))}</td><td>${escapeHtml(run.provider)}</td>
      <td><span class="run-state ${escapeHtml(run.state)}">${escapeHtml(run.state)}</span></td>
      <td>${stats.received ?? 0}</td><td>${stats.created ?? 0}</td><td>${stats.updated ?? 0}</td><td>${stats.expenseCreated ?? 0} / ${stats.expenseUpdated ?? 0}</td><td>${stats.documents ?? 0}</td><td>${stats.errors ?? 0}</td>
      <td class="run-error">${escapeHtml(run.error_text || '')}</td></tr>`;
  }

  const wait = milliseconds => new Promise(resolve => window.setTimeout(resolve, milliseconds));

  async function waitForSyncRun(runId, provider) {
    const deadline = Date.now() + 10 * 60 * 1000;
    while (Date.now() < deadline) {
      state.syncRuns = await api('sync.runs');
      state.syncRunsLoaded = true;
      const run = state.syncRuns.find(item => item.id === runId);
      const tbody = screen.querySelector('.log-panel tbody');
      if (tbody) {
        tbody.innerHTML = state.syncRuns.length
          ? state.syncRuns.map(formatRun).join('')
          : '<tr><td colspan="10" class="muted">Запусков пока нет</td></tr>';
      }
      if (!run) {
        throw new Error('Поставленная задача исчезла из журнала синхронизации.');
      }
      const stats = run.stats || {};
      if (run.state === 'queued') {
        setStatus(`${provider}: задача ожидает запуска cron-worker…`);
      } else if (run.state === 'running') {
        const total = Number(stats.total || 0);
        setStatus(`${provider}: обработано ${stats.received || 0}${total ? ` из ${total}` : ''}, карточек ${stats.created || 0}, затрат ${stats.expenseCreated || 0}.`);
      } else if (run.state === 'success' || run.state === 'partial' || run.state === 'failed') {
        return run;
      }
      await wait(2000);
    }
    throw new Error('Задача остаётся в очереди более 10 минут. Проверьте cron для bin/worker.php на Timeweb.');
  }

  async function renderRules(renderToken) {
    const settings = state.settings;
    const providers = state.catalog.filter(item => connected(item.code));
    const selectedUserIds = new Set((settings.notificationUserIds || []).map(Number));
    const assignedById = Number(settings.assignedById || 0);
    const ruleResponsibleOptions = responsibleOptions(assignedById);
    const notificationUsers = state.users.length
      ? state.users.map(user => `<label class="notification-user-row">
          <input class="notification-user" type="checkbox" value="${Number(user.id)}" ${selectedUserIds.has(Number(user.id)) ? 'checked' : ''}>
          <span><strong>${escapeHtml(user.name)}</strong>${user.position ? `<small>${escapeHtml(user.position)}</small>` : ''}</span>
        </label>`).join('')
      : `<p class="muted">${state.usersError
          ? `Не удалось загрузить сотрудников: ${escapeHtml(state.usersError)}. <button type="button" class="button ghost retry-users">Повторить загрузку</button>`
          : 'Сотрудники загружаются отдельно. Остальные настройки уже доступны.'}</p>`;
    screen.innerHTML = `<div class="rules-grid">
      <label class="large-field"><span>Интервал синхронизации</span><select id="interval"><option value="5">Каждые 5 минут</option><option value="10">Каждые 10 минут</option><option value="15">Каждые 15 минут</option><option value="30">Каждые 30 минут</option><option value="60">Каждый час</option><option value="180">Каждые 3 часа</option></select></label>
      <label class="large-field"><span>Период автоматической проверки</span><select id="lookback"><option value="1">Последний день</option><option value="3">Последние 3 дня</option><option value="7">Последняя неделя</option><option value="30">Последний месяц</option></select></label>
      <div class="hint compact wide">Период применяется к Aviasales, Smartway и полной сверке Trivio Client API. Токенный Trivio Integration API дополнительно отдаёт очередь новых операций без диапазона дат.</div>
      <label class="large-field wide"><span>Шаблон названия карточки билета / услуги</span><input id="title-template" value="${escapeHtml(settings.titleTemplate || '{provider}: {service_type_label} {ticket_number} — {passenger}')}"></label>
      <label class="large-field wide"><span>Шаблон названия карточки проживания / хостела</span><input id="accommodation-title-template" value="${escapeHtml(settings.accommodationTitleTemplate || '{provider}: проживание {hotel_name} — {passenger}')}"></label>
      <div class="hint compact wide">В шаблонах можно использовать поля в фигурных скобках, например: <code>{provider}</code>, <code>{passenger}</code>, <code>{ticket_number}</code>, <code>{short_order_id}</code>, <code>{hotel_name}</code>, <code>{room_name}</code>, <code>{check_in}</code> и <code>{check_out}</code>. Для Aviasales вместо <code>{ticket_number}</code> в названии используется короткий ID заказа.</div>
      <label class="large-field wide"><span>Ответственный для новых карточек</span><select id="assignedById"><option value="">По умолчанию Bitrix24</option>${ruleResponsibleOptions}</select><small>При последующих обновлениях ответственный в Bitrix24 не изменяется.</small></label>
      <div class="hint compact wide"><strong>Кем создаётся карточка:</strong> пользователь, чей OAuth-токен использует приложение. Поле «Кем создано» в Bitrix24 доступно только для чтения, поэтому выбрать другого создателя через REST нельзя. Ответственного можно выбрать выше.</div>
      ${[
        ['createItems', 'Создавать новые карточки', true],
        ['updateItems', 'Обновлять изменившиеся карточки', true],
        ['preserveTitleOnUpdate', 'Записывать название карточки только при создании', false],
        ['syncReturns', 'Обрабатывать возвраты и отмены', true],
        ['attachDocuments', 'Загружать маршрутные квитанции в файловое поле', false],
        ['enabled', 'Включить автоматическую синхронизацию', false]
      ].map(([key, label, defaultValue]) => `<label class="rule-toggle"><input id="${key}" type="checkbox" ${(settings[key] ?? defaultValue) ? 'checked' : ''}><span><strong>${label}</strong></span></label>`).join('')}
    </div>
    <section class="sync-panel"><div><h3>Проверочный запуск</h3><p>Повторно проверяет выбранный период и обновляет изменившиеся карточки. Дубли не создаются.</p></div>
      <div class="sync-buttons">${providers.map(provider => `<span class="sync-provider-actions">
        <button class="button sync-now" data-provider="${provider.code}" data-title="${escapeHtml(provider.title)}" data-mode="manual">${escapeHtml(provider.title)}</button>
        <button class="button small ghost sync-now" data-provider="${provider.code}" data-title="${escapeHtml(provider.title)}" data-mode="rescan">Перепроверить историю</button>
      </span>`).join('')}</div>
    </section>
    <section class="log-panel"><div class="log-heading"><h3>Журнал синхронизации</h3><button class="button small refresh-log">Обновить</button></div>
      <div class="log-scroll"><table><thead><tr><th>Начало</th><th>Источник</th><th>Статус</th><th>Получено</th><th>Создано</th><th>Обновлено</th><th>Затраты + / ↑</th><th>Файлы</th><th>Ошибки</th><th>Сообщение</th></tr></thead>
      <tbody>${state.syncRuns.length ? state.syncRuns.map(formatRun).join('') : '<tr><td colspan="10" class="muted">Запусков пока нет</td></tr>'}</tbody></table></div>
    </section>
    <section class="support-panel">
      <div><span class="provider-code">Travel Sync ${escapeHtml(state.support.version || '')}</span><h3>Поддержка и данные</h3></div>
      <p>Диагностический журнал хранится ${escapeHtml(state.support.retentionDays || 90)} дней. OAuth-токены и ключи удаляются при удалении приложения.</p>
      <div>${state.support.email ? `<a href="mailto:${escapeHtml(state.support.email)}">${escapeHtml(state.support.email)}</a>` : '<span class="muted">Укажите SUPPORT_EMAIL перед публикацией</span>'}
      ${state.support.privacyUrl ? ` · <a href="${escapeHtml(state.support.privacyUrl)}" target="_blank" rel="noopener">Политика обработки данных</a>` : ''}</div>
    </section>`;
    screen.querySelector('.sync-panel').insertAdjacentHTML('beforebegin', `<section class="notification-panel">
      <div class="notification-heading">
        <div><span class="provider-code">Bitrix24</span><h3>Уведомления после автоматической синхронизации</h3></div>
        <label class="toggle-row"><input id="notificationEnabled" type="checkbox" ${settings.notificationEnabled ? 'checked' : ''}><span>Отправлять уведомления</span></label>
      </div>
      <p>Выбранные сотрудники получат одну сводку: сколько записей вернул API, сколько карточек создано и обновлено, сколько возникло ошибок.</p>
      <div class="notification-users">${notificationUsers}</div>
      <label class="toggle-row notify-empty"><input id="notifyOnEmpty" type="checkbox" ${(settings.notifyOnEmpty ?? true) ? 'checked' : ''}><span>Уведомлять, даже если изменений и ошибок нет</span></label>
    </section>`);
    document.getElementById('interval').value = String([5, 10, 15, 30, 60, 180].includes(Number(settings.intervalMinutes)) ? Number(settings.intervalMinutes) : 15);
    document.getElementById('lookback').value = String([1, 3, 7, 30].includes(Number(settings.initialLookbackDays)) ? Number(settings.initialLookbackDays) : 7);

    screen.querySelectorAll('.sync-now').forEach(button => button.addEventListener('click', async () => {
      const providerTitle = button.dataset.title || button.dataset.provider;
      if (button.dataset.mode === 'rescan'
        && !window.confirm(`Повторно проверить историю ${providerTitle} и восстановить удалённые карточки? Это может занять длительное время.`)
      ) return;
      button.disabled = true;
      setStatus(`Синхронизируем ${providerTitle}…`);
      try {
        await saveRules();
        const provider = button.dataset.provider;
        const queued = await api('sync.run', {
          method: 'POST',
          body: { provider, mode: button.dataset.mode || 'manual' }
        });
        setStatus(`${providerTitle}: задача поставлена в очередь.`);
        const result = await waitForSyncRun(queued.runId, providerTitle);
        const stats = result.stats || {};
        setStatus(
          `Готово: получено ${stats.received || 0}, карточек создано ${stats.created || 0}, затрат создано ${stats.expenseCreated || 0}, затрат обновлено ${stats.expenseUpdated || 0}, ошибок ${stats.errors || 0}.`,
          result.state === 'failed' || Number(stats.errors || 0) > 0
        );
        await render();
      } catch (error) {
        setStatus(error.message, true);
      } finally {
        button.disabled = false;
      }
    }));
    screen.querySelector('.retry-users')?.addEventListener('click', async () => {
      state.usersLoaded = false;
      state.usersError = '';
      await ensureUsers(true);
      await render();
    });
    screen.querySelector('.refresh-log').addEventListener('click', async () => {
      state.syncRuns = await api('sync.runs');
      state.syncRunsLoaded = true;
      await render();
    });
    if (!state.syncRunsLoaded) {
      void api('sync.runs', { timeoutMs: 15000 }).then(runs => {
        state.syncRuns = runs;
        state.syncRunsLoaded = true;
        if (renderToken !== state.renderToken || state.step !== 6) return;
        const tbody = screen.querySelector('.log-panel tbody');
        if (tbody) {
          tbody.innerHTML = runs.length
            ? runs.map(formatRun).join('')
            : '<tr><td colspan="10" class="muted">Запусков пока нет</td></tr>';
        }
      }).catch(error => {
        if (renderToken === state.renderToken && state.step === 6) setStatus(error.message, true);
      });
    }
    if (!state.usersLoaded) {
      void ensureUsers().then(() => {
        if (renderToken !== state.renderToken || state.step !== 6) return;
        replaceResponsibleOptions(document.getElementById('assignedById'), assignedById);
        const selectedIds = new Set((settings.notificationUserIds || []).map(Number));
        const container = screen.querySelector('.notification-users');
        if (!container) return;
        container.innerHTML = state.users.length
          ? state.users.map(user => `<label class="notification-user-row">
              <input class="notification-user" type="checkbox" value="${Number(user.id)}" ${selectedIds.has(Number(user.id)) ? 'checked' : ''}>
              <span><strong>${escapeHtml(user.name)}</strong>${user.position ? `<small>${escapeHtml(user.position)}</small>` : ''}</span>
            </label>`).join('')
          : `<p class="muted">${state.usersError ? `Не удалось загрузить сотрудников: ${escapeHtml(state.usersError)}.` : 'Активные сотрудники не найдены.'}</p>`;
      });
    }
  }

  async function saveRules() {
    const notificationUserIds = [...document.querySelectorAll('.notification-user:checked')]
      .map(input => Number(input.value))
      .filter(Number.isInteger);
    const notificationEnabled = document.getElementById('notificationEnabled').checked;
    if (notificationEnabled && notificationUserIds.length === 0) {
      throw new Error('Выберите хотя бы одного сотрудника для уведомлений Bitrix24.');
    }
    state.settings = {
      ...state.settings,
      intervalMinutes: Number(document.getElementById('interval').value),
      initialLookbackDays: Number(document.getElementById('lookback').value),
      titleTemplate: document.getElementById('title-template').value.trim(),
      accommodationTitleTemplate: document.getElementById('accommodation-title-template').value.trim(),
      createItems: document.getElementById('createItems').checked,
      updateItems: document.getElementById('updateItems').checked,
      preserveTitleOnUpdate: document.getElementById('preserveTitleOnUpdate').checked,
      syncReturns: document.getElementById('syncReturns').checked,
      attachDocuments: document.getElementById('attachDocuments').checked,
      enabled: document.getElementById('enabled').checked,
      notificationEnabled,
      notificationUserIds,
      assignedById: Number(document.getElementById('assignedById').value) || null,
      notifyOnEmpty: document.getElementById('notifyOnEmpty').checked
    };
    await saveSettings();
  }

  async function saveSettings() {
    await api('settings.save', { method: 'POST', body: { settings: state.settings } });
  }

  async function render() {
    const renderToken = ++state.renderToken;
    updateNavigation();
    try {
      if (state.step === 1) renderProviders();
      if (state.step === 2) await renderProcesses(renderToken);
      if (state.step === 3) await renderRoutes(renderToken);
      if (state.step === 4) await renderMappings(renderToken);
      if (state.step === 5) await renderExpenses(renderToken);
      if (state.step === 6) await renderRules(renderToken);
    } catch (error) {
      if (renderToken !== state.renderToken) return;
      screen.innerHTML = `<div class="empty-state error-text"><h3>Не удалось загрузить шаг</h3><p>${escapeHtml(error.message)}</p></div>`;
    }
  }

  document.querySelectorAll('.step').forEach(button => button.addEventListener('click', async () => {
    const target = Number(button.dataset.step);
    if (target <= state.step) {
      state.step = target;
      await render();
    }
  }));
  document.getElementById('back').addEventListener('click', async () => {
    state.step = Math.max(1, state.step - 1);
    await render();
  });
  let navigationBusy = false;
  document.getElementById('next').addEventListener('click', async event => {
    if (navigationBusy) return;
    const nextButton = event.currentTarget;
    navigationBusy = true;
    nextButton.disabled = true;
    setStatus('Сохраняем…');
    try {
      if (state.step === 2) await saveProcessSelection();
      if (state.step === 3) await saveRoutes();
      if (state.step === 4) await saveDirtyMappings();
      if (state.step === 5) await saveExpenses();
      if (state.step === 6) {
        await saveRules();
        setStatus('Настройки сохранены.');
        return;
      }
      state.step += 1;
      await render();
    } catch (error) {
      setStatus(error.message, true);
    } finally {
      navigationBusy = false;
      nextButton.disabled = false;
    }
  });

  api('bootstrap').then(data => {
    Object.assign(state, data);
    render();
  }).catch(error => {
    screen.innerHTML = `<div class="empty-state error-text"><h3>Ошибка запуска</h3><p>${escapeHtml(error.message)}</p></div>`;
  });
})();

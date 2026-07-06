document.addEventListener('DOMContentLoaded', () => {
  // Inline form validation: mark fields as touched on blur so :invalid styles apply.
  document.querySelectorAll('form').forEach((form) => {
    form.querySelectorAll('input.input, select.input, textarea.input').forEach((field) => {
      const markTouched = () => field.classList.add('was-touched');
      field.addEventListener('blur', markTouched);
      field.addEventListener('invalid', markTouched);
    });
    form.addEventListener('submit', () => {
      form.querySelectorAll('input.input, select.input, textarea.input').forEach((field) => {
        field.classList.add('was-touched');
      });
    });
  });

  const root = document.documentElement;
  const themeToggle = document.querySelector('[data-theme-toggle]');
  const sidebarToggle = document.querySelector('[data-sidebar-toggle]');
  const sidebarCollapse = document.querySelector('[data-sidebar-collapse]');
  const sidebar = document.getElementById('app-sidebar');
  const sidebarOverlay = document.querySelector('[data-sidebar-overlay]');
  const notificationRoot = document.querySelector('[data-notification-root]');
  const printTriggers = document.querySelectorAll('[data-print-trigger]');
  const toasts = document.querySelectorAll('[data-toast]');

  const syncThemeToggle = () => {
    if (!themeToggle) {
      return;
    }

    themeToggle.setAttribute('aria-pressed', root.classList.contains('dark') ? 'true' : 'false');
  };

  const applyTheme = (theme) => {
    root.classList.toggle('dark', theme === 'dark');
    localStorage.setItem('theme', theme);
    syncThemeToggle();
  };

  const setSidebarOpen = (isOpen) => {
    if (!sidebar) {
      return;
    }

    sidebar.classList.toggle('is-open', isOpen);

    if (sidebarToggle) {
      sidebarToggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    }

    if (sidebarOverlay) {
      if (isOpen) {
        sidebarOverlay.removeAttribute('hidden');
      } else {
        sidebarOverlay.setAttribute('hidden', 'hidden');
      }
    }
  };

  const buildSkeletonMarkup = () => `
    <div class="skeleton-card">
      <div class="skeleton-bar" style="width: 42%"></div>
      <div class="skeleton-bar" style="width: 86%"></div>
      <div class="skeleton-bar" style="width: 64%"></div>
    </div>
  `;

  const revealCharts = () => {
    document.querySelectorAll('[data-chart-shell]').forEach((shell) => {
      shell.classList.add('is-ready');
    });
  };

  syncThemeToggle();

  if (localStorage.getItem('sidebar-collapsed') === 'true') {
    document.body.classList.add('sidebar-collapsed');
    if (sidebarCollapse) {
      sidebarCollapse.setAttribute('aria-expanded', 'false');
      sidebarCollapse.setAttribute('aria-label', 'ขยายเมนูด้านข้าง');
    }
  }

  if (themeToggle) {
    themeToggle.addEventListener('click', () => {
      const nextTheme = root.classList.contains('dark') ? 'light' : 'dark';
      applyTheme(nextTheme);
    });
  }

  if (sidebarToggle && sidebar) {
    sidebarToggle.addEventListener('click', () => {
      setSidebarOpen(!sidebar.classList.contains('is-open'));
    });
  }

  if (sidebarCollapse) {
    sidebarCollapse.addEventListener('click', () => {
      const collapsed = document.body.classList.toggle('sidebar-collapsed');
      localStorage.setItem('sidebar-collapsed', collapsed ? 'true' : 'false');
      sidebarCollapse.setAttribute('aria-label', collapsed ? 'ขยายเมนูด้านข้าง' : 'ย่อเมนูด้านข้าง');
      sidebarCollapse.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
    });
  }

  if (sidebarOverlay) {
    sidebarOverlay.addEventListener('click', () => {
      setSidebarOpen(false);
    });
  }

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
      setSidebarOpen(false);
    }
  });

  window.addEventListener('resize', () => {
    if (window.innerWidth > 1024) {
      setSidebarOpen(false);
    }
  });

  printTriggers.forEach(el => el.addEventListener('click', () => window.print()));

  document.addEventListener('click', (event) => {
    const trigger = event.target instanceof Element ? event.target.closest('[data-print-qr-url]') : null;

    if (!(trigger instanceof HTMLElement)) {
      return;
    }

    const qrUrl = trigger.getAttribute('data-print-qr-url') || '';
    if (qrUrl === '') {
      return;
    }

    event.preventDefault();

    const printWindow = window.open('', '_blank', 'noopener');
    if (!printWindow) {
      window.location.href = qrUrl;
      return;
    }

    printWindow.document.open();
    printWindow.document.write(`<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <title>พิมพ์ QR</title>
</head>
<body style="margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#fff;">
</body>
</html>`);
    printWindow.document.close();

    const image = printWindow.document.createElement('img');
    image.src = qrUrl;
    image.alt = 'QR สำหรับแจ้งซ่อม';
    image.style.maxWidth = '90vw';
    image.style.maxHeight = '90vh';
    image.addEventListener('load', () => {
      printWindow.print();
      printWindow.setTimeout(() => printWindow.close(), 150);
    });
    printWindow.document.body.appendChild(image);
  });

  const mobileInfoCards = document.querySelectorAll('[data-mobile-collapsible-info]');

  if (mobileInfoCards.length > 0) {
    const mobileInfoQuery = window.matchMedia('(max-width: 700px)');
    const syncMobileInfoCards = () => {
      mobileInfoCards.forEach((card) => {
        if (!(card instanceof HTMLDetailsElement)) {
          return;
        }

        if (mobileInfoQuery.matches) {
          card.removeAttribute('open');
          return;
        }

        card.setAttribute('open', 'open');
      });
    };

    mobileInfoCards.forEach((card) => {
      const summary = card.querySelector('summary');

      if (!summary) {
        return;
      }

      summary.addEventListener('click', (event) => {
        if (!mobileInfoQuery.matches) {
          event.preventDefault();
        }
      });
    });

    syncMobileInfoCards();

    if (typeof mobileInfoQuery.addEventListener === 'function') {
      mobileInfoQuery.addEventListener('change', syncMobileInfoCards);
    } else if (typeof mobileInfoQuery.addListener === 'function') {
      mobileInfoQuery.addListener(syncMobileInfoCards);
    }
  }

  document.querySelectorAll('[data-ticket-attachment-input]').forEach((input) => {
    const fieldGroup = input.closest('.field-group');
    const rootEl = fieldGroup?.querySelector('[data-ticket-attachment-root]');
    const status = fieldGroup?.querySelector('[data-ticket-attachment-status]');
    const preview = fieldGroup?.querySelector('[data-ticket-attachment-preview]');
    let objectUrls = [];

    if (!(input instanceof HTMLInputElement) || !status || !preview) {
      return;
    }

    const maxFiles = 3;
    const formatter = new Intl.NumberFormat('th-TH', { maximumFractionDigits: 1 });

    const clearPreview = () => {
      objectUrls.forEach((url) => URL.revokeObjectURL(url));
      objectUrls = [];
      preview.replaceChildren();
      preview.setAttribute('hidden', 'hidden');
      status.classList.remove('is-warning');
      if (rootEl) {
        rootEl.classList.remove('has-files', 'has-warning');
      }
    };

    const formatFileSize = (bytes) => {
      if (!Number.isFinite(bytes) || bytes <= 0) {
        return '0 KB';
      }

      if (bytes >= 1024 * 1024) {
        return `${formatter.format(bytes / (1024 * 1024))} MB`;
      }

      return `${formatter.format(bytes / 1024)} KB`;
    };

    const renderPreview = () => {
      clearPreview();

      const files = Array.from(input.files || []);

      if (files.length === 0) {
        status.textContent = 'ยังไม่ได้เลือกรูป';
        return;
      }

      const isOverLimit = files.length > maxFiles;
      status.textContent = isOverLimit
        ? `เลือกแล้ว ${files.length} รูป จากสูงสุด ${maxFiles} รูป ระบบรับได้สูงสุด ${maxFiles} รูปต่อครั้ง`
        : `เลือกแล้ว ${files.length} รูป จากสูงสุด ${maxFiles} รูป`;
      status.classList.toggle('is-warning', isOverLimit);

      if (rootEl) {
        rootEl.classList.add('has-files');
        rootEl.classList.toggle('has-warning', isOverLimit);
      }

      files.forEach((file) => {
        const card = document.createElement('div');
        card.className = 'attachment-preview-card';
        card.setAttribute('role', 'listitem');

        const thumb = document.createElement('div');
        thumb.className = 'attachment-preview-thumb';

        if (file.type.startsWith('image/')) {
          const image = document.createElement('img');
          const url = URL.createObjectURL(file);
          objectUrls.push(url);
          image.src = url;
          image.alt = `ตัวอย่างรูป ${file.name}`;
          thumb.appendChild(image);
        } else {
          thumb.textContent = 'ไฟล์';
        }

        const meta = document.createElement('div');
        meta.className = 'attachment-preview-meta';

        const name = document.createElement('strong');
        name.textContent = file.name || 'รูปประกอบ';

        const size = document.createElement('span');
        size.textContent = formatFileSize(file.size);

        meta.append(name, size);
        card.append(thumb, meta);
        preview.appendChild(card);
      });

      preview.removeAttribute('hidden');
    };

    input.addEventListener('change', renderPreview);
  });

  document.querySelectorAll('[data-comment-thread]').forEach((thread) => {
    const setEditingState = (item, isEditing) => {
      const view = item.querySelector('[data-comment-view]');
      const panel = item.querySelector('[data-comment-edit-panel]');

      if (!view || !panel) {
        return;
      }

      if (isEditing) {
        view.setAttribute('hidden', 'hidden');
        panel.removeAttribute('hidden');

        const textarea = panel.querySelector('[data-comment-edit-textarea]');
        if (textarea instanceof HTMLTextAreaElement) {
          textarea.focus();
          const length = textarea.value.length;
          textarea.setSelectionRange(length, length);
        }

        return;
      }

      panel.setAttribute('hidden', 'hidden');
      view.removeAttribute('hidden');
    };

    const setErrorState = (item, message) => {
      const error = item.querySelector('[data-comment-edit-error]');

      if (!error) {
        return;
      }

      if (!message) {
        error.textContent = '';
        error.setAttribute('hidden', 'hidden');
        return;
      }

      error.textContent = String(message);
      error.removeAttribute('hidden');
    };

    const setSavingState = (form, isSaving) => {
      const submitButton = form.querySelector('button[type="submit"]');

      if (!(submitButton instanceof HTMLButtonElement)) {
        return;
      }

      if (isSaving) {
        submitButton.disabled = true;
        submitButton.dataset.originalLabel = submitButton.innerHTML;
        submitButton.innerHTML = '<span>กำลังบันทึก...</span>';
        return;
      }

      submitButton.disabled = false;
      if (submitButton.dataset.originalLabel) {
        submitButton.innerHTML = submitButton.dataset.originalLabel;
      }
    };

    const applyCommentUpdate = (item, comment) => {
      const body = item.querySelector('[data-comment-body]');
      if (body) {
        body.textContent = String(comment.body || '');
      }

      const textarea = item.querySelector('[data-comment-edit-textarea]');
      if (textarea instanceof HTMLTextAreaElement) {
        textarea.value = String(comment.body || '');
      }

      const badgeRoot = item.querySelector('[data-comment-badge]');
      if (badgeRoot) {
        let badge = badgeRoot.querySelector('.badge');

        if (!badge) {
          badge = document.createElement('span');
          badgeRoot.appendChild(badge);
        }

        badge.className = `badge badge-${String(comment.visibility_tone || 'default')}`;
        badge.textContent = String(comment.visibility_label || 'Public');
      }
    };

    thread.querySelectorAll('[data-comment-item]').forEach((item) => {
      const editToggle = item.querySelector('[data-comment-edit-toggle]');
      const cancelToggle = item.querySelector('[data-comment-edit-cancel]');
      const editForm = item.querySelector('[data-comment-edit-form]');

      if (editToggle) {
        editToggle.addEventListener('click', (event) => {
          event.preventDefault();

          thread.querySelectorAll('[data-comment-item]').forEach((currentItem) => {
            setErrorState(currentItem, '');
            setEditingState(currentItem, currentItem === item);
          });
        });
      }

      if (cancelToggle) {
        cancelToggle.addEventListener('click', (event) => {
          event.preventDefault();
          setErrorState(item, '');
          setEditingState(item, false);
        });
      }

      if (editForm instanceof HTMLFormElement) {
        editForm.addEventListener('submit', async (event) => {
          event.preventDefault();
          setErrorState(item, '');
          setSavingState(editForm, true);

          try {
            const response = await fetch(editForm.action, {
              method: 'POST',
              headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
              },
              body: new FormData(editForm),
              credentials: 'same-origin',
            });

            const data = await response.json();

            if (!response.ok || !data.success) {
              throw new Error(String(data.message || 'ไม่สามารถบันทึกการแก้ไขได้'));
            }

            applyCommentUpdate(item, data.comment || {});
            setEditingState(item, false);
          } catch (error) {
            setErrorState(item, error instanceof Error ? error.message : 'ไม่สามารถบันทึกการแก้ไขได้');
          } finally {
            setSavingState(editForm, false);
          }
        });
      }
    });
  });

  toasts.forEach((toast) => {
    const closeButton = toast.querySelector('[data-toast-close]');
    const dismiss = () => {
      toast.classList.add('is-leaving');
      window.setTimeout(() => {
        toast.remove();
      }, 180);
    };

    if (closeButton) {
      closeButton.addEventListener('click', dismiss);
    }

    // Errors/warnings stay until dismissed (user must read them); success/info
    // auto-dismiss after 4.2s. Hovering pauses the auto-dismiss timer.
    var isPersistent = toast.classList.contains('toast-danger')
      || toast.classList.contains('toast-warning');
    if (!isPersistent) {
      var timer = window.setTimeout(dismiss, 4200);
      toast.addEventListener('mouseenter', function () { window.clearTimeout(timer); });
      toast.addEventListener('mouseleave', function () { timer = window.setTimeout(dismiss, 2000); });
    }
  });

  const dashboardChartPayload = document.getElementById('dashboard-charts-data');

  if (dashboardChartPayload && typeof window.Chart !== 'undefined') {
    try {
      const payload = JSON.parse(dashboardChartPayload.textContent || '{}');
      const textColor = root.classList.contains('dark') ? '#cbd5e1' : '#334155';
      const gridColor = root.classList.contains('dark') ? 'rgba(148, 163, 184, 0.18)' : 'rgba(148, 163, 184, 0.22)';
      const palette = ['#4f46e5', '#0ea5e9', '#10b981', '#f59e0b', '#f43f5e', '#8b5cf6', '#14b8a6', '#f97316'];

      document.querySelectorAll('[data-dashboard-chart]').forEach((canvas) => {
        const key = canvas.getAttribute('data-dashboard-chart');
        const chartType = canvas.getAttribute('data-chart-type') || 'bar';
        const chartData = payload[key || ''];

        // Accept either a single series ({data:[]}) or multiple series ({datasets:[{label,data}]}).
        // Guard !chartData FIRST — a canvas whose key is absent from the payload must skip only itself,
        // not throw (which the shared try/catch would swallow, killing every chart on the page).
        if (!chartData || !Array.isArray(chartData.labels)) {
          return;
        }
        const hasDatasets = Array.isArray(chartData.datasets);
        if (!Array.isArray(chartData.data) && !hasDatasets) {
          return;
        }

        const styleDataset = (source, index) => {
          const color = palette[index % palette.length];
          const dataset = {
            label: source.label || 'Dataset',
            data: source.data,
            borderWidth: 2,
          };

          if (chartType === 'line') {
            dataset.borderColor = color;
            dataset.backgroundColor = hasDatasets ? 'transparent' : 'rgba(79, 70, 229, 0.16)';
            dataset.fill = !hasDatasets;
            dataset.tension = 0.35;
            dataset.pointRadius = 3;
            dataset.spanGaps = true;
          } else if (chartType === 'doughnut') {
            dataset.backgroundColor = chartData.labels.map((_, i) => palette[i % palette.length]);
            dataset.borderColor = root.classList.contains('dark') ? '#020617' : '#ffffff';
          } else {
            dataset.backgroundColor = color;
            dataset.borderRadius = 8;
          }

          return dataset;
        };

        const datasets = hasDatasets
          ? chartData.datasets.map((source, index) => styleDataset(source, index))
          : [styleDataset({ label: chartData.label, data: chartData.data }, 0)];

        new window.Chart(canvas, {
          type: chartType,
          data: {
            labels: chartData.labels,
            datasets: datasets,
          },
          options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
              legend: {
                display: chartType === 'doughnut' || hasDatasets,
                labels: {
                  color: textColor,
                },
              },
            },
            scales: chartType === 'doughnut' ? {} : {
              x: {
                ticks: { color: textColor },
                grid: { color: gridColor },
              },
              y: {
                beginAtZero: true,
                ticks: { color: textColor },
                grid: { color: gridColor },
              },
            },
          },
        });
      });

      revealCharts();
    } catch (error) {
      revealCharts();
    }
  } else {
    revealCharts();
  }

  if (notificationRoot) {
    const toggle = notificationRoot.querySelector('[data-notification-toggle]');
    const menu = notificationRoot.querySelector('[data-notification-menu]');
    const list = notificationRoot.querySelector('[data-notification-list]');
    const count = notificationRoot.querySelector('[data-notification-count]');
    const close = notificationRoot.querySelector('[data-notification-close]');
    const backdrop = notificationRoot.querySelector('[data-notification-backdrop]');
    const feedUrl = notificationRoot.getAttribute('data-feed-url');

    if (backdrop) {
      document.body.appendChild(backdrop);
    }

    if (menu) {
      document.body.appendChild(menu);
    }

    const escapeHtml = (value) => String(value || '')
      .replaceAll('&', '&amp;')
      .replaceAll('<', '&lt;')
      .replaceAll('>', '&gt;')
      .replaceAll('"', '&quot;')
      .replaceAll("'", '&#039;');

    const renderItems = (items) => {
      if (!list) {
        return;
      }

      if (!Array.isArray(items) || items.length === 0) {
        list.innerHTML = `
          <div class="notification-empty">
            <span class="notification-empty-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.1V12a10 10 0 1 1-5.9-9.1"/><path d="m9 11 3 3L22 4"/></svg></span>
            <div><p class="notification-title">ไม่มีข้อความใหม่</p><p class="notification-copy">ไม่มี Ticket ที่มีอัปเดตในขณะนี้</p></div>
          </div>`;
        return;
      }

      list.innerHTML = items.map((item) => `
        <a href="${escapeHtml(item.link_url || '/notifications')}" class="notification-item${item.is_read ? '' : ' is-unread'}">
          <span class="notification-status-dot"></span>
          <span class="notification-item-body">
            <span class="notification-title">${escapeHtml(item.title || 'การแจ้งเตือน')}</span>
            <span class="notification-copy">${escapeHtml(item.message || '')}</span>
            <span class="notification-meta"><strong>${escapeHtml(item.category_label || 'Workflow')}</strong><span>·</span><span>${escapeHtml(item.relative_time || item.created_at || '-')}</span>${Number(item.event_count || 1) > 1 ? `<span>· ${Number(item.event_count)} กิจกรรม</span>` : ''}</span>
          </span>
          <span class="notification-item-arrow" title="${escapeHtml(item.action_label || 'เปิด Ticket')}"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg></span>
        </a>
      `).join('');
    };

    const renderCount = (unreadCount) => {
      if (!count) {
        return;
      }

      const nextCount = Number(unreadCount || 0);
      count.textContent = String(nextCount);
      count.classList.toggle('is-hidden', nextCount <= 0);
    };

    const refreshFeed = async () => {
      if (!feedUrl) {
        return;
      }

      try {
        if (list) {
          list.innerHTML = buildSkeletonMarkup();
        }

        const response = await fetch(feedUrl, {
          headers: {
            Accept: 'application/json',
          },
          credentials: 'same-origin',
        });

        if (!response.ok) {
          return;
        }

        const data = await response.json();
        renderItems(Array.isArray(data.items) ? data.items : []);
        renderCount(data.unreadCount || 0);
      } catch (error) {
      }
    };

    if (toggle && menu) {
      const setMenuOpen = (isOpen) => {
        menu.toggleAttribute('hidden', !isOpen);
        backdrop?.toggleAttribute('hidden', !isOpen);
        toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        document.body.classList.toggle('notification-open', isOpen);
      };

      toggle.addEventListener('click', () => {
        const isOpening = menu.hasAttribute('hidden');
        setMenuOpen(isOpening);
        if (isOpening) {
          refreshFeed();
        }
      });

      close?.addEventListener('click', () => setMenuOpen(false));
      backdrop?.addEventListener('click', () => setMenuOpen(false));

      document.addEventListener('click', (event) => {
        if (!(event.target instanceof Node)) {
          return;
        }

        if (!notificationRoot.contains(event.target) && !menu.contains(event.target)) {
          setMenuOpen(false);
        }
      });

      document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !menu.hasAttribute('hidden')) {
          setMenuOpen(false);
          toggle.focus();
        }
      });

      // Poll เฉพาะตอน tab แสดงอยู่ (ประหยัด request ตอน background) + refresh ทันทีเมื่อกลับมา focus
      window.setInterval(function () {
        if (!document.hidden) {
          refreshFeed();
        }
      }, 30000);
      document.addEventListener('visibilitychange', function () {
        if (!document.hidden) {
          refreshFeed();
        }
      });
    }
  }

  // ── Export dropdown: close on outside click ──
  document.addEventListener('click', function (e) {
    document.querySelectorAll('.export-dropdown[open], .ticket-print-menu[open]').forEach(function (d) {
      if (!d.contains(e.target)) d.removeAttribute('open');
    });
  });

  // ── Report table sorting ──
  document.querySelectorAll('.data-table th[data-sort-col]').forEach(function (th) {
    th.addEventListener('click', function () {
      var table = th.closest('table');
      var tbody = table && table.querySelector('tbody');
      if (!tbody) return;

      var col = parseInt(th.getAttribute('data-sort-col'), 10);
      var type = th.getAttribute('data-sort-type') || 'string';
      var asc = !th.classList.contains('sort-asc');

      table.querySelectorAll('th[data-sort-col]').forEach(function (s) {
        s.classList.remove('sort-asc', 'sort-desc');
        s.removeAttribute('aria-sort');
      });
      th.classList.add(asc ? 'sort-asc' : 'sort-desc');
      th.setAttribute('aria-sort', asc ? 'ascending' : 'descending');

      var rows = Array.from(tbody.querySelectorAll('tr'));
      rows.sort(function (a, b) {
        var va = (a.children[col] && a.children[col].textContent || '').trim();
        var vb = (b.children[col] && b.children[col].textContent || '').trim();

        if (type === 'number') {
          return ((parseFloat(va.replace(/[^0-9.\-]/g, '')) || 0)
                - (parseFloat(vb.replace(/[^0-9.\-]/g, '')) || 0)) * (asc ? 1 : -1);
        }
        if (type === 'date') {
          // Capture DD/MM/YYYY plus optional HH:MM so same-day rows sort by time too.
          var pa = va.match(/(\d{2})\/(\d{2})\/(\d{4})(?:\s+(\d{2}):(\d{2}))?/);
          var pb = vb.match(/(\d{2})\/(\d{2})\/(\d{4})(?:\s+(\d{2}):(\d{2}))?/);
          var da = pa ? new Date(pa[3], pa[2] - 1, pa[1], pa[4] || 0, pa[5] || 0).getTime() : 0;
          var db = pb ? new Date(pb[3], pb[2] - 1, pb[1], pb[4] || 0, pb[5] || 0).getTime() : 0;
          return (da - db) * (asc ? 1 : -1);
        }
        return va.localeCompare(vb, 'th') * (asc ? 1 : -1);
      });
      rows.forEach(function (r) { tbody.appendChild(r); });
    });
  });

  // ── Admin search/filter ──
  document.querySelectorAll('[data-search-target]').forEach(function (input) {
    var targetId = input.getAttribute('data-search-target');
    var container = document.querySelector(targetId);
    if (!container) return;

    var countEl = input.closest('.admin-search-box');
    countEl = countEl && countEl.querySelector('.admin-search-count');

    input.addEventListener('input', function () {
      var q = input.value.trim().toLowerCase();
      var items = container.querySelectorAll('.collapsible');
      var shown = 0;

      items.forEach(function (item) {
        var text = (item.querySelector('.collapsible-summary') || item).textContent.toLowerCase();
        var match = !q || text.indexOf(q) !== -1;
        item.classList.toggle('search-hidden', !match);
        if (match) shown++;
      });

      if (countEl) {
        countEl.textContent = q ? shown + ' / ' + items.length + ' รายการ' : '';
      }
    });
  });

  // ── Form submit loading state ──
  document.querySelectorAll('form[data-loading-submit]').forEach(function (form) {
    form.addEventListener('submit', function () {
      var btn = form.querySelector('button[type="submit"]');
      if (!btn || btn.disabled) return;
      btn.classList.add('is-loading');
      btn.disabled = true;
      setTimeout(function () {
        btn.classList.remove('is-loading');
        btn.disabled = false;
      }, 8000);
    });
  });

  // ── Ticket bulk approve ──
  (function () {
    var checkboxes = document.querySelectorAll('[data-bulk-checkbox]');
    var bar = document.getElementById('bulk-action-bar');
    var idsInput = document.querySelector('[data-bulk-ids]');
    var countEl = document.querySelector('[data-bulk-count]');
    var selectAll = document.querySelector('[data-bulk-select-all]');
    if (checkboxes.length === 0 || !bar || !idsInput || !countEl) return;

    function refresh() {
      var ids = [];
      checkboxes.forEach(function (cb) {
        if (cb.checked) ids.push(cb.value);
      });
      idsInput.value = ids.join(',');
      countEl.textContent = String(ids.length);
      if (ids.length === 0) {
        bar.setAttribute('hidden', 'hidden');
      } else {
        bar.removeAttribute('hidden');
      }
      if (selectAll) {
        selectAll.checked = ids.length === checkboxes.length;
      }
    }

    checkboxes.forEach(function (cb) {
      cb.addEventListener('change', refresh);
    });

    if (selectAll) {
      selectAll.addEventListener('change', function () {
        checkboxes.forEach(function (cb) { cb.checked = selectAll.checked; });
        refresh();
      });
    }
  })();

  // ── Reveal field when source input is changed from initial value ──
  document.querySelectorAll('[data-reveal-when-changed]').forEach(function (target) {
    var fieldName = target.getAttribute('data-reveal-when-changed');
    if (!fieldName) return;
    var sourceInput = document.querySelector('input[name="' + fieldName + '"]');
    if (!sourceInput) return;

    var initialValue = sourceInput.value;
    var update = function () {
      var changed = sourceInput.value.trim().toLowerCase() !== initialValue.trim().toLowerCase();
      if (changed) {
        target.removeAttribute('hidden');
      } else {
        target.setAttribute('hidden', 'hidden');
        // Clear any input inside when hiding so it doesn't submit
        target.querySelectorAll('input').forEach(function (i) { i.value = ''; });
      }
    };
    sourceInput.addEventListener('input', update);
    sourceInput.addEventListener('change', update);
  });

  // ── Unsaved changes warning ──
  document.querySelectorAll('form[data-warn-unsaved]').forEach(function (form) {
    var isDirty = false;
    var markDirty = function () { isDirty = true; };
    form.addEventListener('input', markDirty);
    form.addEventListener('change', markDirty);
    form.addEventListener('submit', function () { isDirty = false; });
    window.addEventListener('beforeunload', function (e) {
      if (!isDirty) return;
      e.preventDefault();
      e.returnValue = '';
    });
  });

  // ── Inline help tooltips for form fields ──
  document.querySelectorAll('[data-info-toggle]').forEach(function (trigger) {
    trigger.addEventListener('click', function (event) {
      event.preventDefault();
      var targetId = trigger.getAttribute('data-info-toggle');
      var target = document.getElementById(targetId);
      if (!target) return;
      var expanded = trigger.getAttribute('aria-expanded') === 'true';
      trigger.setAttribute('aria-expanded', expanded ? 'false' : 'true');
      target.hidden = expanded;
    });
  });

  // ── Shared confirm modal handler ──
  // Triggered by [data-confirm-modal-trigger="<modal-id>"]. Finds parent form
  // (if any) and submits via requestSubmit() on confirm so that submit listeners
  // (like data-warn-unsaved) fire correctly. ESC and backdrop close the modal.
  // Pages can hook into population by registering a callback on the trigger:
  //   trigger.__beforeConfirmOpen = (modal) => { ... };
  (function () {
    var triggers = document.querySelectorAll('[data-confirm-modal-trigger]');
    if (triggers.length === 0) return;
    var activeModal = null;
    var activeTrigger = null;

    var closeModal = function () {
      if (!activeModal) return;
      activeModal.hidden = true;
      if (activeTrigger) activeTrigger.focus();
      activeModal = null;
      activeTrigger = null;
    };

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && activeModal) closeModal();
    });

    triggers.forEach(function (trigger) {
      trigger.addEventListener('click', function (event) {
        event.preventDefault();
        var modalId = trigger.getAttribute('data-confirm-modal-trigger');
        var modal = document.getElementById(modalId);
        if (!modal) return;

        // Portal ไป body: .confirm-modal ใช้ position:fixed แต่ถ้าถูก render อยู่ใน
        // container ที่มี transform/filter/backdrop-filter (เช่น .panel-card) จะเกิด
        // containing block → modal ถูกกักในการ์ด ไม่เต็มจอ. ย้ายไป body ให้ fixed อิง viewport.
        if (modal.parentNode !== document.body) { document.body.appendChild(modal); }

        var form = trigger.closest('form');
        if (form && !form.reportValidity()) return;

        if (typeof trigger.__beforeConfirmOpen === 'function') {
          trigger.__beforeConfirmOpen(modal);
        }

        if (document.activeElement instanceof HTMLElement) {
          document.activeElement.blur();
        }

        activeModal = modal;
        activeTrigger = trigger;
        modal.hidden = false;

        var confirmBtn = modal.querySelector('[data-modal-submit]');
        if (confirmBtn) confirmBtn.focus();

        modal.querySelectorAll('[data-modal-close]').forEach(function (el) {
          if (el.__confirmModalBound) return;
          el.__confirmModalBound = true;
          el.addEventListener('click', closeModal);
        });

        if (confirmBtn && !confirmBtn.__confirmModalSubmitBound) {
          confirmBtn.__confirmModalSubmitBound = true;
          confirmBtn.addEventListener('click', function () {
            confirmBtn.disabled = true;
            if (!form) { closeModal(); return; }
            if (typeof form.requestSubmit === 'function') {
              form.requestSubmit();
            } else {
              form.submit();
            }
          });
        }
      });
    });
  })();

  // ── Generic submit confirmation (delegated) ──
  // แทน inline onsubmit="return confirm(...)" ด้วย data-confirm-submit="ข้อความ" ตัวเดียว
  // → รวม pattern ยืนยันการกระทำ destructive (ลบ/นำเข้า/regenerate) ไว้ที่เดียว ไม่ปน JS ใน template.
  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (!form || typeof form.getAttribute !== 'function') { return; }
    var message = form.getAttribute('data-confirm-submit');
    if (message && !window.confirm(message)) { e.preventDefault(); }
  });

  // ── Export loading overlay ──
  // Heavy export links (Excel/PDF/CSV) take 5-10s to prepare server-side. Show
  // a blocking overlay while the browser waits for the response.
  (function () {
    var links = document.querySelectorAll('[data-export-link]');
    if (links.length === 0) return;

    var overlay = null;
    var hideTimer = null;
    var pollTimer = null;

    var ensureOverlay = function () {
      if (overlay) return overlay;
      overlay = document.createElement('div');
      overlay.className = 'export-loading-overlay';
      overlay.hidden = true;
      overlay.setAttribute('role', 'status');
      overlay.setAttribute('aria-live', 'polite');
      overlay.innerHTML = '<div class="export-loading-card">'
        + '<span class="export-loading-spinner" aria-hidden="true"></span>'
        + '<p>กำลังเตรียมไฟล์...</p>'
        + '<p class="helper-text">ระบบจะดาวน์โหลดไฟล์อัตโนมัติเมื่อพร้อม</p>'
        + '</div>';
      document.body.appendChild(overlay);
      return overlay;
    };

    var hide = function () {
      if (overlay) overlay.hidden = true;
      if (hideTimer) { clearTimeout(hideTimer); hideTimer = null; }
      if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
    };

    // Secondary hide signals (some browsers shift focus / navigate on download).
    window.addEventListener('blur', function () { setTimeout(hide, 500); });
    window.addEventListener('pagehide', hide);

    links.forEach(function (link) {
      link.addEventListener('click', function () {
        // Don't intercept — let the browser handle the download. Show the overlay, then detect
        // "download started" via a server-echoed cookie: attachment responses don't navigate away,
        // so blur/pagehide are unreliable. Send a token in the form; Response::download echoes it
        // back as a `fileDownload` cookie; poll for it and hide the instant it appears.
        var ov = ensureOverlay();
        ov.hidden = false;

        var token = String(Date.now()) + Math.random().toString(36).slice(2);
        var form = typeof link.closest === 'function' ? link.closest('form') : null;
        if (form) {
          var field = form.querySelector('input[name="_download_token"]');
          if (!field) {
            field = document.createElement('input');
            field.type = 'hidden';
            field.name = '_download_token';
            form.appendChild(field);
          }
          field.value = token;
        }

        if (pollTimer) clearInterval(pollTimer);
        pollTimer = setInterval(function () {
          if (document.cookie.indexOf('fileDownload=' + token) !== -1) {
            document.cookie = 'fileDownload=; Max-Age=0; path=/';
            hide();
          }
        }, 250);

        // Ultimate fallback: auto-hide after 30s if no signal ever arrives.
        if (hideTimer) clearTimeout(hideTimer);
        hideTimer = setTimeout(hide, 30000);
      });
    });
  })();

  // ── Live SLA countdown ──
  // เป้า SLA เดิม render เป็น label นิ่งฝั่ง server; นับถอยหลังสดฝั่ง client (ใช้ epoch → timezone-safe)
  (function () {
    var els = document.querySelectorAll('[data-sla-countdown]');
    if (els.length === 0) return;

    var fmt = function (ms) {
      var totalMin = Math.floor(Math.abs(ms) / 60000);
      var d = Math.floor(totalMin / 1440);
      var h = Math.floor((totalMin % 1440) / 60);
      var m = totalMin % 60;
      if (d > 0) return d + ' วัน ' + h + ' ชม.';
      if (h > 0) return h + ' ชม. ' + m + ' นาที';
      return m + ' นาที';
    };

    var tick = function () {
      var now = Date.now();
      els.forEach(function (el) {
        var ts = parseInt(el.getAttribute('data-sla-target-ts'), 10);
        if (!ts) return;
        var diff = ts * 1000 - now;
        var label = el.querySelector('[data-sla-countdown-label]') || el;
        label.textContent = (diff > 0 ? 'เหลือ ' : 'เกินกำหนด ') + fmt(diff);
        el.classList.toggle('sla-countdown-overdue', diff <= 0);
      });
    };

    tick();
    window.setInterval(tick, 30000);
  })();

  // ── Live queue poll (generic) ──
  // หน้าคิว (เช่น guest requests) poll ค่า numeric (data-live-poll-key) เทียบ baseline →
  // ถ้าเพิ่ม (มีรายการใหม่) โชว์ banner ให้โหลดใหม่. pause เมื่อ tab ซ่อน; ไม่ auto-reload. ไม่ WebSocket.
  (function () {
    var roots = document.querySelectorAll('[data-live-poll]');
    if (roots.length === 0) return;

    roots.forEach(function (root) {
      var url = root.getAttribute('data-live-poll-url');
      var key = root.getAttribute('data-live-poll-key') || 'value';
      var baseline = parseInt(root.getAttribute('data-live-poll-baseline'), 10) || 0;
      var banner = root.querySelector('[data-live-poll-banner]');
      var reloadBtn = root.querySelector('[data-live-poll-reload]');
      var notified = false;
      if (!url) return;

      if (reloadBtn) {
        reloadBtn.addEventListener('click', function () { window.location.reload(); });
      }

      var check = function () {
        if (notified || document.hidden) return;
        fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
          .then(function (r) { return r.ok ? r.json() : null; })
          .then(function (data) {
            if (!data) return;
            var v = parseInt(data[key], 10) || 0;
            if (v > baseline && banner) {
              banner.hidden = false;
              notified = true;
            }
          })
          .catch(function () {});
      };

      window.setInterval(check, 30000);
      document.addEventListener('visibilitychange', function () {
        if (!document.hidden) check();
      });
    });
  })();

  // ── Live ticket queue (auto-refresh + in-place ค้นหา/กรอง/เปลี่ยนหน้า) ──
  // หน้าคิว ticket หลัก อัปเดตในที่โดยไม่ reload ทั้งหน้า 2 ทาง:
  //   1) auto-refresh: poll max_id เมื่อมีงานใหม่ → swap เฉพาะรายการ+การ์ดสรุป (ไม่ยุ่ง filter form
  //      ที่ผู้ใช้อาจกำลังพิมพ์). หลบไปโชว์ banner ให้กดเองเมื่อไม่ปลอดภัย (bulk mode/โฟกัสค้นหา/modal).
  //   2) navigate: submit ค้นหา/กรอง, กด chips/ล้างตัวกรอง, เปลี่ยนหน้า, back/forward → fetch หน้าเดิม
  //      แล้ว swap ทั้ง panel (chips/ตัวกรอง/รายการ/หน้า sync กัน) + pushState. ผูกแบบ delegation จึงรอด
  //      การ swap. bulk view / session หมด → โหลดเต็มปกติ. pause เมื่อ tab ซ่อน. ไม่ WebSocket.
  (function () {
    var root = document.querySelector('[data-ticket-queue-live]');
    if (!root) return;
    var stateUrl = root.getAttribute('data-ticket-queue-state-url');
    var baseline = parseInt(root.getAttribute('data-ticket-queue-baseline'), 10) || 0;
    var banner = root.querySelector('[data-ticket-queue-banner]');
    var reloadBtn = root.querySelector('[data-ticket-queue-reload]');
    var busy = false;

    if (reloadBtn) reloadBtn.addEventListener('click', function () { window.location.reload(); });

    function swapRegion(doc, selector) {
      var incoming = doc.querySelector(selector);
      var current = document.querySelector(selector);
      if (incoming && current) { current.innerHTML = incoming.innerHTML; }
    }
    function flash() {
      var results = document.querySelector('[data-ticket-queue-results]');
      if (!results) return;
      results.style.transition = 'opacity .18s ease';
      results.style.opacity = '0.4';
      window.setTimeout(function () { results.style.opacity = '1'; }, 180);
    }
    // เลื่อนขึ้นหัวตารางแบบนุ่ม (เผื่อความสูง sticky topbar) — ให้เริ่มอ่านหน้าใหม่จากแถวแรกเสมอ
    function scrollToQueueTop() {
      var panel = document.querySelector('[data-ticket-queue-panel]');
      if (!panel) return;
      var topbar = document.querySelector('.topbar');
      var offset = topbar ? Math.round(topbar.getBoundingClientRect().height) + 12 : 0;
      var y = panel.getBoundingClientRect().top + window.scrollY - offset;
      window.scrollTo({ top: Math.max(0, y), behavior: 'smooth' });
    }

    // ---- User navigation: ค้นหา/กรอง/เปลี่ยนหน้า/back-forward แบบ swap ในที่ ----
    function navigate(targetUrl, push) {
      if (busy) return;
      busy = true;
      var ae = document.activeElement;
      var keepSearch = ae && ae.name === 'q' && ae.closest && ae.closest('.ticket-filter-toolbar');
      fetch(targetUrl, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
        .then(function (r) { return r.ok ? r.text() : Promise.reject(); })
        .then(function (html) {
          var doc = new DOMParser().parseFromString(html, 'text/html');
          // login/error หรือ bulk view (checkbox ต้อง bind ตอนโหลด) → โหลดเต็มปกติ
          if (!doc.querySelector('[data-ticket-queue-results]') || doc.querySelector('[data-bulk-root]')) {
            window.location.href = targetUrl; return;
          }
          swapRegion(doc, '[data-ticket-queue-metrics]');
          swapRegion(doc, '[data-ticket-queue-total]');
          swapRegion(doc, '[data-ticket-queue-panel]');   // swap ทั้ง panel → chips/ตัวกรอง/รายการ/หน้า sync
          if (push) { try { window.history.pushState({ tq: 1 }, '', targetUrl); } catch (e) {} }
          if (banner) banner.hidden = true;
          if (keepSearch) {   // คืนโฟกัส+เคอร์เซอร์ให้ช่องค้นหา (ไม่ให้ browser auto-scroll เอง)
            var s = document.querySelector('.ticket-filter-toolbar input[name="q"]');
            if (s) { s.focus({ preventScroll: true }); var v = s.value; s.value = ''; s.value = v; }
          }
          // เปลี่ยนหน้า/กรอง (push) → เลื่อนขึ้นหัวตารางให้อ่านจากแถวแรก; back/forward (ไม่ push) ปล่อยตามเดิม
          if (push) { scrollToQueueTop(); }
          flash();
        })
        .catch(function () { window.location.href = targetUrl; })
        .finally(function () { busy = false; });
    }

    // submit ฟอร์มค้นหา/กรอง (delegation → รอด innerHTML swap)
    document.addEventListener('submit', function (e) {
      var form = e.target;
      if (!form || !form.classList || !form.classList.contains('ticket-filter-toolbar')) return;
      if (!form.closest('[data-ticket-queue-live]')) return;
      e.preventDefault();
      var params = new URLSearchParams();
      new FormData(form).forEach(function (val, key) { if (String(val).trim() !== '') params.append(key, val); });
      var qs = params.toString();
      navigate(form.action + (qs ? '?' + qs : ''), true);
    });

    // chips ลบตัวกรอง / ปุ่มล้างตัวกรอง / pagination (delegation)
    document.addEventListener('click', function (e) {
      var a = e.target && e.target.closest ? e.target.closest('a[href]') : null;
      if (!a || !a.closest('[data-ticket-queue-live]')) return;
      var isQueueNav = a.classList.contains('page-link') || a.closest('.ticket-filter-toolbar');
      if (!isQueueNav) return;
      if (a.classList.contains('is-disabled')) { e.preventDefault(); return; }
      e.preventDefault();
      navigate(a.href, true);
    });

    window.addEventListener('popstate', function () { navigate(window.location.href, false); });

    // ---- Auto-refresh: มีงานใหม่เข้าคิว → swap เฉพาะรายการ (ไม่ยุ่ง filter form) ----
    function canAutoSwap() {
      if (document.querySelector('[data-bulk-root]')) return false;   // bulk view: swap ทำ checkbox หลุด binding
      var ae = document.activeElement;
      if (ae && ae.closest && ae.closest('.ticket-filter-toolbar')) return false;
      if (document.querySelector('.confirm-modal:not([hidden])')) return false;
      return true;
    }
    function autoRefresh(newMax) {
      if (busy) return;
      busy = true;
      fetch(window.location.href, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
        .then(function (r) { return r.ok ? r.text() : null; })
        .then(function (html) {
          if (!html) return;
          var doc = new DOMParser().parseFromString(html, 'text/html');
          if (!doc.querySelector('[data-ticket-queue-results]')) return;
          swapRegion(doc, '[data-ticket-queue-results]');
          swapRegion(doc, '[data-ticket-queue-metrics]');
          swapRegion(doc, '[data-ticket-queue-count]');
          swapRegion(doc, '[data-ticket-queue-total]');
          baseline = newMax;
          if (banner) banner.hidden = true;
          flash();
        })
        .catch(function () {})
        .finally(function () { busy = false; });
    }
    var check = function () {
      if (document.hidden || !stateUrl) return;
      fetch(stateUrl, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (data) {
          if (!data) return;
          var v = parseInt(data.max_id, 10) || 0;
          if (v <= baseline) return;
          if (canAutoSwap()) { autoRefresh(v); }
          else if (banner) { banner.hidden = false; }   // fallback ให้กดโหลดเอง
        })
        .catch(function () {});
    };

    window.setInterval(check, 30000);
    document.addEventListener('visibilitychange', function () { if (!document.hidden) check(); });
  })();
});

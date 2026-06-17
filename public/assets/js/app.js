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
  const printTrigger = document.querySelector('[data-print-trigger]');
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

  if (printTrigger) {
    printTrigger.addEventListener('click', () => {
      window.print();
    });
  }

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

    window.setTimeout(dismiss, 4200);
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

        if (!chartData || !Array.isArray(chartData.labels) || !Array.isArray(chartData.data)) {
          return;
        }

        const dataset = {
          label: chartData.label || 'Dataset',
          data: chartData.data,
          borderWidth: 2,
        };

        if (chartType === 'line') {
          dataset.borderColor = '#4f46e5';
          dataset.backgroundColor = 'rgba(79, 70, 229, 0.16)';
          dataset.fill = true;
          dataset.tension = 0.35;
          dataset.pointRadius = 3;
        } else if (chartType === 'doughnut') {
          dataset.backgroundColor = chartData.labels.map((_, index) => palette[index % palette.length]);
          dataset.borderColor = root.classList.contains('dark') ? '#020617' : '#ffffff';
        } else {
          dataset.backgroundColor = '#4f46e5';
          dataset.borderRadius = 8;
        }

        new window.Chart(canvas, {
          type: chartType,
          data: {
            labels: chartData.labels,
            datasets: [dataset],
          },
          options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
              legend: {
                display: chartType === 'doughnut',
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

      window.setInterval(refreshFeed, 30000);
    }
  }
});

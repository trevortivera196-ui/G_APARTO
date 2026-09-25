/* =========================================================
   OJ APARTMENT — Payroll Dashboard Logic
   =========================================================
   Covers:
   - Staff CRUD (localStorage)
   - Payroll calculation (allowances + deductions)
   - Run Payroll modal with animated M-Pesa steps
   - Withdrawal STK-push simulation
   - Transaction history
   - Settings persistence
   - Tab navigation
   ========================================================= */

/* ── Storage keys ── */
const KEY_STAFF    = 'oj_staff';
const KEY_TX       = 'oj_transactions';
const KEY_SETTINGS = 'oj_payroll_settings';

/* ── Default settings ── */
const DEFAULT_SETTINGS = {
  frequency    : 'Monthly',
  payday       : '28th of every month',
  currency     : 'KES — Kenyan Shilling',
  nhif         : true,
  nssf         : true,
  paye         : true,
  housing      : false,
  houseAllow   : true,
  transport    : true,
  medical      : false,
  overtime     : false,
  customDed    : 0,
  shortcode    : '522522',
  consumerKey  : '',
  consumerSecret: '',
  autoDisburse : false,
  remind       : true,
  sms          : true,
  emailReport  : true
};

/* ── Seed staff data ── */
const SEED_STAFF = [
  { id:1, name:'James Mwangi',   email:'james@ojapt.co.ke',  phone:'722100001', dept:'Management',  role:'Property Manager',  salary:85000, status:'active'   },
  { id:2, name:'Grace Achieng',  email:'grace@ojapt.co.ke',  phone:'733200002', dept:'Reception',   role:'Front Desk Officer', salary:35000, status:'active'   },
  { id:3, name:'Peter Ochieng',  email:'peter@ojapt.co.ke',  phone:'710300003', dept:'Security',    role:'Security Guard',     salary:28000, status:'active'   },
  { id:4, name:'Faith Njeri',    email:'faith@ojapt.co.ke',  phone:'712400004', dept:'Cleaning',    role:'Housekeeper',        salary:22000, status:'active'   },
  { id:5, name:'Samuel Kariuki', email:'samuel@ojapt.co.ke', phone:'725500005', dept:'Maintenance', role:'Plumber / Technician',salary:40000, status:'active'  },
  { id:6, name:'Lucy Wambui',    email:'lucy@ojapt.co.ke',   phone:'714600006', dept:'Cleaning',    role:'Housekeeper',        salary:22000, status:'active'   },
  { id:7, name:'David Otieno',   email:'david@ojapt.co.ke',  phone:'701700007', dept:'Security',    role:'Night Guard',        salary:26000, status:'active'   },
  { id:8, name:'Rose Muthoni',   email:'rose@ojapt.co.ke',   phone:'798800008', dept:'Reception',   role:'Receptionist',       salary:33000, status:'inactive' }
];

/* ── Seed transactions ── */
const SEED_TX = [
  { id:1001, type:'salary',     name:'James Mwangi',   phone:'722100001', amount:78540, status:'success', date:'2026-08-28', reason:'Monthly Salary'  },
  { id:1002, type:'salary',     name:'Grace Achieng',  phone:'733200002', amount:32050, status:'success', date:'2026-08-28', reason:'Monthly Salary'  },
  { id:1003, type:'salary',     name:'Peter Ochieng',  phone:'710300003', amount:25760, status:'success', date:'2026-08-28', reason:'Monthly Salary'  },
  { id:1004, type:'withdrawal', name:'Faith Njeri',    phone:'712400004', amount:10000, status:'success', date:'2026-09-10', reason:'Advance'         },
  { id:1005, type:'bonus',      name:'Samuel Kariuki', phone:'725500005', amount:5000,  status:'success', date:'2026-09-05', reason:'Bonus'           },
  { id:1006, type:'salary',     name:'Lucy Wambui',    phone:'714600006', amount:20240, status:'failed',  date:'2026-08-28', reason:'Monthly Salary'  },
  { id:1007, type:'withdrawal', name:'David Otieno',   phone:'701700007', amount:8000,  status:'pending', date:'2026-09-18', reason:'Allowance'       },
];

/* =========================================================
   INIT
   ========================================================= */
function initPayroll() {
  // Seed data if first run
  if (!localStorage.getItem(KEY_STAFF)) {
    localStorage.setItem(KEY_STAFF, JSON.stringify(SEED_STAFF));
  }
  if (!localStorage.getItem(KEY_TX)) {
    localStorage.setItem(KEY_TX, JSON.stringify(SEED_TX));
  }
  if (!localStorage.getItem(KEY_SETTINGS)) {
    localStorage.setItem(KEY_SETTINGS, JSON.stringify(DEFAULT_SETTINGS));
  }

  // Set sidebar admin name from access data (if logged in via payment)
  const access = getAccessData ? getAccessData() : {};
  if (access.name) {
    const el = document.getElementById('sb-name');
    const av = document.getElementById('sb-avatar');
    if (el) el.textContent = access.name.split(' ')[0];
    if (av) av.textContent = access.name.slice(0,2).toUpperCase();
  }

  renderOverview();
  renderStaffTable();
  renderRunPayroll();
  renderHistory();
  renderWithdrawalList();
  populateWdRecipients();
  loadSettings();
}

/* =========================================================
   DATA HELPERS
   ========================================================= */
function getStaff()    { try { return JSON.parse(localStorage.getItem(KEY_STAFF))    || []; } catch { return []; } }
function getTx()       { try { return JSON.parse(localStorage.getItem(KEY_TX))       || []; } catch { return []; } }
function getSettings() { try { return { ...DEFAULT_SETTINGS, ...JSON.parse(localStorage.getItem(KEY_SETTINGS)) }; } catch { return DEFAULT_SETTINGS; } }

function saveStaffData(data) { localStorage.setItem(KEY_STAFF, JSON.stringify(data)); }
function saveTxData(data)    { localStorage.setItem(KEY_TX, JSON.stringify(data)); }

function addTransaction(tx) {
  const all = getTx();
  tx.id   = Date.now();
  tx.date = new Date().toISOString().split('T')[0];
  all.unshift(tx);
  saveTxData(all);
}

function nextStaffId() {
  const staff = getStaff();
  return staff.length ? Math.max(...staff.map(s => s.id)) + 1 : 1;
}

/* ── Payroll calculation ── */
function calcNetPay(basic, settings) {
  let allowances = 0;
  let deductions = 0;

  if (settings.houseAllow) allowances += Math.round(basic * 0.15);
  if (settings.transport)  allowances += 3000;
  if (settings.medical)    allowances += 2000;

  const gross = basic + allowances;

  if (settings.nhif)   deductions += nhifBand(gross);
  if (settings.nssf)   deductions += Math.min(Math.round(gross * 0.06), 2160);
  if (settings.paye)   deductions += payeBand(gross);
  if (settings.housing) deductions += Math.round(gross * 0.015);
  if (settings.customDed) deductions += Math.round(gross * (parseFloat(settings.customDed) / 100));

  const net = gross - deductions;
  return { allowances, deductions, gross, net: Math.max(net, 0) };
}

function nhifBand(gross) {
  if (gross <= 5999)   return 150;
  if (gross <= 7999)   return 300;
  if (gross <= 11999)  return 400;
  if (gross <= 14999)  return 500;
  if (gross <= 19999)  return 600;
  if (gross <= 24999)  return 750;
  if (gross <= 29999)  return 850;
  if (gross <= 34999)  return 900;
  if (gross <= 39999)  return 950;
  if (gross <= 44999)  return 1000;
  if (gross <= 49999)  return 1100;
  if (gross <= 59999)  return 1200;
  if (gross <= 69999)  return 1300;
  if (gross <= 79999)  return 1400;
  if (gross <= 89999)  return 1500;
  if (gross <= 99999)  return 1600;
  return 1700;
}

function payeBand(gross) {
  // Simplified PAYE (Kenya 2024 bands)
  const personal = 2400;
  let tax = 0;
  if (gross <= 24000)       tax = gross * 0.10;
  else if (gross <= 32333)  tax = 2400 + (gross - 24000) * 0.25;
  else if (gross <= 500000) tax = 4483 + (gross - 32333) * 0.30;
  else                      tax = 144481 + (gross - 500000) * 0.325;
  return Math.max(0, Math.round(tax - personal));
}

function fmtKES(n) {
  return 'KES ' + Math.round(n).toLocaleString();
}

/* =========================================================
   OVERVIEW TAB
   ========================================================= */
function renderOverview() {
  const staff    = getStaff();
  const active   = staff.filter(s => s.status === 'active');
  const settings = getSettings();

  let totalPayroll = 0;
  active.forEach(s => { totalPayroll += calcNetPay(s.salary, settings).net; });

  const tx = getTx();
  const lastPayroll = tx.filter(t => t.type === 'salary' && t.status === 'success').reduce((a, t) => a + t.amount, 0);
  const pending     = tx.filter(t => t.status === 'pending').length;

  const cards = [
    { icon:'👥', label:'Total Staff',       value: staff.length,           sub: active.length + ' active',         cls:'',       badge: '', badgeClass:'' },
    { icon:'💰', label:'Monthly Payroll',   value: fmtKES(totalPayroll),   sub: 'All active employees',             cls:'green',  badge: 'Scheduled', badgeClass:'' },
    { icon:'📲', label:'Last Disbursement', value: fmtKES(lastPayroll),    sub: 'August 2026',                     cls:'blue',   badge: 'Completed', badgeClass:'' },
    { icon:'⏳', label:'Pending Payments',  value: pending,                sub: 'Awaiting confirmation',            cls:'',       badge: pending > 0 ? pending + ' pending' : 'All clear', badgeClass: pending > 0 ? 'warn' : '' },
  ];

  const el = document.getElementById('overview-cards');
  if (!el) return;
  el.innerHTML = cards.map(c => `
    <div class="ov-card ${c.cls}">
      <div class="ov-icon">${c.icon}</div>
      <div class="ov-label">${c.label}</div>
      <div class="ov-value">${c.value}</div>
      <div class="ov-sub">${c.sub}</div>
      ${c.badge ? `<div class="ov-badge ${c.badgeClass}">${c.badge}</div>` : ''}
    </div>`).join('');

  // Recent transactions (last 5)
  const recentEl = document.getElementById('recent-tx-list');
  if (recentEl) recentEl.innerHTML = buildTxHTML(getTx().slice(0, 5));
}

/* =========================================================
   STAFF TAB
   ========================================================= */
function renderStaffTable(filter = '', deptFilter = '') {
  const staff = getStaff();
  const el    = document.getElementById('staff-tbody');
  const cnt   = document.getElementById('staff-count');
  if (!el) return;

  const filtered = staff.filter(s => {
    const matchText = !filter || s.name.toLowerCase().includes(filter) || s.role.toLowerCase().includes(filter);
    const matchDept = !deptFilter || s.dept === deptFilter;
    return matchText && matchDept;
  });

  if (cnt) cnt.textContent = filtered.length;

  el.innerHTML = filtered.map(s => `
    <tr>
      <td data-label="Employee">
        <div class="staff-name-cell">
          <div class="staff-avatar">${s.name.slice(0,2).toUpperCase()}</div>
          <div>
            <div class="staff-name">${s.name}</div>
            <div class="staff-email">${s.email}</div>
          </div>
        </div>
      </td>
      <td data-label="Department"><span class="dept-badge">${s.dept}</span></td>
      <td data-label="Role">${s.role}</td>
      <td data-label="M-Pesa">+254 ${s.phone}</td>
      <td data-label="Salary">${fmtKES(s.salary)}</td>
      <td data-label="Status"><span class="status-badge ${s.status}">${s.status.charAt(0).toUpperCase()+s.status.slice(1)}</span></td>
      <td data-label="Actions" style="white-space:nowrap;">
        <button class="btn btn-outline" style="padding:.3rem .75rem;font-size:.78rem;" onclick="editStaff(${s.id})">✏️ Edit</button>
        <button class="btn" style="padding:.3rem .75rem;font-size:.78rem;background:rgba(224,92,92,.15);color:#e05c5c;border:1px solid rgba(224,92,92,.3);border-radius:8px;cursor:pointer;margin-left:.4rem;" onclick="deleteStaff(${s.id})">🗑️</button>
      </td>
    </tr>`).join('');
}

function filterStaff() {
  const q    = document.getElementById('staff-search')?.value.toLowerCase() || '';
  const dept = document.getElementById('staff-dept-filter')?.value || '';
  renderStaffTable(q, dept);
}

/* ── Add/Edit staff modal ── */
function openAddStaffModal() {
  document.getElementById('add-staff-title').textContent = 'Add Staff Member';
  document.getElementById('edit-staff-id').value = '';
  ['as-name','as-email','as-phone','as-role','as-salary'].forEach(id => {
    document.getElementById(id).value = '';
  });
  document.getElementById('as-dept').value   = 'Management';
  document.getElementById('as-status').value = 'active';
  openModal('add-staff-modal');
}

function editStaff(id) {
  const s = getStaff().find(x => x.id === id);
  if (!s) return;
  document.getElementById('add-staff-title').textContent = 'Edit Staff Member';
  document.getElementById('edit-staff-id').value = id;
  document.getElementById('as-name').value   = s.name;
  document.getElementById('as-email').value  = s.email;
  document.getElementById('as-phone').value  = s.phone;
  document.getElementById('as-dept').value   = s.dept;
  document.getElementById('as-role').value   = s.role;
  document.getElementById('as-salary').value = s.salary;
  document.getElementById('as-status').value = s.status;
  openModal('add-staff-modal');
}

function saveStaff() {
  const name   = document.getElementById('as-name').value.trim();
  const email  = document.getElementById('as-email').value.trim();
  const phone  = document.getElementById('as-phone').value.trim();
  const dept   = document.getElementById('as-dept').value;
  const role   = document.getElementById('as-role').value.trim();
  const salary = parseFloat(document.getElementById('as-salary').value) || 0;
  const status = document.getElementById('as-status').value;
  const editId = document.getElementById('edit-staff-id').value;

  if (!name)  { showToast('Please enter the employee name.', 'error'); return; }
  if (!phone || phone.length < 9) { showToast('Please enter a valid 9-digit M-Pesa number.', 'error'); return; }
  if (salary <= 0) { showToast('Please enter a valid salary.', 'error'); return; }

  const all = getStaff();
  if (editId) {
    const idx = all.findIndex(s => s.id === parseInt(editId));
    if (idx > -1) all[idx] = { ...all[idx], name, email, phone, dept, role, salary, status };
    showToast('✅ Employee updated successfully.');
  } else {
    all.push({ id: nextStaffId(), name, email, phone, dept, role, salary, status });
    showToast('✅ New employee added.');
  }

  saveStaffData(all);
  closeModal('add-staff-modal');
  renderStaffTable();
  renderOverview();
  renderRunPayroll();
  populateWdRecipients();
}

function deleteStaff(id) {
  if (!confirm('Remove this employee from the payroll?')) return;
  const filtered = getStaff().filter(s => s.id !== id);
  saveStaffData(filtered);
  renderStaffTable();
  renderOverview();
  renderRunPayroll();
  populateWdRecipients();
  showToast('Employee removed.');
}

/* =========================================================
   RUN PAYROLL TAB
   ========================================================= */
function renderRunPayroll() {
  const staff    = getStaff().filter(s => s.status === 'active');
  const settings = getSettings();
  const tbody    = document.getElementById('run-payroll-tbody');
  if (!tbody) return;

  let totalNet = 0;

  tbody.innerHTML = staff.map(s => {
    const { allowances, deductions, net } = calcNetPay(s.salary, settings);
    totalNet += net;
    return `
      <tr>
        <td data-label="Employee">
          <div class="staff-name-cell">
            <div class="staff-avatar">${s.name.slice(0,2).toUpperCase()}</div>
            <div class="staff-name">${s.name}</div>
          </div>
        </td>
        <td data-label="Dept"><span class="dept-badge">${s.dept}</span></td>
        <td data-label="Basic">${fmtKES(s.salary)}</td>
        <td data-label="Allowances" style="color:#4caf7d;">+${fmtKES(allowances)}</td>
        <td data-label="Deductions" style="color:#e05c5c;">-${fmtKES(deductions)}</td>
        <td data-label="Net" style="font-weight:700;color:var(--gold);">${fmtKES(net)}</td>
        <td data-label="M-Pesa" style="font-size:.82rem;">+254 ${s.phone}</td>
        <td data-label="Include">
          <label class="toggle-switch" style="width:36px;height:20px;">
            <input type="checkbox" checked onchange="recalcTotal()" data-net="${net}" />
            <span class="toggle-slider"></span>
          </label>
        </td>
      </tr>`;
  }).join('');

  const display = document.getElementById('total-net-display');
  if (display) display.textContent = fmtKES(totalNet);

  // Run payroll summary cards
  const cardsEl = document.getElementById('run-payroll-cards');
  if (cardsEl) {
    cardsEl.innerHTML = `
      <div class="ov-card green"><div class="ov-icon">👥</div><div class="ov-label">Employees</div><div class="ov-value">${staff.length}</div><div class="ov-sub">Active staff</div></div>
      <div class="ov-card"><div class="ov-icon">💰</div><div class="ov-label">Total Payroll</div><div class="ov-value">${fmtKES(totalNet)}</div><div class="ov-sub">Net after deductions</div></div>
      <div class="ov-card blue"><div class="ov-icon">📅</div><div class="ov-label">Pay Period</div><div class="ov-value">Sep 2026</div><div class="ov-sub">Monthly</div></div>`;
  }
}

function recalcTotal() {
  let total = 0;
  document.querySelectorAll('#run-payroll-tbody input[type=checkbox]').forEach(cb => {
    if (cb.checked) total += parseFloat(cb.getAttribute('data-net')) || 0;
  });
  const d = document.getElementById('total-net-display');
  if (d) d.textContent = fmtKES(total);
}

/* ── Run payroll modal ── */
function openRunModal() {
  const staff    = getStaff().filter(s => s.status === 'active');
  const settings = getSettings();

  // Build summary rows
  let totalNet = 0;
  const included = [];
  document.querySelectorAll('#run-payroll-tbody tr').forEach((row, i) => {
    const cb = row.querySelector('input[type=checkbox]');
    if (cb && cb.checked && staff[i]) {
      const { net } = calcNetPay(staff[i].salary, settings);
      totalNet += net;
      included.push(staff[i]);
    }
  });

  const summaryEl = document.getElementById('run-summary');
  if (summaryEl) {
    summaryEl.innerHTML = `
      <div style="background:var(--dark-mid);border-radius:10px;padding:1rem 1.25rem;margin-bottom:1rem;">
        <div class="run-summary-row"><span class="rs-label">Period</span><span class="rs-value">September 2026</span></div>
        <div class="run-summary-row"><span class="rs-label">Employees</span><span class="rs-value">${included.length}</span></div>
        <div class="run-summary-row"><span class="rs-label">Payment Method</span><span class="rs-value">📲 M-Pesa B2C</span></div>
        <div class="run-summary-row"><span class="rs-label">Total Net Payroll</span><span class="rs-value total">${fmtKES(totalNet)}</span></div>
      </div>
      <p style="font-size:.82rem;color:var(--text-muted);">Clicking <strong>Confirm &amp; Disburse</strong> will trigger M-Pesa STK push payments to all ${included.length} employees.</p>`;
  }

  document.getElementById('run-pre-section').style.display = 'block';
  document.getElementById('run-progress-section').style.display = 'none';
  openModal('run-payroll-modal');
}

function startPayrollRun() {
  const staff    = getStaff().filter(s => s.status === 'active');
  const settings = getSettings();

  // Collect checked employees
  const included = [];
  document.querySelectorAll('#run-payroll-tbody tr').forEach((row, i) => {
    const cb = row.querySelector('input[type=checkbox]');
    if (cb && cb.checked && staff[i]) included.push(staff[i]);
  });

  document.getElementById('run-pre-section').style.display = 'none';
  document.getElementById('run-progress-section').style.display = 'block';

  const stepsEl = document.getElementById('run-steps-list');
  const statusEl = document.getElementById('run-status-text');

  stepsEl.innerHTML = `
    <div class="run-step active" id="rs-auth"><span class="rs-icon">🔐</span>Authenticating with Safaricom Daraja API<span class="run-spinner"></span></div>
    <div class="run-step" id="rs-prep"><span class="rs-icon">📋</span>Preparing payroll batch (${included.length} employees)</div>
    <div class="run-step" id="rs-send"><span class="rs-icon">📲</span>Disbursing salaries via M-Pesa B2C</div>
    <div class="run-step" id="rs-confirm"><span class="rs-icon">✅</span>Confirming transactions</div>
    <div class="run-step" id="rs-done"><span class="rs-icon">🎉</span>Payroll complete — receipts sent</div>`;

  let delay = 0;

  function stepDone(id, nextId, label) {
    delay += 1600;
    setTimeout(() => {
      const el = document.getElementById(id);
      if (el) {
        el.classList.remove('active');
        el.classList.add('done');
        el.querySelector('.run-spinner')?.remove();
        el.insertAdjacentHTML('beforeend', '<span class="run-tick">✓</span>');
      }
      const nxt = document.getElementById(nextId);
      if (nxt) {
        nxt.classList.add('active');
        const sp = document.createElement('span');
        sp.className = 'run-spinner';
        nxt.appendChild(sp);
      }
      if (statusEl) statusEl.textContent = label;
    }, delay);
  }

  stepDone('rs-auth',    'rs-prep',    'Preparing payroll data…');
  stepDone('rs-prep',    'rs-send',    `Sending payments to ${included.length} employees…`);
  stepDone('rs-send',    'rs-confirm', 'Confirming with M-Pesa…');
  stepDone('rs-confirm', 'rs-done',    'Finalising records…');

  delay += 2000;
  setTimeout(() => {
    const el = document.getElementById('rs-done');
    if (el) {
      el.classList.remove('active');
      el.classList.add('done');
      el.querySelector('.run-spinner')?.remove();
      el.insertAdjacentHTML('beforeend', '<span class="run-tick">✓</span>');
    }
    if (statusEl) statusEl.textContent = '✅ All salaries disbursed successfully!';

    // Record transactions
    included.forEach(s => {
      const { net } = calcNetPay(s.salary, settings);
      addTransaction({ type:'salary', name:s.name, phone:s.phone, amount:net, status:'success', reason:'Monthly Salary' });
    });

    showToast(`✅ Payroll complete! ${included.length} employees paid via M-Pesa.`);

    setTimeout(() => {
      closeModal('run-payroll-modal');
      renderOverview();
      renderHistory();
      renderWithdrawalList();
    }, 2000);
  }, delay);
}

/* =========================================================
   HISTORY TAB
   ========================================================= */
function renderHistory() {
  const filter = document.getElementById('hist-filter')?.value || '';
  const tx     = getTx().filter(t => !filter || t.type === filter);
  const el     = document.getElementById('history-tx-list');
  if (el) el.innerHTML = buildTxHTML(tx);
}

function buildTxHTML(list) {
  if (!list.length) return '<p style="color:var(--text-muted);text-align:center;padding:2rem;">No transactions found.</p>';
  return list.map(t => {
    const iconMap = { salary:'💼', withdrawal:'📲', bonus:'🎁' };
    const icon    = iconMap[t.type] || '💳';
    const dotCls  = t.status === 'failed' ? 'fail' : t.status === 'pending' ? 'pend' : '';
    const amtCls  = t.status === 'failed' ? '' : 'credit';
    const icoBox  = t.status === 'failed' ? 'fail' : t.status === 'pending' ? 'pend' : '';
    return `
      <div class="tx-item">
        <div class="tx-icon ${icoBox}">${icon}</div>
        <div class="tx-info">
          <div class="tx-name">${t.name}</div>
          <div class="tx-meta">+254 ${t.phone} &nbsp;·&nbsp; ${t.reason} &nbsp;·&nbsp; ${t.date}</div>
        </div>
        <div class="tx-amount ${amtCls}" style="${t.status==='failed'?'color:#e05c5c;':''}">
          ${t.status === 'failed' ? '✗ ' : ''}${fmtKES(t.amount)}
        </div>
        <div class="tx-status-dot ${dotCls}"></div>
      </div>`;
  }).join('');
}

/* =========================================================
   WITHDRAWAL TAB
   ========================================================= */
function populateWdRecipients() {
  const sel = document.getElementById('wd-recipient');
  if (!sel) return;
  const staff = getStaff().filter(s => s.status === 'active');
  sel.innerHTML = '<option value="">— Select staff member —</option>' +
    staff.map(s => `<option value="${s.id}" data-phone="${s.phone}">${s.name} (+254 ${s.phone})</option>`).join('');
}

function prefillRecipient() {
  const sel    = document.getElementById('wd-recipient');
  const option = sel.options[sel.selectedIndex];
  const phone  = option?.getAttribute('data-phone') || '';
  document.getElementById('wd-phone').value = phone;
  updateWdPreview();
}

function updateWdPreview() {
  const sel    = document.getElementById('wd-recipient');
  const name   = sel.options[sel.selectedIndex]?.text || '';
  const phone  = document.getElementById('wd-phone').value;
  const amount = parseFloat(document.getElementById('wd-amount').value) || 0;
  const prev   = document.getElementById('wd-preview');

  if (phone && amount > 0) {
    document.getElementById('wd-preview-name').textContent   = name !== '— Select staff member —' ? name : 'Custom recipient';
    document.getElementById('wd-preview-phone').textContent  = '+254 ' + phone;
    document.getElementById('wd-preview-amount').textContent = fmtKES(amount);
    prev.style.display = 'flex';
  } else {
    prev.style.display = 'none';
  }
}

function renderWithdrawalList() {
  const el = document.getElementById('wd-tx-list');
  if (!el) return;
  const withdrawals = getTx().filter(t => t.type === 'withdrawal' || t.type === 'bonus').slice(0, 8);
  el.innerHTML = buildTxHTML(withdrawals);
}

let wdStkTimer = null;

function sendWithdrawal() {
  const phone  = document.getElementById('wd-phone').value.trim();
  const amount = parseFloat(document.getElementById('wd-amount').value) || 0;
  const reason = document.getElementById('wd-reason').value;
  const sel    = document.getElementById('wd-recipient');
  const name   = sel.options[sel.selectedIndex]?.text || 'Staff Member';

  if (!phone || phone.length < 9) { showToast('Enter a valid 9-digit M-Pesa number.', 'error'); return; }
  if (amount < 1) { showToast('Enter a valid amount.', 'error'); return; }

  // Show STK panel
  document.getElementById('wd-stk-panel').style.display = 'block';
  document.getElementById('stk-phone-label').textContent = '+254 ' + phone;

  // Reset steps
  ['wstk-1','wstk-2','wstk-3','wstk-4'].forEach(id => {
    const el = document.getElementById(id);
    if (el) {
      el.classList.remove('done','active');
      el.querySelectorAll('.run-spinner,.run-tick').forEach(e => e.remove());
    }
  });
  document.getElementById('wstk-1').classList.add('done');
  document.getElementById('wstk-1').insertAdjacentHTML('beforeend','<span class="run-tick">✓</span>');
  document.getElementById('wstk-2').classList.add('active');
  const sp2 = document.createElement('span'); sp2.className = 'run-spinner';
  document.getElementById('wstk-2').appendChild(sp2);

  clearTimeout(wdStkTimer);
  wdStkTimer = setTimeout(() => {
    document.getElementById('wstk-2').classList.remove('active');
    document.getElementById('wstk-2').classList.add('done');
    sp2.remove();
    document.getElementById('wstk-2').insertAdjacentHTML('beforeend','<span class="run-tick">✓</span>');
    document.getElementById('wstk-3').classList.add('active');
    const sp3 = document.createElement('span'); sp3.className = 'run-spinner';
    document.getElementById('wstk-3').appendChild(sp3);

    setTimeout(() => {
      document.getElementById('wstk-3').classList.remove('active');
      document.getElementById('wstk-3').classList.add('done');
      sp3.remove();
      document.getElementById('wstk-3').insertAdjacentHTML('beforeend','<span class="run-tick">✓</span>');
      document.getElementById('wstk-4').classList.add('active');
      const sp4 = document.createElement('span'); sp4.className = 'run-spinner';
      document.getElementById('wstk-4').appendChild(sp4);

      setTimeout(() => {
        document.getElementById('wstk-4').classList.remove('active');
        document.getElementById('wstk-4').classList.add('done');
        sp4.remove();
        document.getElementById('wstk-4').insertAdjacentHTML('beforeend','<span class="run-tick">✓</span>');

        // Record transaction
        const cleanName = name === '— Select staff member —' ? 'External' : name.split(' (')[0];
        addTransaction({ type: reason === 'Monthly Salary' ? 'salary' : 'withdrawal', name: cleanName, phone, amount, status:'success', reason });

        showToast(`✅ ${fmtKES(amount)} sent to +254 ${phone} via M-Pesa.`);
        document.getElementById('wd-stk-panel').style.display = 'none';

        // Reset form
        document.getElementById('wd-phone').value  = '';
        document.getElementById('wd-amount').value = '';
        document.getElementById('wd-preview').style.display = 'none';
        document.getElementById('wd-recipient').value = '';

        renderWithdrawalList();
        renderHistory();
        renderOverview();
      }, 1200);
    }, 2000);
  }, 4500);
}

function cancelWdStk() {
  clearTimeout(wdStkTimer);
  document.getElementById('wd-stk-panel').style.display = 'none';
  showToast('Withdrawal cancelled.', 'error');
}

/* =========================================================
   SETTINGS TAB
   ========================================================= */
function loadSettings() {
  const s = getSettings();
  const set = (id, val) => { const el = document.getElementById(id); if (el) { if (el.type === 'checkbox') el.checked = val; else el.value = val; } };

  set('set-frequency',       s.frequency);
  set('set-payday',          s.payday);
  set('set-currency',        s.currency);
  set('set-nhif',            s.nhif);
  set('set-nssf',            s.nssf);
  set('set-paye',            s.paye);
  set('set-housing',         s.housing);
  set('set-house-allow',     s.houseAllow);
  set('set-transport',       s.transport);
  set('set-medical',         s.medical);
  set('set-overtime',        s.overtime);
  set('set-custom-ded',      s.customDed || '');
  set('set-shortcode',       s.shortcode);
  set('set-consumer-key',    s.consumerKey);
  set('set-consumer-secret', s.consumerSecret);
  set('set-auto-disburse',   s.autoDisburse);
  set('set-remind',          s.remind);
  set('set-sms',             s.sms);
  set('set-email-report',    s.emailReport);
}

function saveSettings() {
  const get = id => { const el = document.getElementById(id); if (!el) return null; return el.type === 'checkbox' ? el.checked : el.value; };

  const updated = {
    frequency     : get('set-frequency'),
    payday        : get('set-payday'),
    currency      : get('set-currency'),
    nhif          : get('set-nhif'),
    nssf          : get('set-nssf'),
    paye          : get('set-paye'),
    housing       : get('set-housing'),
    houseAllow    : get('set-house-allow'),
    transport     : get('set-transport'),
    medical       : get('set-medical'),
    overtime      : get('set-overtime'),
    customDed     : parseFloat(get('set-custom-ded')) || 0,
    shortcode     : get('set-shortcode'),
    consumerKey   : get('set-consumer-key'),
    consumerSecret: get('set-consumer-secret'),
    autoDisburse  : get('set-auto-disburse'),
    remind        : get('set-remind'),
    sms           : get('set-sms'),
    emailReport   : get('set-email-report')
  };

  localStorage.setItem(KEY_SETTINGS, JSON.stringify(updated));
  renderRunPayroll();
  renderOverview();
  showToast('✅ Settings saved successfully.');
}

function confirmReset() {
  if (!confirm('This will delete ALL payroll data and cannot be undone. Are you sure?')) return;
  localStorage.removeItem(KEY_STAFF);
  localStorage.removeItem(KEY_TX);
  localStorage.removeItem(KEY_SETTINGS);
  showToast('Payroll data reset. Reloading…');
  setTimeout(() => location.reload(), 1500);
}

function exportData() {
  const data = {
    staff       : getStaff(),
    transactions: getTx(),
    settings    : getSettings(),
    exportedAt  : new Date().toISOString()
  };
  const blob = new Blob([JSON.stringify(data, null, 2)], { type:'application/json' });
  const url  = URL.createObjectURL(blob);
  const a    = document.createElement('a');
  a.href     = url;
  a.download = 'oj-payroll-export-' + new Date().toISOString().split('T')[0] + '.json';
  a.click();
  URL.revokeObjectURL(url);
  showToast('📤 Payroll data exported.');
}

/* =========================================================
   TAB NAVIGATION
   ========================================================= */
function showTab(name, btn) {
  // Hide all tabs
  document.querySelectorAll('.dash-tab').forEach(t => t.style.display = 'none');

  // Show target tab
  const target = document.getElementById('tab-' + name);
  if (target) target.style.display = 'block';

  // Update sidebar active state
  document.querySelectorAll('.sidebar-link').forEach(l => l.classList.remove('active'));
  if (btn) btn.classList.add('active');

  // Refresh relevant data on tab open
  if (name === 'overview')    { renderOverview(); }
  if (name === 'staff')       { renderStaffTable(); }
  if (name === 'run-payroll') { renderRunPayroll(); }
  if (name === 'history')     { renderHistory(); }
  if (name === 'withdrawal')  { renderWithdrawalList(); populateWdRecipients(); }
}

/* =========================================================
   MODAL HELPERS
   ========================================================= */
function openModal(id) {
  const el = document.getElementById(id);
  if (el) { el.classList.add('open'); document.body.style.overflow = 'hidden'; }
}

function closeModal(id) {
  const el = document.getElementById(id);
  if (el) { el.classList.remove('open'); document.body.style.overflow = ''; }
}

/* Close modals on overlay click */
document.addEventListener('click', e => {
  if (e.target.classList.contains('modal-overlay')) {
    closeModal(e.target.id);
  }
});

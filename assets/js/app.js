/**
 * Kalpindo CalibFlow - Frontend Interactions & Dynamic Calculators
 */

document.addEventListener('DOMContentLoaded', () => {
    // Flash message auto dismiss
    const flashAlert = document.getElementById('flash-alert');
    if (flashAlert) {
        setTimeout(() => {
            flashAlert.style.opacity = '0';
            flashAlert.style.transition = 'opacity 0.5s ease';
            setTimeout(() => flashAlert.remove(), 500);
        }, 4500);
    }

    // Worksheet dynamic calculation
    setupWorksheetCalculators();
});

function setupWorksheetCalculators() {
    const rows = document.querySelectorAll('.reading-calc-row');
    if (!rows.length) return;

    rows.forEach(row => {
        const stdInput = row.querySelector('.std-val');
        const r1Input = row.querySelector('.r1-val');
        const r2Input = row.querySelector('.r2-val');
        const r3Input = row.querySelector('.r3-val');
        const meanInput = row.querySelector('.mean-val');
        const corrInput = row.querySelector('.corr-val');

        function recalculate() {
            const std = parseFloat(stdInput?.value) || 0;
            const r1 = parseFloat(r1Input?.value) || 0;
            const r2 = parseFloat(r2Input?.value) || 0;
            const r3 = parseFloat(r3Input?.value) || 0;

            const mean = (r1 + r2 + r3) / 3;
            const corr = std - mean;

            if (meanInput) meanInput.value = mean.toFixed(4);
            if (corrInput) {
                corrInput.value = (corr >= 0 ? '+' : '') + corr.toFixed(4);
                if (corr >= 0) {
                    corrInput.classList.remove('text-[#C81E26]');
                    corrInput.classList.add('text-emerald-700');
                } else {
                    corrInput.classList.remove('text-emerald-700');
                    corrInput.classList.add('text-[#C81E26]');
                }
            }
        }

        [stdInput, r1Input, r2Input, r3Input].forEach(inp => {
            if (inp && !inp.dataset.listenerAttached) {
                inp.addEventListener('input', recalculate);
                inp.dataset.listenerAttached = 'true';
            }
        });
    });
}

function addWorksheetRow() {
    const tbody = document.querySelector('#worksheet-table tbody');
    if (!tbody) return;

    const rowCount = tbody.querySelectorAll('tr').length + 1;
    const tr = document.createElement('tr');
    tr.className = 'reading-calc-row hover:bg-slate-50/50 transition-colors';
    tr.innerHTML = `
        <td class="py-2 px-2.5">
            <input type="text" name="points[]" value="Titik ${rowCount}" class="bg-white border border-slate-300 rounded px-2 py-1 text-[11px] text-slate-900 w-24 focus:outline-none focus:border-slate-800">
        </td>
        <td class="py-2 px-2.5 text-right">
            <input type="number" step="any" name="standards[]" value="0.00" class="std-val bg-white border border-slate-300 rounded px-2 py-1 text-[11px] text-slate-900 w-20 text-right focus:outline-none focus:border-slate-800">
        </td>
        <td class="py-2 px-1 text-right">
            <input type="number" step="any" name="run1[]" value="0.00" class="r1-val bg-white border border-slate-300 rounded px-1.5 py-1 text-[11px] text-slate-900 w-16 text-right focus:outline-none focus:border-slate-800">
        </td>
        <td class="py-2 px-1 text-right">
            <input type="number" step="any" name="run2[]" value="0.00" class="r2-val bg-white border border-slate-300 rounded px-1.5 py-1 text-[11px] text-slate-900 w-16 text-right focus:outline-none focus:border-slate-800">
        </td>
        <td class="py-2 px-1 text-right">
            <input type="number" step="any" name="run3[]" value="0.00" class="r3-val bg-white border border-slate-300 rounded px-1.5 py-1 text-[11px] text-slate-900 w-16 text-right focus:outline-none focus:border-slate-800">
        </td>
        <td class="py-2 px-1 text-right">
            <input type="text" readonly value="0.0000" class="mean-val bg-slate-100 border border-slate-200 rounded px-1.5 py-1 text-[11px] text-slate-700 w-18 text-right font-medium cursor-not-allowed">
        </td>
        <td class="py-2 px-1 text-right">
            <input type="text" readonly value="0.0000" class="corr-val bg-slate-100 border border-slate-200 rounded px-1.5 py-1 text-[11px] text-slate-800 w-18 text-right font-medium cursor-not-allowed">
        </td>
        <td class="py-2 px-2.5 text-right">
            <input type="number" step="any" name="uncertainties[]" value="0.01" class="bg-white border border-slate-300 rounded px-1.5 py-1 text-[11px] text-slate-700 w-18 text-right focus:outline-none focus:border-slate-800">
        </td>
    `;
    tbody.appendChild(tr);
    setupWorksheetCalculators();
}

function removeWorksheetRow() {
    const tbody = document.querySelector('#worksheet-table tbody');
    if (!tbody) return;
    const rows = tbody.querySelectorAll('tr');
    if (rows.length > 1) {
        rows[rows.length - 1].remove();
    }
}

function autoFillWorksheetDemo(scopeCode) {
    const tbody = document.querySelector('#worksheet-table tbody');
    if (!tbody) return;

    let sampleData = [];
    if (scopeCode === 'P') {
        sampleData = [
            { point: '0.00 bar', std: 0.00, r1: 0.00, r2: 0.00, r3: 0.00, unc: 0.004 },
            { point: '2.50 bar', std: 2.50, r1: 2.502, r2: 2.501, r3: 2.502, unc: 0.005 },
            { point: '5.00 bar', std: 5.00, r1: 5.003, r2: 5.002, r3: 5.003, unc: 0.006 },
            { point: '7.50 bar', std: 7.50, r1: 7.504, r2: 7.503, r3: 7.504, unc: 0.007 },
            { point: '10.00 bar', std: 10.00, r1: 10.005, r2: 10.004, r3: 10.005, unc: 0.008 }
        ];
    } else if (scopeCode === 'T' || scopeCode === 'S') {
        sampleData = [
            { point: '0.00 °C', std: 0.01, r1: 0.03, r2: 0.02, r3: 0.03, unc: 0.03 },
            { point: '50.00 °C', std: 50.00, r1: 50.04, r2: 50.03, r3: 50.04, unc: 0.04 },
            { point: '100.00 °C', std: 99.98, r1: 100.05, r2: 100.04, r3: 100.05, unc: 0.05 }
        ];
    } else if (scopeCode === 'D') {
        sampleData = [
            { point: '0.000 mm', std: 0.000, r1: 0.000, r2: 0.001, r3: 0.000, unc: 0.001 },
            { point: '5.000 mm', std: 5.000, r1: 5.002, r2: 5.001, r3: 5.002, unc: 0.001 },
            { point: '15.000 mm', std: 15.000, r1: 15.003, r2: 15.002, r3: 15.003, unc: 0.002 },
            { point: '25.000 mm', std: 25.000, r1: 25.004, r2: 25.003, r3: 25.004, unc: 0.002 }
        ];
    } else if (scopeCode === 'E') {
        sampleData = [
            { point: '1.0000 V', std: 1.0002, r1: 1.0005, r2: 1.0004, r3: 1.0005, unc: 0.0005 },
            { point: '5.0000 V', std: 5.0001, r1: 5.0003, r2: 5.0002, r3: 5.0003, unc: 0.0010 },
            { point: '10.0000 V', std: 10.0004, r1: 10.0008, r2: 10.0007, r3: 10.0008, unc: 0.0020 }
        ];
    } else {
        // Default: Scope M (Massa)
        sampleData = [
            { point: '1.0000 g', std: 1.00002, r1: 1.0000, r2: 1.0000, r3: 1.0001, unc: 0.00005 },
            { point: '50.0000 g', std: 50.00012, r1: 50.0002, r2: 50.0001, r3: 50.0002, unc: 0.00011 },
            { point: '100.0000 g', std: 100.00020, r1: 100.0003, r2: 100.0002, r3: 100.0003, unc: 0.00015 }
        ];
    }

    tbody.innerHTML = '';
    sampleData.forEach(s => {
        const tr = document.createElement('tr');
        tr.className = 'reading-calc-row hover:bg-slate-50/50 transition-colors';
        tr.innerHTML = `
            <td class="py-2 px-2.5">
                <input type="text" name="points[]" value="${s.point}" class="bg-white border border-slate-300 rounded px-2 py-1 text-[11px] text-slate-900 w-24 focus:outline-none focus:border-slate-800">
            </td>
            <td class="py-2 px-2.5 text-right">
                <input type="number" step="any" name="standards[]" value="${s.std}" class="std-val bg-white border border-slate-300 rounded px-2 py-1 text-[11px] text-slate-900 w-20 text-right focus:outline-none focus:border-slate-800">
            </td>
            <td class="py-2 px-1 text-right">
                <input type="number" step="any" name="run1[]" value="${s.r1}" class="r1-val bg-white border border-slate-300 rounded px-1.5 py-1 text-[11px] text-slate-900 w-16 text-right focus:outline-none focus:border-slate-800">
            </td>
            <td class="py-2 px-1 text-right">
                <input type="number" step="any" name="run2[]" value="${s.r2}" class="r2-val bg-white border border-slate-300 rounded px-1.5 py-1 text-[11px] text-slate-900 w-16 text-right focus:outline-none focus:border-slate-800">
            </td>
            <td class="py-2 px-1 text-right">
                <input type="number" step="any" name="run3[]" value="${s.r3}" class="r3-val bg-white border border-slate-300 rounded px-1.5 py-1 text-[11px] text-slate-900 w-16 text-right focus:outline-none focus:border-slate-800">
            </td>
            <td class="py-2 px-1 text-right">
                <input type="text" readonly value="0.0000" class="mean-val bg-slate-100 border border-slate-200 rounded px-1.5 py-1 text-[11px] text-slate-700 w-18 text-right font-medium cursor-not-allowed">
            </td>
            <td class="py-2 px-1 text-right">
                <input type="text" readonly value="0.0000" class="corr-val bg-slate-100 border border-slate-200 rounded px-1.5 py-1 text-[11px] text-slate-800 w-18 text-right font-medium cursor-not-allowed">
            </td>
            <td class="py-2 px-2.5 text-right">
                <input type="number" step="any" name="uncertainties[]" value="${s.unc}" class="bg-white border border-slate-300 rounded px-1.5 py-1 text-[11px] text-slate-700 w-18 text-right focus:outline-none focus:border-slate-800">
            </td>
        `;
        tbody.appendChild(tr);
    });

    setupWorksheetCalculators();
    // Trigger recalculate on all rows
    tbody.querySelectorAll('.std-val').forEach(input => {
        input.dispatchEvent(new Event('input'));
    });
}

function openModal(id) {
    const modal = document.getElementById(id);
    if (modal) {
        modal.classList.remove('hidden');
        modal.classList.add('flex');
        document.body.style.overflow = 'hidden';
    }
}

function closeModal(id) {
    const modal = document.getElementById(id);
    if (modal) {
        modal.classList.add('hidden');
        modal.classList.remove('flex');
        document.body.style.overflow = 'auto';
    }
}

/**
 * Mobile Left Sidebar Drawer Controls
 */
function toggleMobileSidebar() {
    const sidebar = document.getElementById('mobile-sidebar');
    const backdrop = document.getElementById('mobile-sidebar-backdrop');
    if (!sidebar || !backdrop) return;

    if (sidebar.classList.contains('-translate-x-full')) {
        backdrop.classList.remove('hidden');
        setTimeout(() => {
            backdrop.classList.add('opacity-100');
            sidebar.classList.remove('-translate-x-full');
            sidebar.classList.add('translate-x-0');
        }, 10);
        document.body.style.overflow = 'hidden';
    } else {
        closeMobileSidebar();
    }
}

function closeMobileSidebar() {
    const sidebar = document.getElementById('mobile-sidebar');
    const backdrop = document.getElementById('mobile-sidebar-backdrop');
    if (!sidebar || !backdrop) return;

    sidebar.classList.remove('translate-x-0');
    sidebar.classList.add('-translate-x-full');
    backdrop.classList.remove('opacity-100');
    setTimeout(() => {
        backdrop.classList.add('hidden');
        document.body.style.overflow = '';
    }, 300);
}

/**
 * Dark Mode Theme Controller
 */
function toggleTheme() {
    const isDark = document.documentElement.classList.toggle('dark');
    try {
        localStorage.setItem('theme', isDark ? 'dark' : 'light');
    } catch (e) {}
    updateThemeToggleButtons(isDark);
}

function updateThemeToggleButtons(isDark) {
    document.querySelectorAll('.theme-toggle-btn').forEach(btn => {
        btn.setAttribute('aria-label', isDark ? 'Beralih ke mode terang' : 'Beralih ke mode gelap');
        btn.setAttribute('title', isDark ? 'Mode Terang (Light Mode)' : 'Mode Gelap (Dark Mode)');
    });
}

function initTheme() {
    let isDark = false;
    try {
        const savedTheme = localStorage.getItem('theme');
        const prefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
        isDark = (savedTheme === 'dark' || (!savedTheme && prefersDark));
    } catch (e) {}

    if (isDark) {
        document.documentElement.classList.add('dark');
    } else {
        document.documentElement.classList.remove('dark');
    }
    updateThemeToggleButtons(isDark);
}

initTheme();
document.addEventListener('DOMContentLoaded', initTheme);

/**
 * Contextual Action Menu (••• Dropdown) Controls
 */
function toggleActionMenu(id, event) {
    if (event) {
        event.stopPropagation();
    }
    // Close other open menus
    document.querySelectorAll('.action-menu-dropdown.show').forEach(menu => {
        if (menu.id !== id) {
            menu.classList.remove('show');
        }
    });

    const targetMenu = document.getElementById(id);
    if (targetMenu) {
        targetMenu.classList.toggle('show');
    }
}

// Close action menus when clicking outside
document.addEventListener('click', (e) => {
    if (!e.target.closest('.action-menu-container')) {
        document.querySelectorAll('.action-menu-dropdown.show').forEach(menu => {
            menu.classList.remove('show');
        });
    }
});

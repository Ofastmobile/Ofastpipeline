/**
 * OFast Pipeline - Plan Comparison Matrix Editor
 * Interactive inline editing, custom text support across all columns,
 * row addition/deletion, category management, and AJAX/form synchronization.
 */

(function () {
    'use strict';

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initMatrixEditor);
    } else {
        initMatrixEditor();
    }

    function initMatrixEditor() {
        const matrixCard = document.getElementById('ofp-matrix-card');
        if (!matrixCard) return;

        const tbody = document.getElementById('ofp-matrix-tbody');
        const hiddenInput = document.getElementById('ofp_plan_matrix_json');
        const quickSaveBtn = document.getElementById('ofp-matrix-save-quick-btn');
        const quickSaveTxt = document.getElementById('ofp-matrix-save-txt');
        const addGroupBtn = document.getElementById('ofp-matrix-add-group-btn');

        const ajaxUrl = (window.ofpPlanMatrix && window.ofpPlanMatrix.ajaxUrl)
            ? window.ofpPlanMatrix.ajaxUrl
            : (window.ofpAdmin && window.ofpAdmin.ajaxUrl ? window.ofpAdmin.ajaxUrl : '/wp-admin/admin-ajax.php');

        const nonce = (window.ofpPlanMatrix && window.ofpPlanMatrix.nonce)
            ? window.ofpPlanMatrix.nonce
            : '';

        function renderFormattedVal(val, col) {
            val = (val || '').trim();
            if (val === '✓') {
                return '<span class="ofp-badge-check" style="display:inline-flex; align-items:center; justify-content:center; width:22px; height:22px; border-radius:50%; background:#dcfce7; color:#16a34a; font-weight:800; font-size:13px;">✓</span>';
            }
            if (val === '—' || val === '' || val === '-') {
                return '<span class="ofp-badge-dash" style="color:#94a3b8; font-size:16px;">—</span>';
            }
            const weight = (col === 'gold') ? '800' : '600';
            let color = '#1e293b';
            if (col === 'gold') color = '#2563eb';
            return '<span class="ofp-badge-text" style="font-weight:' + weight + '; font-size:12px; color:' + color + ';">' + escapeHtml(val) + '</span>';
        }

        function escapeHtml(str) {
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function ofpSyncMatrix() {
            if (!tbody || !hiddenInput) return;
            const data = {};
            let currentGroup = null;

            const children = tbody.children;
            for (let i = 0; i < children.length; i++) {
                const tr = children[i];
                if (tr.classList.contains('ofp-matrix-group-row')) {
                    currentGroup = tr.getAttribute('data-group-name') || 'Platform Features';
                    if (!data[currentGroup]) {
                        data[currentGroup] = [];
                    }
                } else if (tr.classList.contains('ofp-matrix-row') && currentGroup) {
                    const featureCell = tr.querySelector('.ofp-matrix-cell[data-col="feature"]');
                    const freeCell = tr.querySelector('.ofp-matrix-cell[data-col="free"]');
                    const silverCell = tr.querySelector('.ofp-matrix-cell[data-col="silver"]');
                    const goldCell = tr.querySelector('.ofp-matrix-cell[data-col="gold"]');

                    const feature = featureCell ? (featureCell.getAttribute('data-val') || '').trim() : '';
                    if (feature) {
                        data[currentGroup].push({
                            feature: feature,
                            free: freeCell ? (freeCell.getAttribute('data-val') || '—').trim() : '—',
                            silver: silverCell ? (silverCell.getAttribute('data-val') || '—').trim() : '—',
                            gold: goldCell ? (goldCell.getAttribute('data-val') || '—').trim() : '—'
                        });
                    }
                }
            }
            hiddenInput.value = JSON.stringify(data);
        }

        // Initial sync
        ofpSyncMatrix();

        // Master settings form submit sync
        const masterForm = document.getElementById('ofp-master-settings-form');
        if (masterForm) {
            masterForm.addEventListener('submit', function () {
                ofpSyncMatrix();
            });
        }

        // Cell inline edit
        function startEditCell(cell) {
            if (cell.classList.contains('is-editing')) return;
            cell.classList.add('is-editing');

            const col = cell.getAttribute('data-col');
            const currentVal = cell.getAttribute('data-val') || '';
            const isFeatureCol = (col === 'feature');

            const wrap = document.createElement('div');
            wrap.className = 'ofp-matrix-editor-wrap';

            const input = document.createElement('input');
            input.type = 'text';
            input.className = 'ofp-matrix-inline-input';
            input.value = currentVal;
            input.placeholder = isFeatureCol ? 'Feature name...' : 'Type text or click pill';
            if (!isFeatureCol) {
                input.style.textAlign = 'center';
            }

            wrap.appendChild(input);

            if (!isFeatureCol) {
                const pillsWrap = document.createElement('div');
                pillsWrap.className = 'ofp-matrix-quick-pills';

                const checkPill = document.createElement('button');
                checkPill.type = 'button';
                checkPill.className = 'ofp-matrix-pill-btn btn-check';
                checkPill.innerHTML = '<span style="color:#16a34a; font-weight:800;">✓</span> Yes';
                checkPill.title = 'Set as checkmark';

                const dashPill = document.createElement('button');
                dashPill.type = 'button';
                dashPill.className = 'ofp-matrix-pill-btn btn-dash';
                dashPill.innerHTML = '<span style="color:#64748b; font-weight:800;">—</span> None';
                dashPill.title = 'Set as not included (—)';

                pillsWrap.appendChild(checkPill);
                pillsWrap.appendChild(dashPill);
                wrap.appendChild(pillsWrap);

                checkPill.addEventListener('mousedown', function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    commitVal('✓');
                });

                dashPill.addEventListener('mousedown', function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    commitVal('—');
                });
            }

            cell.innerHTML = '';
            cell.appendChild(wrap);
            input.focus();
            input.select();

            let committed = false;

            function commitVal(explicitVal) {
                if (committed) return;
                committed = true;

                let newVal = (explicitVal !== undefined ? explicitVal : input.value).trim();
                if (!newVal) {
                    newVal = isFeatureCol ? 'Untitled Feature' : '—';
                } else if (!isFeatureCol) {
                    const lower = newVal.toLowerCase();
                    if (lower === 'yes' || lower === '1' || lower === 'true' || lower === '✓' || lower === 'check' || lower === 'y') {
                        newVal = '✓';
                    } else if (lower === 'no' || lower === '0' || lower === 'false' || lower === '—' || lower === '-' || lower === 'none' || lower === 'n') {
                        newVal = '—';
                    }
                }

                cell.classList.remove('is-editing');
                cell.setAttribute('data-val', newVal);

                if (isFeatureCol) {
                    cell.innerHTML = '<div style="display:flex; align-items:center; justify-content:space-between; gap:8px;">' +
                        '<span class="ofp-matrix-display-val">' + escapeHtml(newVal) + '</span>' +
                        '<button type="button" class="ofp-matrix-del-row-btn" title="Delete feature" style="background:none; border:none; color:#ef4444; cursor:pointer; padding:2px 6px; font-size:16px; line-height:1; opacity:0; transition:opacity 0.15s; border-radius:4px;">&times;</button>' +
                        '</div>';
                } else {
                    cell.innerHTML = '<span class="ofp-matrix-display-val">' + renderFormattedVal(newVal, col) + '</span>';
                }

                ofpSyncMatrix();
            }

            input.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    commitVal();
                } else if (e.key === 'Escape') {
                    e.preventDefault();
                    committed = true;
                    cell.classList.remove('is-editing');
                    if (isFeatureCol) {
                        cell.innerHTML = '<div style="display:flex; align-items:center; justify-content:space-between; gap:8px;">' +
                            '<span class="ofp-matrix-display-val">' + escapeHtml(currentVal) + '</span>' +
                            '<button type="button" class="ofp-matrix-del-row-btn" title="Delete feature" style="background:none; border:none; color:#ef4444; cursor:pointer; padding:2px 6px; font-size:16px; line-height:1; opacity:0; transition:opacity 0.15s; border-radius:4px;">&times;</button>' +
                            '</div>';
                    } else {
                        cell.innerHTML = '<span class="ofp-matrix-display-val">' + renderFormattedVal(currentVal, col) + '</span>';
                    }
                }
            });

            input.addEventListener('blur', function () {
                setTimeout(function () {
                    if (!committed) {
                        commitVal();
                    }
                }, 150);
            });
        }

        // Event delegation on table body
        if (tbody) {
            // Click to edit any cell (Feature name, Free, Silver or Gold)
            tbody.addEventListener('click', function (e) {
                // Delete feature row
                const delBtn = e.target.closest('.ofp-matrix-del-row-btn');
                if (delBtn) {
                    e.preventDefault();
                    e.stopPropagation();
                    const tr = delBtn.closest('.ofp-matrix-row');
                    if (tr) {
                        tr.remove();
                        ofpSyncMatrix();
                    }
                    return;
                }

                // Delete section / group
                const delGroupBtn = e.target.closest('.ofp-matrix-del-group-btn');
                if (delGroupBtn) {
                    e.preventDefault();
                    e.stopPropagation();
                    if (!confirm('Are you sure you want to remove this entire comparison section?')) return;
                    const groupRow = delGroupBtn.closest('.ofp-matrix-group-row');
                    let sib = groupRow.nextElementSibling;
                    while (sib && !sib.classList.contains('ofp-matrix-group-row')) {
                        const toRemove = sib;
                        sib = sib.nextElementSibling;
                        toRemove.remove();
                    }
                    groupRow.remove();
                    ofpSyncMatrix();
                    return;
                }

                // Add feature button inside group
                const addFeatBtn = e.target.closest('.ofp-matrix-add-feature-btn');
                if (addFeatBtn) {
                    e.preventDefault();
                    const groupName = addFeatBtn.getAttribute('data-group');
                    const addRow = addFeatBtn.closest('.ofp-matrix-add-row');

                    const newTr = document.createElement('tr');
                    newTr.className = 'ofp-matrix-row';
                    newTr.setAttribute('data-group', groupName);
                    newTr.style.borderBottom = '1px solid #f1f5f9';
                    newTr.style.transition = 'background 0.15s ease';
                    newTr.innerHTML = '<td class="ofp-matrix-cell" data-col="feature" data-val="New Feature" title="Click to edit" style="padding:12px 20px; font-size:13px; font-weight:600; color:#1e293b;">' +
                        '<div style="display:flex; align-items:center; justify-content:space-between; gap:8px;">' +
                        '<span class="ofp-matrix-display-val">New Feature</span>' +
                        '<button type="button" class="ofp-matrix-del-row-btn" title="Delete feature" style="background:none; border:none; color:#ef4444; cursor:pointer; padding:2px 6px; font-size:16px; line-height:1; opacity:0; transition:opacity 0.15s; border-radius:4px;">&times;</button>' +
                        '</div>' +
                        '</td>' +
                        '<td class="ofp-matrix-cell" data-col="free" data-val="—" title="Click to edit or set checkmark" style="padding:12px 14px; text-align:center;">' +
                        '<span class="ofp-matrix-display-val">' + renderFormattedVal('—', 'free') + '</span>' +
                        '</td>' +
                        '<td class="ofp-matrix-cell" data-col="silver" data-val="✓" title="Click to edit or set checkmark" style="padding:12px 14px; text-align:center;">' +
                        '<span class="ofp-matrix-display-val">' + renderFormattedVal('✓', 'silver') + '</span>' +
                        '</td>' +
                        '<td class="ofp-matrix-cell" data-col="gold" data-val="✓" title="Click to edit or set checkmark" style="padding:12px 14px; text-align:center;">' +
                        '<span class="ofp-matrix-display-val">' + renderFormattedVal('✓', 'gold') + '</span>' +
                        '</td>';

                    tbody.insertBefore(newTr, addRow);
                    ofpSyncMatrix();

                    const newFeatureCell = newTr.querySelector('.ofp-matrix-cell[data-col="feature"]');
                    if (newFeatureCell) {
                        startEditCell(newFeatureCell);
                    }
                    return;
                }

                // Click on any cell to edit it
                const cell = e.target.closest('.ofp-matrix-cell');
                if (cell && !cell.classList.contains('is-editing')) {
                    startEditCell(cell);
                    return;
                }

                // Click group title to rename
                const groupTitle = e.target.closest('.ofp-matrix-group-title');
                if (groupTitle) {
                    const currentTitle = groupTitle.textContent.trim();
                    const input = document.createElement('input');
                    input.type = 'text';
                    input.className = 'ofp-matrix-inline-input';
                    input.value = currentTitle;
                    input.style.width = '260px';
                    input.style.display = 'inline-block';

                    const groupRow = groupTitle.closest('.ofp-matrix-group-row');
                    groupTitle.style.display = 'none';
                    groupTitle.parentNode.insertBefore(input, groupTitle);
                    input.focus();
                    input.select();

                    function commitGroupTitle() {
                        const newTitle = input.value.trim() || currentTitle;
                        groupTitle.textContent = newTitle;
                        groupTitle.style.display = '';
                        if (groupRow) {
                            groupRow.setAttribute('data-group-name', newTitle);
                            let sib = groupRow.nextElementSibling;
                            while (sib && !sib.classList.contains('ofp-matrix-group-row')) {
                                sib.setAttribute('data-group', newTitle);
                                sib = sib.nextElementSibling;
                            }
                        }
                        input.remove();
                        ofpSyncMatrix();
                    }

                    input.addEventListener('keydown', function (ev) {
                        if (ev.key === 'Enter') {
                            ev.preventDefault();
                            input.blur();
                        } else if (ev.key === 'Escape') {
                            groupTitle.style.display = '';
                            input.remove();
                        }
                    });
                    input.addEventListener('blur', commitGroupTitle, { once: true });
                }
            });
        }

        // Add Section button
        if (addGroupBtn) {
            addGroupBtn.addEventListener('click', function () {
                const groupName = prompt('Enter the name for the new comparison section:', 'New Section');
                if (!groupName || !groupName.trim()) return;
                const safeName = groupName.trim();

                const groupTr = document.createElement('tr');
                groupTr.className = 'ofp-matrix-group-row';
                groupTr.setAttribute('data-group-name', safeName);
                groupTr.style.background = '#f1f5f9';
                groupTr.style.borderTop = '1px solid #e2e8f0';
                groupTr.style.borderBottom = '1px solid #e2e8f0';
                groupTr.innerHTML = '<td colspan="4" style="padding:9px 20px;">' +
                    '<div style="display:flex; align-items:center; justify-content:space-between;">' +
                    '<span class="ofp-matrix-group-title" title="Click to rename section" style="font-size:11px; font-weight:800; text-transform:uppercase; letter-spacing:0.06em; color:#475569; cursor:pointer;">' +
                    escapeHtml(safeName) +
                    '</span>' +
                    '<button type="button" class="ofp-matrix-del-group-btn" title="Delete section" style="background:none; border:none; color:#ef4444; font-size:12px; font-weight:700; cursor:pointer; opacity:0; transition:opacity 0.15s; padding:2px 8px; border-radius:4px;">' +
                    'Remove Section' +
                    '</button>' +
                    '</div>' +
                    '</td>';

                const addRowTr = document.createElement('tr');
                addRowTr.className = 'ofp-matrix-add-row';
                addRowTr.setAttribute('data-group', safeName);
                addRowTr.innerHTML = '<td colspan="4" style="padding:6px 20px; background:#fafbfc; border-bottom:1px solid #e2e8f0; text-align:left;">' +
                    '<button type="button" class="ofp-matrix-add-feature-btn" data-group="' + escapeHtml(safeName) + '" style="display:inline-flex; align-items:center; gap:6px; background:none; border:none; color:var(--ofp-primary, #2563eb); font-size:12px; font-weight:700; cursor:pointer; padding:4px 8px; border-radius:4px; transition:all 0.15s ease;">' +
                    '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>' +
                    '+ Add Feature to this section' +
                    '</button>' +
                    '</td>';

                tbody.appendChild(groupTr);
                tbody.appendChild(addRowTr);
                ofpSyncMatrix();

                const btn = addRowTr.querySelector('.ofp-matrix-add-feature-btn');
                if (btn) btn.click();
            });
        }

        // Quick AJAX Save
        if (quickSaveBtn) {
            quickSaveBtn.addEventListener('click', function (e) {
                e.preventDefault();
                ofpSyncMatrix();
                const matrixJson = hiddenInput.value;
                quickSaveTxt.textContent = 'Saving...';
                quickSaveBtn.disabled = true;

                const fd = new FormData();
                fd.append('action', 'ofp_admin_save_plan_matrix');
                fd.append('nonce', nonce);
                fd.append('matrix_json', matrixJson);

                fetch(ajaxUrl, {
                    method: 'POST',
                    body: fd
                })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        if (res && res.success) {
                            quickSaveTxt.textContent = 'Saved!';
                            quickSaveBtn.style.background = '#10b981';
                            setTimeout(function () {
                                quickSaveTxt.textContent = 'Save Matrix';
                                quickSaveBtn.style.background = '';
                                quickSaveBtn.disabled = false;
                            }, 2000);
                        } else {
                            alert((res && res.data) ? res.data : 'Failed to save matrix.');
                            quickSaveTxt.textContent = 'Save Matrix';
                            quickSaveBtn.disabled = false;
                        }
                    })
                    .catch(function (err) {
                        console.error(err);
                        alert('An error occurred while saving.');
                        quickSaveTxt.textContent = 'Save Matrix';
                        quickSaveBtn.disabled = false;
                    });
            });
        }
    }
})();

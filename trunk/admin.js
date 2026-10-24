        // ── Card toggle ──────────────────────────────────────────────
        function pcsfwToggleCard(header) {
            const body = header.nextElementSibling;
            const footer = body.nextElementSibling;
            const isHidden = body.style.display === 'none';
            body.style.display   = isHidden ? '' : 'none';
            footer.style.display = isHidden ? '' : 'none';
        }

        // ── Update card header when category changes ─────────────────
        function pcsfwUpdateHeader(select) {
            const card = select.closest('.pcsfw-rule-card');
            const h3   = card.querySelector('.pcsfw-rule-header h3');
            const badge = h3.querySelector('.pcsfw-rule-type-badge');
            const badgeText = badge ? badge.outerHTML : '';
            const chosen = select.options[select.selectedIndex].text;
            h3.innerHTML = (chosen && select.value ? chosen : 'New Rule') + badgeText;
        }

        // ── Switch between flat / tiered ─────────────────────────────
        function pcsfwSwitchType(radio, type) {
            const card = radio.closest('.pcsfw-rule-card');
            card.querySelector('.pcsfw-flat-section').style.display   = type === 'flat'   ? '' : 'none';
            card.querySelector('.pcsfw-tiered-section').style.display = type === 'tiered' ? '' : 'none';
            // Update badge
            const badge = card.querySelector('.pcsfw-rule-type-badge');
            if (badge) badge.textContent = type === 'flat' ? 'Flat Rate' : 'Tiered';
        }

        // ── Add a new rule card ───────────────────────────────────────
        // Both rule sets (default + selected-role override) share this handler.
        // Each button carries its own container, template and running index.
        document.querySelectorAll('#pcsfw-add-rule, #pcsfw-add-role-rule').forEach(function (btn) {
            btn.addEventListener('click', function () {
            const template = document.getElementById(btn.dataset.template);
            let count = parseInt(btn.dataset.count || '0', 10);
            const html = template.innerHTML.replace(/__IDX__/g, count);
            btn.dataset.count = count + 1;
            const container = document.getElementById(btn.dataset.container);
            // Remove "no rules" message if present.
            // Must be a DIRECT child — rule cards contain their own <p> hints,
            // and querySelector('p') would happily delete one of those instead.
            const noRules = container.querySelector(':scope > p');
            if (noRules) noRules.remove();
            container.insertAdjacentHTML('beforeend', html);
            });
        });

        // ── Remove a rule card ────────────────────────────────────────
        function pcsfwRemoveRule(btn) {
            if (!confirm('Remove this shipping rule?')) return;
            btn.closest('.pcsfw-rule-card').remove();
        }

        // ── Add a tier row ────────────────────────────────────────────
        // `base` is the full field prefix, e.g. "rules[0]" or "role_rules[2]",
        // so the same function serves both rule sets.
        function pcsfwAddTier(btn, base) {
            const tbody = btn.previousElementSibling.querySelector('.pcsfw-tier-tbody');
            const tierIdx = tbody.querySelectorAll('tr').length;
            const prefix = `${base}[tiers][${tierIdx}]`;
            const row = document.createElement('tr');
            row.innerHTML = `
                <td><input type="number" min="1" name="${prefix}[qty_min]" placeholder="1"></td>
                <td><input type="number" min="1" name="${prefix}[qty_max]" placeholder="6"></td>
                <td><input type="number" step="0.01" min="0" name="${prefix}[price]" placeholder="10.20" class="pcsfw-price-input"></td>
                <td style="text-align:center;">
                    <input type="checkbox" name="${prefix}[free]" value="1"
                           class="pcsfw-free-check" onchange="pcsfwToggleFree(this)">
                </td>
                <td><button type="button" class="pcsfw-remove-tier" onclick="pcsfwRemoveTier(this)">✕</button></td>
            `;
            tbody.appendChild(row);
        }

        // ── Remove a tier row ─────────────────────────────────────────
        function pcsfwRemoveTier(btn) {
            btn.closest('tr').remove();
        }

        // ── Toggle price field when "Free" is checked ─────────────────
        function pcsfwToggleFree(checkbox) {
            const row   = checkbox.closest('tr');
            const price = row.querySelector('.pcsfw-price-input');
            if (!price) return;
            price.disabled = checkbox.checked;
            price.style.opacity = checkbox.checked ? '0.4' : '1';
        }

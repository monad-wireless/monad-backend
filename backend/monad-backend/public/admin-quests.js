/*
 * The quest builder's step island (IP-157, Phase 2). Vanilla, no build step.
 *
 * It owns one thing: the ordered step list of the quest on screen. It seeds from three
 * <script type="application/json"> blocks the page renders — the steps, the step schemas
 * (StepSchemaRegistry::all(): type, palette, fields) and the placement mirror rows for the
 * floor — and it writes the list back into the form's hidden `stepsJson` field on every
 * change. Nothing here validates: the server re-runs every step through the entity
 * constraint on QuestStep::$config and returns violations per step index, which this island
 * only renders (#step-violations).
 *
 * The start step's `features.*` fields are deliberately NOT drawn here. They are session
 * scoped, they are edited in the header card, and the controller writes them onto the first
 * start step on save; drawing them twice would give the author two controls for one value.
 *
 * One global, `MonadQuestBuilder`, with one entry point, `mount(el)`.
 */
(function () {
    'use strict';

    var TARGET_KINDS = ['card', 'node'];

    // ── small helpers ────────────────────────────────────────────────────────────────────

    function el(tag, attrs, children) {
        var node = document.createElement(tag), k;
        attrs = attrs || {};
        for (k in attrs) {
            if (!Object.prototype.hasOwnProperty.call(attrs, k)) { continue; }
            if (k === 'text') { node.textContent = attrs[k]; }
            else if (k === 'class') { node.className = attrs[k]; }
            else if (attrs[k] === true) { node.setAttribute(k, k); }
            else if (attrs[k] !== false && attrs[k] !== null && attrs[k] !== undefined) { node.setAttribute(k, attrs[k]); }
        }
        (children || []).forEach(function (child) {
            if (child) { node.appendChild(child); }
        });
        return node;
    }

    function readJson(id, fallback) {
        var node = document.getElementById(id);
        if (!node) { return fallback; }
        try { return JSON.parse(node.textContent); } catch (e) { return fallback; }
    }

    /** Config paths are dotted (`features.broadcast`), so get and set walk the path. */
    function getPath(obj, path) {
        var parts = path.split('.'), cur = obj, i;
        for (i = 0; i < parts.length; i++) {
            if (cur === null || typeof cur !== 'object') { return undefined; }
            cur = cur[parts[i]];
        }
        return cur;
    }

    function setPath(obj, path, value) {
        var parts = path.split('.'), cur = obj, i, last = parts[parts.length - 1];
        for (i = 0; i < parts.length - 1; i++) {
            if (typeof cur[parts[i]] !== 'object' || cur[parts[i]] === null) { cur[parts[i]] = {}; }
            cur = cur[parts[i]];
        }
        if (value === undefined) { delete cur[last]; } else { cur[last] = value; }
    }

    // ── the island ───────────────────────────────────────────────────────────────────────

    function Builder(root) {
        this.root = root;
        this.locked = root.getAttribute('data-locked') === '1';
        this.steps = readJson('quest-steps', []) || [];
        this.schemas = readJson('step-schemas', []) || [];
        this.placements = readJson('placements', []) || [];
        this.violations = readJson('step-violations', {}) || {};
        this.field = document.querySelector('input[name$="[stepsJson]"]');
        this.open = {};      // step index -> advanced JSON open
        this.picker = {};    // step index -> target picker open
        this.dragFrom = null;

        this.byType = {};
        this.schemas.forEach(function (s) { this.byType[s.type] = s; }, this);
        this.rooms = this.groupRooms();
    }

    Builder.prototype.groupRooms = function () {
        var rooms = {}, order = [];
        this.placements.forEach(function (p) {
            var room = p.room || 'unassigned';
            if (!rooms[room]) { rooms[room] = []; order.push(room); }
            rooms[room].push(p);
        });
        order.sort();
        return order.map(function (room) { return { room: room, rows: rooms[room] }; });
    };

    Builder.prototype.sync = function () {
        if (this.field) { this.field.value = JSON.stringify(this.steps); }
    };

    Builder.prototype.schemaOf = function (step) {
        return this.byType[step.type] || null;
    };

    Builder.prototype.render = function () {
        this.root.textContent = '';
        this.root.appendChild(this.renderList());
        if (!this.locked) { this.root.appendChild(this.renderPalette()); }
        this.sync();
    };

    Builder.prototype.renderList = function () {
        var list = el('ol', { class: 'step-list' }), self = this;
        if (this.steps.length === 0) {
            list.appendChild(el('li', { class: 'step-empty', text: 'No steps yet. Add one from the palette below.' }));
            return list;
        }
        this.steps.forEach(function (step, i) { list.appendChild(self.renderStep(step, i)); });
        return list;
    };

    Builder.prototype.renderStep = function (step, index) {
        var self = this,
            schema = this.schemaOf(step),
            item = el('li', {
                class: 'step' + (this.violations[index] ? ' step-invalid' : ''),
                // draggable is an ENUMERATED attribute, not a boolean one: it wants the literal
                // "true", and `draggable="draggable"` is invalid and reads back as "auto".
                draggable: this.locked ? null : 'true',
                'data-index': index
            });

        if (!this.locked) {
            item.addEventListener('dragstart', function (e) {
                self.dragFrom = index;
                item.classList.add('dragging');
                e.dataTransfer.effectAllowed = 'move';
                try { e.dataTransfer.setData('text/plain', String(index)); } catch (err) { /* Safari */ }
            });
            item.addEventListener('dragend', function () { item.classList.remove('dragging'); });
            item.addEventListener('dragover', function (e) { e.preventDefault(); item.classList.add('drop-target'); });
            item.addEventListener('dragleave', function () { item.classList.remove('drop-target'); });
            item.addEventListener('drop', function (e) {
                e.preventDefault();
                item.classList.remove('drop-target');
                if (self.dragFrom !== null && self.dragFrom !== index) { self.move(self.dragFrom, index); }
                self.dragFrom = null;
            });
        }

        item.appendChild(this.renderStepHead(step, index, schema));
        item.appendChild(this.renderStepBody(step, index, schema));

        (this.violations[index] || []).forEach(function (message) {
            item.appendChild(el('p', { class: 'step-violation', text: message }));
        });

        return item;
    };

    Builder.prototype.renderStepHead = function (step, index, schema) {
        var self = this,
            head = el('div', { class: 'step-head' }),
            name = el('input', {
                type: 'text', class: 'form-control step-name', value: step.name || '',
                placeholder: 'Step name, shown on the phone', disabled: this.locked
            });

        name.addEventListener('input', function () { step.name = name.value; self.sync(); });

        head.appendChild(el('span', { class: 'step-order', text: String(index) }));
        head.appendChild(el('span', { class: 'step-type', text: schema ? schema.palette.title : step.type, title: step.type }));
        head.appendChild(name);

        if (!this.locked) {
            head.appendChild(this.button('↑', 'Move up', index === 0, function () { self.move(index, index - 1); }));
            head.appendChild(this.button('↓', 'Move down', index === this.steps.length - 1, function () { self.move(index, index + 1); }));
            head.appendChild(this.button('Advanced', 'Edit this step’s config as raw JSON', false, function () {
                self.open[index] = !self.open[index];
                self.render();
            }));
            head.appendChild(this.button('Delete', 'Remove this step', false, function () {
                if (window.confirm('Delete step ' + index + ' (' + (step.name || step.type) + ')? The step list is only written when you save.')) {
                    self.steps.splice(index, 1);
                    self.open = {};
                    self.picker = {};
                    self.render();
                }
            }, 'danger'));
        }

        return head;
    };

    Builder.prototype.renderStepBody = function (step, index, schema) {
        var body = el('div', { class: 'step-body' }), self = this;

        if (!schema) {
            body.appendChild(el('p', {
                class: 'step-note',
                text: 'No schema for type "' + step.type + '". Edit the config as JSON; the server will refuse an unknown type.'
            }));
            body.appendChild(this.renderAdvanced(step, index));
            return body;
        }

        if (schema.palette.disabled_reason) {
            body.appendChild(el('p', { class: 'step-note step-note-warn', text: schema.palette.disabled_reason }));
        }

        schema.fields.forEach(function (field) {
            // features.* belong to the header card; see the file comment.
            if (step.type === 'start' && field.name.indexOf('features.') === 0) { return; }
            body.appendChild(self.renderField(step, index, field));
        });

        if (step.type === 'start') {
            body.appendChild(el('p', {
                class: 'step-note',
                text: 'The four session features (broadcast, track, witness, illuminator) are edited in the header, beside the rest of the quest, and written onto this step when you save.'
            }));
        }

        if (this.open[index]) { body.appendChild(this.renderAdvanced(step, index)); }

        return body;
    };

    // ── fields ───────────────────────────────────────────────────────────────────────────

    Builder.prototype.renderField = function (step, index, field) {
        var wrap = el('div', { class: 'field' }),
            label = el('label', { class: 'field-label' }),
            control;

        label.appendChild(el('code', { text: field.name }));
        if (field.required) { label.appendChild(el('span', { class: 'field-required', text: 'required' })); }
        wrap.appendChild(label);

        if (field.kind === 'targets') { control = this.targetsControl(step, index, field); }
        else if (field.kind === 'location') { control = this.locationControl(step, field); }
        else if (field.kind === 'bool') { control = this.boolControl(step, field); }
        else if (field.kind === 'enum') { control = this.enumControl(step, field); }
        else if (field.kind === 'list') { control = this.listControl(step, field); }
        else if (field.kind === 'int' || field.kind === 'number') { control = this.numberControl(step, field); }
        else { control = this.stringControl(step, field); }

        wrap.appendChild(control);
        if (field.help) { wrap.appendChild(el('p', { class: 'field-help', text: field.help })); }
        return wrap;
    };

    Builder.prototype.stringControl = function (step, field) {
        var self = this,
            multiline = field.name === 'description',
            input = el(multiline ? 'textarea' : 'input', {
                class: 'form-control', type: multiline ? false : 'text',
                rows: multiline ? 2 : false, disabled: this.locked
            });
        input.value = getPath(step.config, field.name) === undefined ? '' : String(getPath(step.config, field.name));
        input.addEventListener('input', function () {
            setPath(step.config, field.name, input.value === '' ? undefined : input.value);
            self.sync();
        });
        return input;
    };

    Builder.prototype.numberControl = function (step, field) {
        var self = this,
            input = el('input', {
                class: 'form-control', type: 'number', disabled: this.locked,
                step: field.kind === 'int' ? '1' : 'any',
                min: field.min === null ? false : String(field.min),
                max: field.max === null ? false : String(field.max)
            }),
            current = getPath(step.config, field.name);
        input.value = (current === undefined || current === null) ? '' : String(current);
        input.addEventListener('input', function () {
            if (input.value === '') { setPath(step.config, field.name, undefined); }
            else {
                // An int field stores an int: the schemas reject a numeric string on purpose.
                var n = field.kind === 'int' ? parseInt(input.value, 10) : parseFloat(input.value);
                setPath(step.config, field.name, isNaN(n) ? input.value : n);
            }
            self.sync();
        });
        return input;
    };

    Builder.prototype.boolControl = function (step, field) {
        var self = this,
            wrap = el('div', { class: 'form-check' }),
            input = el('input', { class: 'form-check-input', type: 'checkbox', disabled: this.locked });
        input.checked = getPath(step.config, field.name) === true;
        input.addEventListener('change', function () { setPath(step.config, field.name, input.checked); self.sync(); });
        wrap.appendChild(input);
        return wrap;
    };

    Builder.prototype.enumControl = function (step, field) {
        var self = this,
            select = el('select', { class: 'form-select', disabled: this.locked }),
            current = getPath(step.config, field.name);
        if (!field.required) { select.appendChild(el('option', { value: '', text: '—' })); }
        (field.choices || []).forEach(function (choice) {
            select.appendChild(el('option', { value: choice, text: choice }));
        });
        select.value = current === undefined ? '' : String(current);
        select.addEventListener('change', function () {
            setPath(step.config, field.name, select.value === '' ? undefined : select.value);
            self.sync();
        });
        return select;
    };

    Builder.prototype.listControl = function (step, field) {
        var self = this,
            area = el('textarea', { class: 'form-control font-monospace', rows: 3, disabled: this.locked }),
            current = getPath(step.config, field.name);
        area.value = Array.isArray(current) ? current.join('\n') : '';
        area.placeholder = 'One entry per line';
        area.addEventListener('input', function () {
            var values = area.value.split(/\r?\n/).map(function (s) { return s.trim(); })
                .filter(function (s) { return s !== ''; });
            setPath(step.config, field.name, values.length === 0 ? undefined : values);
            self.sync();
        });
        return area;
    };

    /**
     * A named place. The mirror rows are offered as a datalist rather than a closed select:
     * a location may name a room the mirror does not carry (a corridor, a doorway), and the
     * schema accepts any non-empty string.
     */
    Builder.prototype.locationControl = function (step, field) {
        var self = this,
            listId = 'places-' + Math.random().toString(36).slice(2),
            input = el('input', { class: 'form-control', type: 'text', list: listId, disabled: this.locked }),
            datalist = el('datalist', { id: listId }),
            wrap = el('div', {}),
            seen = {};

        this.placements.forEach(function (p) {
            var label = p.room ? (p.key + ' — ' + p.room) : p.key;
            if (seen[label]) { return; }
            seen[label] = true;
            datalist.appendChild(el('option', { value: label }));
        });
        this.rooms.forEach(function (group) {
            if (group.room === 'unassigned' || seen[group.room]) { return; }
            seen[group.room] = true;
            datalist.appendChild(el('option', { value: group.room }));
        });

        input.value = getPath(step.config, field.name) === undefined ? '' : String(getPath(step.config, field.name));
        input.addEventListener('input', function () {
            setPath(step.config, field.name, input.value === '' ? undefined : input.value);
            self.sync();
        });
        wrap.appendChild(input);
        wrap.appendChild(datalist);
        if (this.placements.length === 0) {
            wrap.appendChild(el('p', { class: 'field-help', text: 'No placements synced yet, run lab placements-export; type the place instead.' }));
        }
        return wrap;
    };

    // ── the target picker ────────────────────────────────────────────────────────────────

    Builder.prototype.targetsControl = function (step, index, field) {
        var self = this,
            wrap = el('div', { class: 'targets' }),
            targets = getPath(step.config, field.name);

        if (!Array.isArray(targets)) { targets = []; setPath(step.config, field.name, targets); }

        if (targets.length === 0) {
            wrap.appendChild(el('p', { class: 'step-note step-note-warn', text: 'No targets: nothing can satisfy this probe.' }));
        }

        targets.forEach(function (target, t) {
            wrap.appendChild(self.renderTarget(step, field, targets, target, t));
        });

        if (!this.locked) {
            wrap.appendChild(this.button(this.picker[index] ? 'Close picker' : 'Add targets', 'Pick surveyed points', false, function () {
                self.picker[index] = !self.picker[index];
                self.render();
            }));
            if (this.picker[index]) { wrap.appendChild(this.renderPicker(step, field, targets)); }
        }

        return wrap;
    };

    Builder.prototype.renderTarget = function (step, field, targets, target, t) {
        var self = this,
            row = el('div', { class: 'target' }),
            label = el('input', { class: 'form-control target-label', type: 'text', value: target.label || '', disabled: this.locked }),
            kind = el('select', { class: 'form-select target-kind', disabled: this.locked });

        row.appendChild(el('code', { class: 'target-value', text: String(target.value || '') }));
        label.addEventListener('input', function () { target.label = label.value; self.sync(); });
        row.appendChild(label);
        row.appendChild(el('span', { class: 'target-room', text: target.room || '—' }));

        TARGET_KINDS.forEach(function (k) { kind.appendChild(el('option', { value: k, text: k })); });
        kind.value = TARGET_KINDS.indexOf(target.kind) === -1 ? 'card' : target.kind;
        if (TARGET_KINDS.indexOf(target.kind) === -1) { target.kind = kind.value; }
        kind.addEventListener('change', function () { target.kind = kind.value; self.sync(); });
        row.appendChild(kind);

        if (!this.locked) {
            row.appendChild(this.button('✕', 'Remove this target', false, function () {
                targets.splice(t, 1);
                self.render();
            }, 'danger'));
        }
        return row;
    };

    Builder.prototype.renderPicker = function (step, field, targets) {
        var self = this,
            panel = el('div', { class: 'picker' }),
            kindFilter = el('select', { class: 'form-select' }),
            textFilter = el('input', { class: 'form-control', type: 'search', placeholder: 'Filter by key or room' }),
            body = el('div', { class: 'picker-body' });

        [['', 'card and node'], ['card', 'cards only'], ['node', 'nodes only']].forEach(function (pair) {
            kindFilter.appendChild(el('option', { value: pair[0], text: pair[1] }));
        });

        function has(value) {
            return targets.some(function (t) { return String(t.value) === String(value); });
        }

        function add(row) {
            if (has(row.key)) { return; }
            // kind comes from the placement, never from the author: the analysis reads it.
            targets.push({ value: row.key, label: row.key, room: row.room || '', kind: row.kind });
        }

        function draw() {
            var needle = textFilter.value.trim().toLowerCase(), wanted = kindFilter.value, drew = false;
            body.textContent = '';
            self.rooms.forEach(function (group) {
                var rows = group.rows.filter(function (row) {
                    if (wanted !== '' && row.kind !== wanted) { return false; }
                    if (needle === '') { return true; }
                    return (row.key + ' ' + (row.room || '')).toLowerCase().indexOf(needle) !== -1;
                });
                if (rows.length === 0) { return; }
                drew = true;

                var head = el('div', { class: 'picker-room' }, [el('strong', { text: group.room })]);
                head.appendChild(self.button('Add all cards in room', 'Add every card placed in ' + group.room, false, function () {
                    rows.filter(function (r) { return r.kind === 'card'; }).forEach(add);
                    self.render();
                }));
                body.appendChild(head);

                var grid = el('div', { class: 'picker-grid' });
                rows.forEach(function (row) {
                    var taken = has(row.key),
                        pick = self.button(row.key + ' · ' + row.kind, row.kind + ' at ' + (row.room || 'unassigned'), taken, function () {
                            add(row);
                            self.render();
                        });
                    if (taken) { pick.classList.add('picked'); }
                    grid.appendChild(pick);
                });
                body.appendChild(grid);
            });
            if (!drew) {
                body.appendChild(el('p', {
                    class: 'step-note step-note-warn',
                    text: self.placements.length === 0
                        ? 'The placement mirror is empty for this floor: no placements synced yet, run lab placements-export.'
                        : 'Nothing matches that filter.'
                }));
            }
        }

        kindFilter.addEventListener('change', draw);
        textFilter.addEventListener('input', draw);
        panel.appendChild(el('div', { class: 'picker-head' }, [kindFilter, textFilter]));
        panel.appendChild(body);
        draw();
        return panel;
    };

    // ── advanced JSON, palette, moves ────────────────────────────────────────────────────

    /** Raw config, two-way: typing valid JSON writes straight into the step. */
    Builder.prototype.renderAdvanced = function (step, index) {
        var self = this,
            wrap = el('div', { class: 'advanced' }),
            area = el('textarea', { class: 'form-control font-monospace', rows: 8, disabled: this.locked }),
            note = el('p', { class: 'field-help', text: 'The whole config. Saved only while it parses.' });

        area.value = JSON.stringify(step.config, null, 2);
        area.addEventListener('input', function () {
            var parsed;
            try { parsed = JSON.parse(area.value); } catch (e) {
                area.classList.add('is-invalid');
                note.textContent = 'Not valid JSON, so the typed fields above still hold: ' + e.message;
                return;
            }
            if (parsed === null || typeof parsed !== 'object' || Array.isArray(parsed)) {
                area.classList.add('is-invalid');
                note.textContent = 'A step config is a JSON object.';
                return;
            }
            area.classList.remove('is-invalid');
            note.textContent = 'Applied. Close Advanced to redraw the typed fields.';
            step.config = parsed;
            self.sync();
        });

        wrap.appendChild(el('div', { class: 'advanced-head' }, [el('strong', { text: 'Advanced: config JSON' })]));
        wrap.appendChild(area);
        wrap.appendChild(note);
        return wrap;
    };

    Builder.prototype.renderPalette = function () {
        var self = this, panel = el('div', { class: 'palette' });
        panel.appendChild(el('div', { class: 'palette-head' }, [el('strong', { text: 'Add a step' })]));

        this.schemas.forEach(function (schema) {
            var disabled = !!schema.palette.disabled_reason,
                card = el('div', { class: 'palette-item' + (disabled ? ' palette-disabled' : '') }),
                add = self.button('Add', 'Append a ' + schema.type + ' step', disabled, function () {
                    self.steps.push({ name: schema.palette.title, type: schema.type, config: {} });
                    self.render();
                });

            card.appendChild(el('div', { class: 'palette-title' }, [
                el('strong', { text: schema.palette.title }),
                el('code', { text: schema.type }),
                add
            ]));
            card.appendChild(el('p', { class: 'palette-summary', text: schema.palette.summary }));
            if (disabled) { card.appendChild(el('p', { class: 'palette-reason', text: schema.palette.disabled_reason })); }
            panel.appendChild(card);
        });

        return panel;
    };

    Builder.prototype.move = function (from, to) {
        if (to < 0 || to >= this.steps.length || from === to) { return; }
        var step = this.steps.splice(from, 1)[0];
        this.steps.splice(to, 0, step);
        this.open = {};
        this.picker = {};
        this.render();
    };

    Builder.prototype.button = function (text, title, disabled, onClick, variant) {
        var node = el('button', {
            type: 'button', title: title, disabled: !!disabled,
            class: 'btn btn-sm step-btn' + (variant === 'danger' ? ' step-btn-danger' : ''),
            text: text
        });
        node.addEventListener('click', onClick);
        return node;
    };

    window.MonadQuestBuilder = {
        mount: function (root) {
            if (!root) { return null; }
            var builder = new Builder(root);
            builder.render();
            return builder;
        }
    };
})();

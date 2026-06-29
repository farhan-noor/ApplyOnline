(function ($) {
  const EDITOR_INPUTS = {
    id: ".aol-ad-fb-v2-id",
    label: ".aol-ad-fb-v2-label",
    required: ".aol-ad-fb-v2-required",
    placeholder: ".aol-ad-fb-v2-placeholder",
    description: ".aol-ad-fb-v2-description",
    options: ".aol-ad-fb-v2-options",
    text: ".aol-ad-fb-v2-text",
    height: ".aol-ad-fb-v2-height",
    allowed_file_types: ".aol-ad-fb-v2-allowed-file-types",
    file_max_size: ".aol-ad-fb-v2-file-max-size",
  };

  function getRegistry() {
    return (window.aolAdFbV2 && window.aolAdFbV2.registry) || {};
  }

  function getTypeDef(type) {
    const registry = getRegistry();
    return registry[type] || registry.text || { label: type, properties: ["id", "label"], preview: "input", inputType: "text" };
  }

  function parseSchema($wrap) {
    const $input = $wrap.find(".aol-ad-fb-v2-schema");
    try {
      const val = $input.val();
      const parsed = val ? JSON.parse(val) : [];
      return Array.isArray(parsed) ? parsed : [];
    } catch (e) {
      return [];
    }
  }

  function writeSchema($wrap, schema) {
    $wrap.find(".aol-ad-fb-v2-schema").val(JSON.stringify(schema || []));
  }

  function typeLabel(type) {
    const def = getTypeDef(type);
    return def.label || type || "";
  }

  function normalizeId(id) {
    return String(id || "")
      .trim()
      .toLowerCase()
      .replace(/[^a-z0-9_]/g, "_")
      .replace(/_{2,}/g, "_")
      .replace(/^_+|_+$/g, "");
  }

  function showModal($wrap) {
    $wrap.find(".aol-ad-fb-v2-modal").show();
  }

  function hideModal($wrap) {
    $wrap.find(".aol-ad-fb-v2-modal").hide();
  }

  function elementKindLabel(kind) {
    const i18n = (window.aolAdFbV2 && window.aolAdFbV2.i18n) || {};
    if (kind === "section") {
      return i18n.formSection || "Form Section";
    }
    return i18n.formInput || "Form Input";
  }

  function syncTypeVisibility($wrap, type) {
    const def = getTypeDef(type);
    const allowed = def.properties || [];
    $wrap.find("[data-property]").each(function () {
      const prop = $(this).attr("data-property");
      $(this).toggle(allowed.indexOf(prop) !== -1);
    });
  }

  function setActiveType($wrap, type) {
    const registry = getRegistry();
    const t = registry[type] ? type : "text";
    $wrap.find(".aol-ad-fb-v2-type").val(t);
    $wrap.find(".aol-ad-fb-v2-type-tab").removeClass("is-active").attr("aria-selected", "false");
    $wrap.find('.aol-ad-fb-v2-type-tab[data-type="' + t + '"]').addClass("is-active").attr("aria-selected", "true");
    syncTypeVisibility($wrap, t);
  }

  function ensureTypeTabs($wrap) {
    const $kinds = $wrap.find(".aol-ad-fb-v2-element-kinds");
    if ($kinds.children().length) return;

    const registry = getRegistry();
    const grouped = { input: [], section: [] };

    Object.keys(registry).forEach((k) => {
      const kind = registry[k].elementKind || "input";
      if (!grouped[kind]) {
        grouped[kind] = [];
      }
      grouped[kind].push(k);
    });

    ["input", "section"].forEach((kindKey) => {
      const types = grouped[kindKey];
      if (!types || !types.length) return;

      const $group = $('<div class="aol-ad-fb-v2-kind-group"></div>');
      $group.attr("data-kind", kindKey);
      $group.append(
        '<div class="aol-ad-fb-v2-kind-group__title">' + escHtml(elementKindLabel(kindKey)) + "</div>"
      );

      const $tabs = $('<div class="aol-ad-fb-v2-type-tabs" role="tablist"></div>');
      types.forEach((k) => {
        const def = registry[k];
        const icon = def.icon || "dashicons-admin-generic";
        const $btn = $('<button type="button" class="aol-ad-fb-v2-type-tab" role="tab"></button>');
        $btn.attr("data-type", k);
        $btn.attr("aria-selected", "false");
        $btn.append('<span class="dashicons ' + icon + '"></span>');
        $btn.append('<span class="aol-ad-fb-v2-type-tab__label"></span>');
        $btn.find(".aol-ad-fb-v2-type-tab__label").text(def.label || k);
        $tabs.append($btn);
      });

      $group.append($tabs);
      $kinds.append($group);
    });
  }

  function escHtml(s) {
    return String(s || "")
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#39;");
  }

  function splitOptions(f) {
    const raw = String((f && f.options) || "");
    return raw
      .split(",")
      .map((x) => x.trim())
      .filter((x) => x);
  }

  const previewRenderers = {
    separator: function ($control) {
      $control.append('<hr class="aol-ad-fb-v2-separator" />');
    },
    paragraph: function ($control, f) {
      const text = (f.text || f.description || "").trim();
      $control.append('<div class="aol-ad-fb-v2-paragraph">' + escHtml(text || "…") + "</div>");
    },
    select: function ($control, f) {
      const opts = splitOptions(f);
      const $sel = $('<select class="aol-ad-fb-v2-preview__input" disabled></select>');
      if (!opts.length) {
        $sel.append($("<option/>").text("Option 1"));
      } else {
        opts.slice(0, 20).forEach((o) => $sel.append($("<option/>").text(o)));
      }
      $control.append($sel);
    },
    choices: function ($control, f, def) {
      const opts = splitOptions(f);
      const list = opts.length ? opts.slice(0, 4) : ["Option 1", "Option 2"];
      const inputType = def.choices === "radio" ? "radio" : "checkbox";
      const $box = $('<div class="aol-ad-fb-v2-preview__choices"></div>');
      list.forEach((o, i) => {
        $box.append(
          '<label class="aol-ad-fb-v2-choice"><input type="' +
            inputType +
            '" disabled name="aol-ad-fb-v2-choices-preview" ' +
            (i === 0 ? "checked" : "") +
            " /> <span>" +
            escHtml(o) +
            "</span></label>"
        );
      });
      $control.append($box);
    },
    textarea: function ($control, f) {
      const $ta = $('<textarea class="aol-ad-fb-v2-preview__input" rows="2" disabled></textarea>');
      if (f.placeholder) $ta.attr("placeholder", f.placeholder);
      $control.append($ta);
    },
    file: function ($control) {
      $control.append('<input class="aol-ad-fb-v2-preview__input" type="file" disabled />');
    },
    input: function ($control, f, def) {
      const htmlType = def.inputType || "text";
      const $in = $('<input class="aol-ad-fb-v2-preview__input" disabled />').attr("type", htmlType);
      if (f.placeholder) $in.attr("placeholder", f.placeholder);
      $control.append($in);
    },
  };

  function renderFieldPreview($row, f) {
    const label = f.label || f.id || "";
    const type = f.type || "text";
    const help = f.description || "";
    const def = getTypeDef(type);
    const kindLabel = elementKindLabel(def.elementKind || "input");

    const $wrap = $row.find(".aol-ad-fb-v2-preview");
    $wrap.find(".aol-ad-fb-v2-preview__label").text(label);
    $wrap.find(".aol-ad-fb-v2-preview__type").text(kindLabel + " · " + typeLabel(type));
    $wrap.find(".aol-ad-fb-v2-preview__help").text(help);

    const $control = $wrap.find(".aol-ad-fb-v2-preview__control");
    $control.empty();

    const renderer = previewRenderers[def.preview] || previewRenderers.input;
    renderer($control, f, def);
  }

  function readEditorProperty($wrap, prop) {
    const sel = EDITOR_INPUTS[prop];
    if (!sel) return "";
    const $el = $wrap.find(sel);
    if (!$el.length) return "";
    if ($el.is(":checkbox")) {
      return $el.is(":checked") ? 1 : 0;
    }
    if ($el.attr("type") === "number") {
      return parseInt($el.val() || "0", 10) || 0;
    }
    return String($el.val() || "").trim();
  }

  function writeEditorProperty($wrap, prop, value) {
    const sel = EDITOR_INPUTS[prop];
    if (!sel) return;
    const $el = $wrap.find(sel);
    if (!$el.length) return;
    if ($el.is(":checkbox")) {
      $el.prop("checked", !!value);
    } else {
      $el.val(value === undefined || value === null ? "" : value);
    }
  }

  function pickFieldForType(field, type) {
    const def = getTypeDef(type);
    const props = def.properties || [];
    const out = {
      id: field.id || "",
      type: type,
      label: field.label || "",
    };
    if (def.defaults) {
      Object.keys(def.defaults).forEach((k) => {
        if (props.indexOf(k) !== -1) {
          out[k] = def.defaults[k];
        }
      });
    }
    props.forEach((prop) => {
      if (prop === "id" || prop === "type") return;
      if (field[prop] !== undefined && field[prop] !== null && field[prop] !== "") {
        out[prop] = field[prop];
      } else if (out[prop] === undefined) {
        out[prop] = prop === "required" || prop === "preselect" ? 0 : prop === "height" || prop === "file_max_size" || prop === "limit" ? 0 : "";
      }
    });
    return out;
  }

  function openFieldEditor($wrap, field, idx) {
    ensureTypeTabs($wrap);
    const f = field || { type: "text" };
    const type = f.type || "text";

    $wrap.data("editIdx", typeof idx === "number" ? idx : null);
    if (typeof idx === "number") {
      $wrap.data("insertAt", null);
    }

    const picked = pickFieldForType(f, type);
    const def = getTypeDef(type);
    (def.properties || []).forEach((prop) => {
      writeEditorProperty($wrap, prop, picked[prop]);
    });

    setActiveType($wrap, type);
    showModal($wrap);
  }

  function readFieldFromEditor($wrap) {
    const i18n = (window.aolAdFbV2 && window.aolAdFbV2.i18n) || {};
    const type = $wrap.find(".aol-ad-fb-v2-type").val() || "text";
    const def = getTypeDef(type);
    const props = def.properties || [];
    const rules = def.rules || {};

    const field = { type: type };

    props.forEach((prop) => {
      if (prop === "id") {
        field.id = normalizeId(readEditorProperty($wrap, "id"));
      } else {
        field[prop] = readEditorProperty($wrap, prop);
      }
    });

    if (rules.id === "required" && !field.id) {
      throw new Error(i18n.idRequired || "ID required");
    }
    if (rules.label === "required" && !String(field.label || "").trim()) {
      throw new Error(i18n.labelRequired || "Label required");
    }

    return pickFieldForType(field, type);
  }

  function renderRows($wrap) {
    const schema = parseSchema($wrap);
    const $tbody = $wrap.find(".aol-ad-fb-v2-rows");
    $tbody.empty();

    const tpl = $wrap.find(".aol-ad-fb-v2-row-template")[0];
    if (!tpl) return;

    schema.forEach((f, idx) => {
      const $row = $($(tpl.content).children()[0].cloneNode(true));
      $row.attr("data-idx", idx);
      $row.find(".aol-ad-fb-v2-add--inline").attr("data-insert-at", String(idx + 1));
      renderFieldPreview($row, f);
      $tbody.append($row);
    });
  }

  function move(schema, fromIdx, toIdx) {
    if (toIdx < 0 || toIdx >= schema.length) return schema;
    const copy = schema.slice();
    const [it] = copy.splice(fromIdx, 1);
    copy.splice(toIdx, 0, it);
    return copy;
  }

  $(function () {
    $(".aol-ad-fb-v2").each(function () {
      const $wrap = $(this);
      renderRows($wrap);

      $wrap.on("click", ".aol-ad-fb-v2-add", function () {
        const raw = $(this).attr("data-insert-at");
        if (raw !== undefined && raw !== "") {
          const n = parseInt(raw, 10);
          $wrap.data("insertAt", isNaN(n) ? null : n);
        } else {
          $wrap.data("insertAt", null);
        }
        const defaults = getTypeDef("text").defaults || {};
        openFieldEditor($wrap, Object.assign({ type: "text" }, defaults), null);
      });

      $wrap.on("click", ".aol-ad-fb-v2-edit", function () {
        const idx = parseInt($(this).closest("tr").attr("data-idx"), 10);
        const schema = parseSchema($wrap);
        openFieldEditor($wrap, schema[idx], idx);
      });

      $wrap.on("click", "tr.aol-ad-fb-v2-row", function (e) {
        const $t = $(e.target);
        if ($t.closest(".aol-ad-fb-v2-row__panel").length) return;
        const idx = parseInt($(this).attr("data-idx"), 10);
        const schema = parseSchema($wrap);
        openFieldEditor($wrap, schema[idx], idx);
      });

      $wrap.on("keydown", "tr.aol-ad-fb-v2-row", function (e) {
        if (e.key !== "Enter" && e.key !== " ") return;
        e.preventDefault();
        const idx = parseInt($(this).attr("data-idx"), 10);
        const schema = parseSchema($wrap);
        openFieldEditor($wrap, schema[idx], idx);
      });

      $wrap.on("click", ".aol-ad-fb-v2-delete", function () {
        const i18n = (window.aolAdFbV2 && window.aolAdFbV2.i18n) || {};
        if (!window.confirm(i18n.confirmDel || "Delete?")) return;
        const idx = parseInt($(this).closest("tr").attr("data-idx"), 10);
        const schema = parseSchema($wrap);
        schema.splice(idx, 1);
        writeSchema($wrap, schema);
        renderRows($wrap);
      });

      $wrap.on("click", ".aol-ad-fb-v2-up", function () {
        const idx = parseInt($(this).closest("tr").attr("data-idx"), 10);
        const schema = parseSchema($wrap);
        writeSchema($wrap, move(schema, idx, idx - 1));
        renderRows($wrap);
      });

      $wrap.on("click", ".aol-ad-fb-v2-down", function () {
        const idx = parseInt($(this).closest("tr").attr("data-idx"), 10);
        const schema = parseSchema($wrap);
        writeSchema($wrap, move(schema, idx, idx + 1));
        renderRows($wrap);
      });

      $wrap.on("click", ".aol-ad-fb-v2-type-tab", function () {
        const newType = $(this).attr("data-type");
        const oldType = $wrap.find(".aol-ad-fb-v2-type").val() || "text";
        const draft = { type: oldType };
        (getTypeDef(oldType).properties || []).forEach((prop) => {
          if (prop === "id") {
            draft.id = normalizeId(readEditorProperty($wrap, "id"));
          } else {
            draft[prop] = readEditorProperty($wrap, prop);
          }
        });
        const picked = pickFieldForType(draft, newType);
        $wrap.find(".aol-ad-fb-v2-type").val(newType);
        setActiveType($wrap, newType);
        (getTypeDef(newType).properties || []).forEach((prop) => {
          writeEditorProperty($wrap, prop, picked[prop]);
        });
      });

      $wrap.on("click", ".aol-ad-fb-v2-close, .aol-ad-fb-v2-cancel", function () {
        $wrap.data("insertAt", null);
        hideModal($wrap);
      });

      $wrap.on("click", ".aol-ad-fb-v2-modal", function (e) {
        if (e.target === this) {
          $wrap.data("insertAt", null);
          hideModal($wrap);
        }
      });

      $wrap.on("click", ".aol-ad-fb-v2-save", function () {
        try {
          const schema = parseSchema($wrap);
          const idx = $wrap.data("editIdx");
          const insertAt = $wrap.data("insertAt");
          const f = readFieldFromEditor($wrap);

          if (typeof idx === "number" && idx !== null) {
            schema[idx] = f;
          } else if (typeof insertAt === "number" && !isNaN(insertAt)) {
            const at = Math.max(0, Math.min(insertAt, schema.length));
            schema.splice(at, 0, f);
          } else {
            schema.push(f);
          }

          $wrap.data("insertAt", null);
          writeSchema($wrap, schema);
          hideModal($wrap);
          renderRows($wrap);
        } catch (err) {
          window.alert(err && err.message ? err.message : "Error");
        }
      });
    });
  });
})(jQuery);

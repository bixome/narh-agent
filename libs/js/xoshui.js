/* ==========================================================================
   XOSHUI 1.0 — comportements. Module ES, aucune dépendance.
   <script type="module" src="/libs/js/xoshui.js"></script>

   Tout se déclare en HTML :
     [data-xo-list]            navigation ↑↓, sélection, événement "xo:select"
                               ="horizontal" ←→ · ="grid" ←→ d'une case, ↑↓ d'une
                               rangée (calendrier, pack de glyphes)
     [data-xo-tabs]            onglets ←→, bascule des [role="tabpanel"]
     [data-xo-open="#id"]      ouvre la <dialog> ciblée
     [data-xo-close]           ferme la <dialog> parente
     .xo-dropdown              <details> : Échap et clic extérieur referment
     [data-xo-menu="#id"]      menu contextuel au clic droit, événement "xo:menu"
     [data-xo-palette]         palette de commandes, ouverte par Ctrl+K
     [data-xo-help]            aide des raccourcis, ouverte par ?
     [data-xo-split]           séparateur redimensionnable (souris et flèches)
     [data-xo-toast]           notification : bouton de fermeture, délai optionnel
     [data-xo-key="y"]         dans une modale : la touche active le bouton
     [data-xo-guard="texte"]   déverrouille [data-xo-guard-ok] quand la saisie
                               correspond
   ========================================================================== */

const KEY_PREV = ['ArrowUp', 'ArrowLeft'];
const KEY_NEXT = ['ArrowDown', 'ArrowRight'];

/* --- Listes ------------------------------------------------------------- */

function initList(root) {
  const items = () => [...root.querySelectorAll('.xo-list__item, .xo-cal__day, tbody tr')]
    .filter((el) => el.getAttribute('aria-disabled') !== 'true' && !el.hidden);

  /* Nombre de colonnes d'une grille, lu dans la grille elle-même. Compter les
     éléments de la première rangée ne marcherait pas : celle d'un calendrier
     est incomplète dès que le mois ne commence pas un lundi. Lu à chaque appel
     plutôt que déclaré — une grille qui se réagence reste juste. */
  const cols = () => getComputedStyle(root).gridTemplateColumns.split(' ').filter(Boolean).length || 1;

  const select = (el, notify = true) => {
    if (!el) return;
    for (const i of items()) i.setAttribute('aria-selected', String(i === el));
    el.scrollIntoView({ block: 'nearest' });
    if (notify) {
      root.dispatchEvent(new CustomEvent('xo:select', {
        bubbles: true,
        detail: { value: el.dataset.value ?? null, item: el },
      }));
    }
  };

  const current = () => root.querySelector('[aria-selected="true"]');

  const move = (delta) => {
    const list = items();
    if (!list.length) return;
    const i = list.indexOf(current());
    select(list[(i + delta + list.length) % list.length]);
  };

  root.addEventListener('keydown', (e) => {
    const mode = root.dataset.xoList;
    const grille = mode === 'grid';
    // En grille, ↑↓ sautent une rangée entière et ←→ avancent d'une case.
    const pas  = grille ? cols() : 1;
    const prev = mode === 'horizontal' ? 'ArrowLeft'  : 'ArrowUp';
    const next = mode === 'horizontal' ? 'ArrowRight' : 'ArrowDown';

    if (e.key === prev)            { e.preventDefault(); move(-pas); }
    else if (e.key === next)       { e.preventDefault(); move(pas); }
    else if (grille && e.key === 'ArrowLeft')  { e.preventDefault(); move(-1); }
    else if (grille && e.key === 'ArrowRight') { e.preventDefault(); move(1); }
    else if (e.key === 'Home')     { e.preventDefault(); select(items()[0]); }
    else if (e.key === 'End')      { e.preventDefault(); select(items().at(-1)); }
    else if (e.key === 'Enter' || e.key === ' ') {
      const el = current();
      if (!el) return;
      e.preventDefault();
      root.dispatchEvent(new CustomEvent('xo:activate', {
        bubbles: true,
        detail: { value: el.dataset.value ?? null, item: el },
      }));
      el.querySelector('a, button')?.click();
    }
  });

  root.addEventListener('click', (e) => {
    const el = e.target.closest('.xo-list__item, .xo-cal__day, tbody tr');
    if (el && root.contains(el)) select(el);
  });

  if (!root.hasAttribute('tabindex')) root.tabIndex = 0;
  if (!current()) select(items()[0], false);
}

/* --- Onglets ------------------------------------------------------------ */

function initTabs(root) {
  const tabs = [...root.querySelectorAll('[role="tab"]')];

  const show = (tab) => {
    for (const t of tabs) {
      const on = t === tab;
      t.setAttribute('aria-selected', String(on));
      t.tabIndex = on ? 0 : -1;
      const panel = document.getElementById(t.getAttribute('aria-controls'));
      if (panel) panel.hidden = !on;
    }
  };

  root.addEventListener('click', (e) => {
    const tab = e.target.closest('[role="tab"]');
    if (tab) show(tab);
  });

  root.addEventListener('keydown', (e) => {
    const i = tabs.indexOf(document.activeElement);
    if (i === -1) return;
    let next = null;
    if (KEY_NEXT.includes(e.key))      next = tabs[(i + 1) % tabs.length];
    else if (KEY_PREV.includes(e.key)) next = tabs[(i - 1 + tabs.length) % tabs.length];
    else if (e.key === 'Home')         next = tabs[0];
    else if (e.key === 'End')          next = tabs.at(-1);
    if (!next) return;
    e.preventDefault();
    next.focus();
    show(next);
  });

  show(tabs.find((t) => t.getAttribute('aria-selected') === 'true') ?? tabs[0]);
}

/* --- Modales ------------------------------------------------------------ */

function initDialogs(scope) {
  scope.addEventListener('click', (e) => {
    const opener = e.target.closest('[data-xo-open]');
    if (opener) {
      document.querySelector(opener.dataset.xoOpen)?.showModal();
      return;
    }
    if (e.target.closest('[data-xo-close]')) {
      e.target.closest('dialog')?.close();
    }
  });
}

/* --- Modales : touche d'action, et garde de saisie ------------------------ */

/* Dans une boîte, [data-xo-key="y"] rend un bouton activable par sa touche —
   la convention des confirmations en mode texte. La saisie est épargnée :
   sinon on ne pourrait plus taper la lettre dans un champ. */
function initDialogKeys(scope) {
  scope.addEventListener('keydown', (e) => {
    if (e.ctrlKey || e.metaKey || e.altKey || isTyping(e.target)) return;
    const dialog = e.target.closest?.('dialog[open]') ?? document.querySelector('dialog[open]');
    if (!dialog) return;
    const cible = [...dialog.querySelectorAll('[data-xo-key]')]
      .find((el) => el.dataset.xoKey.toLowerCase() === e.key.toLowerCase());
    if (!cible) return;
    e.preventDefault();
    cible.click();
  });
}

/* Garde de saisie : le bouton reste inerte tant que la valeur attendue n'est
   pas recopiée. Pour les suppressions qu'on ne veut pas voir confirmées d'un
   réflexe. */
function initGuards(scope) {
  for (const champ of scope.querySelectorAll('[data-xo-guard]')) {
    const portee = champ.closest('dialog, form') ?? document;
    const boutons = [...portee.querySelectorAll('[data-xo-guard-ok]')];
    if (!boutons.length) continue;

    const verifier = () => {
      const ok = champ.value.trim() === champ.dataset.xoGuard;
      for (const b of boutons) b.disabled = !ok;
    };

    champ.addEventListener('input', verifier);
    verifier();
  }
}

/* --- Menus déroulants ---------------------------------------------------- */

/* Le <details> s'ouvre et se ferme nativement. On n'ajoute que ce que le
   navigateur ne fait pas : refermer sur Échap et au clic extérieur. */
function initDropdowns(scope) {
  const closeAll = (except = null) => {
    for (const d of document.querySelectorAll('.xo-dropdown[open]')) {
      if (d !== except) d.open = false;
    }
  };

  scope.addEventListener('click', (e) => {
    const inside = e.target.closest('.xo-dropdown');
    closeAll(inside);
    // Un choix dans le menu referme le menu.
    if (inside && e.target.closest('.xo-dropdown__item')) inside.open = false;
  });

  scope.addEventListener('keydown', (e) => {
    if (e.key !== 'Escape') return;
    const open = document.querySelector('.xo-dropdown[open]');
    if (!open) return;
    e.preventDefault();
    open.open = false;
    open.querySelector('summary')?.focus();
  });
}

/* --- Menu contextuel ----------------------------------------------------- */

/**
 * Clic droit dans [data-xo-menu="#id"] : le menu s'ouvre au curseur.
 *
 * Le menu vit hors de la liste, une seule fois dans la page, et sert toutes ses
 * lignes : le recopier sur chaque élément multiplierait le balisage par le
 * nombre de lignes pour un menu dont un seul est visible à la fois.
 *
 * La cible retenue est le premier ancêtre porteur de `data-value` — la même
 * convention que les listes. Le choix émet `xo:menu` sur le conteneur, avec
 * `{action, value, item}` ; le module ne décide de rien.
 */
function initMenu(root) {
  const menu = document.querySelector(root.dataset.xoMenu);
  if (!menu) return;

  let cible = null;

  const fermer = () => {
    menu.hidden = true;
    cible = null;
  };

  root.addEventListener('contextmenu', (e) => {
    const item = e.target.closest('[data-value]');
    if (!item || !root.contains(item)) return;

    e.preventDefault();
    cible = item;

    // L'intitulé de la cible, s'il y a de quoi le remplir : un menu contextuel
    // sans rappel de ce qu'il vise laisse un doute dans une liste longue.
    const titre = menu.querySelector('.xo-menu__titre');
    if (titre) titre.textContent = (item.dataset.libelle ?? item.textContent).trim().slice(0, 80);

    // Mesurer une fois affiché : un élément caché n'a pas de dimensions.
    menu.hidden = false;
    const large = menu.offsetWidth;
    const haut = menu.offsetHeight;

    // Rabattre plutôt que déborder — près du bord bas, un menu qui sort de la
    // fenêtre est inatteignable, la page ne défilant pas sous lui.
    const x = e.clientX + large > window.innerWidth ? e.clientX - large : e.clientX;
    const y = e.clientY + haut > window.innerHeight ? e.clientY - haut : e.clientY;

    menu.style.left = `${Math.max(0, x)}px`;
    menu.style.top = `${Math.max(0, y)}px`;
  });

  menu.addEventListener('click', (e) => {
    const choix = e.target.closest('.xo-menu__item');
    if (!choix || choix.getAttribute('aria-disabled') === 'true') return;

    root.dispatchEvent(new CustomEvent('xo:menu', {
      bubbles: true,
      detail: { action: choix.dataset.action ?? null, value: cible?.dataset.value ?? null, item: cible },
    }));
    fermer();
  });

  // Un menu posé en coordonnées fixes suivrait le curseur au défilement : il se
  // ferme, comme le fait le système.
  document.addEventListener('pointerdown', (e) => {
    if (!menu.hidden && !e.target.closest('.xo-menu')) fermer();
  });
  document.addEventListener('scroll', () => { if (!menu.hidden) fermer(); }, true);
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && !menu.hidden) fermer();
  });
}

/* --- Palette de commandes ------------------------------------------------ */

const isTyping = (el) =>
  el instanceof HTMLElement &&
  (el.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(el.tagName));

function initPalette(root) {
  const input = root.querySelector('input');
  const list  = root.querySelector('[data-xo-list]');
  const empty = root.querySelector('.xo-palette__empty');
  const items = [...root.querySelectorAll('.xo-list__item')];

  // Mémorise le libellé d'origine : le filtrage y insère des <mark>.
  const labels = new Map(
    items.map((el) => [el, el.querySelector('.xo-palette__label') ?? el]),
  );
  const texts = new Map([...labels].map(([el, lab]) => [el, lab.textContent.trim()]));

  const filter = (q) => {
    const needle = q.trim().toLowerCase();
    let visible = 0;

    for (const el of items) {
      const text = texts.get(el);
      const at   = needle ? text.toLowerCase().indexOf(needle) : -1;
      const show = !needle || at !== -1;
      el.hidden = !show;
      if (show) visible++;

      const label = labels.get(el);
      if (!needle || at === -1) {
        label.textContent = text;
      } else {
        // textContent puis insertion du <mark> : aucune chaîne HTML concaténée.
        label.textContent = '';
        label.append(
          text.slice(0, at),
          Object.assign(document.createElement('mark'), {
            className: 'xo-mark',
            textContent: text.slice(at, at + needle.length),
          }),
          text.slice(at + needle.length),
        );
      }
    }

    if (empty) empty.hidden = visible > 0;

    // La sélection doit rester sur une ligne visible.
    const current = list?.querySelector('[aria-selected="true"]');
    if (!current || current.hidden) {
      const first = items.find((el) => !el.hidden);
      for (const el of items) el.setAttribute('aria-selected', String(el === first));
    }
  };

  input?.addEventListener('input', () => filter(input.value));

  // Les flèches et Entrée sont saisies dans le champ : on les relaie à la liste.
  input?.addEventListener('keydown', (e) => {
    if (!['ArrowUp', 'ArrowDown', 'Home', 'End', 'Enter'].includes(e.key)) return;
    e.preventDefault();
    list?.dispatchEvent(new KeyboardEvent('keydown', { key: e.key }));
  });

  root.addEventListener('close', () => {
    if (input) input.value = '';
    filter('');
  });

  root.addEventListener('xo:activate', () => root.close());
  root.addEventListener('click', (e) => {
    if (e.target.closest('.xo-list__item')) root.close();
  });
}

/* --- Raccourcis globaux -------------------------------------------------- */

function initShortcuts() {
  document.addEventListener('keydown', (e) => {
    // Ctrl+K / Cmd+K : palette
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
      const palette = document.querySelector('[data-xo-palette]');
      if (!palette) return;
      e.preventDefault();
      if (!palette.open) palette.showModal();
      palette.querySelector('input')?.focus();
      return;
    }

    // ? : aide — sauf pendant une saisie
    if (e.key === '?' && !isTyping(document.activeElement)) {
      const help = document.querySelector('[data-xo-help]');
      if (!help) return;
      e.preventDefault();
      if (!help.open) help.showModal();
    }
  });
}

/* --- Notifications ------------------------------------------------------- */

function initToasts(scope) {
  const dismiss = (toast) => {
    clearTimeout(Number(toast.dataset.xoTimer));
    toast.remove();
  };

  scope.addEventListener('click', (e) => {
    const btn = e.target.closest('.xo-toast__close');
    if (btn) dismiss(btn.closest('.xo-toast'));
  });

  for (const toast of scope.querySelectorAll('[data-xo-toast]')) {
    const delay = Number(toast.dataset.xoToast);
    if (delay > 0) toast.dataset.xoTimer = String(setTimeout(() => dismiss(toast), delay));
  }
}

/* --- Séparateur redimensionnable ----------------------------------------- */

function initSplit(root) {
  const handle = root.querySelector('.xo-split__handle');
  if (!handle) return;

  const setPercent = (pct) => {
    root.style.setProperty('--xo-split', `${Math.min(85, Math.max(15, pct))}%`);
    handle.setAttribute('aria-valuenow', String(Math.round(pct)));
  };

  const onMove = (e) => {
    const box = root.getBoundingClientRect();
    setPercent(((e.clientX - box.left) / box.width) * 100);
  };

  const stop = () => {
    document.removeEventListener('pointermove', onMove);
    document.removeEventListener('pointerup', stop);
  };

  handle.addEventListener('pointerdown', (e) => {
    e.preventDefault();
    document.addEventListener('pointermove', onMove);
    document.addEventListener('pointerup', stop);
  });

  handle.addEventListener('keydown', (e) => {
    const step = e.key === 'ArrowLeft' ? -5 : e.key === 'ArrowRight' ? 5 : 0;
    if (!step) return;
    e.preventDefault();
    const box  = root.getBoundingClientRect();
    const curr = (handle.getBoundingClientRect().left - box.left) / box.width * 100;
    setPercent(curr + step);
  });
}

/* --- Montage ------------------------------------------------------------ */

/* Un élément peut porter plusieurs comportements — `data-xo-list` et
   `data-xo-menu` sur la même liste, par exemple. Retenir « déjà monté » par
   élément ne suffit donc pas : le premier hook monté ferait sauter les
   suivants. On retient le couple élément × comportement. */
const mounted = new WeakMap();

function une_fois(el, nom, init) {
  let faits = mounted.get(el);
  if (!faits) {
    faits = new Set();
    mounted.set(el, faits);
  }
  if (faits.has(nom)) {
    return;
  }
  faits.add(nom);
  init(el);
}

export function mount(scope = document) {
  for (const [nom, init] of [
    ['list', initList],
    ['tabs', initTabs],
    ['palette', initPalette],
    ['split', initSplit],
    ['menu', initMenu],
  ]) {
    for (const el of scope.querySelectorAll(`[data-xo-${nom}]`)) {
      une_fois(el, nom, init);
    }
  }
  initToasts(scope);
  initGuards(scope);
}

if (!mounted.has(document.body ?? document)) {
  initDialogs(document);
  initDropdowns(document);
  initDialogKeys(document);
  initShortcuts();
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => mount());
  } else {
    mount();
  }
}

export default { mount };

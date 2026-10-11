/**
 * Dashboard Sortable - Alpine.js drag-and-drop component for dashboard widgets.
 *
 * Provides reordering of dashboard widgets via:
 *   - HTML5 Drag and Drop API (mouse on desktop)
 *   - Touch events (iOS/Android mobile and tablet)
 *   - Keyboard navigation (arrow keys + Enter/Space for accessibility)
 *
 * Designed for use with Livewire components. When the order changes, the
 * component calls `$wire.reorderWidgets(newOrder)` to persist the new layout.
 *
 * Live preview: while a widget is dragged (mouse or touch), the other widgets
 * slide into the places they will take after the drop. The preview never
 * moves DOM nodes — Livewire owns the DOM — it sets each item's CSS `order`
 * (see previewStyle) and animates the change with FLIP. Dropping anywhere in
 * the grid commits the previewed order; ending the drag elsewhere, or
 * cancelling it, slides everything back.
 *
 * Usage in Blade:
 *   <div x-data="dashboardSortable($wire, widgetIds)">
 *       <div x-bind="sortableContainer">
 *           @foreach ($widgets as $index => $widget)
 *               <div x-bind="sortableItem({{ $index }})" data-widget-id="{{ $widget['id'] }}">
 *                   ...widget content...
 *               </div>
 *           @endforeach
 *       </div>
 *   </div>
 *
 * @param {Object} wire - The Livewire `$wire` proxy for calling server methods.
 * @param {string[]} initialOrder - Array of widget IDs in their initial display order.
 */
export default function dashboardSortable(wire, initialOrder = []) { return ({
    /** @type {number|null} Index of the element currently being dragged. */
    draggedIndex: null,

    /** @type {HTMLElement|null} Reference to the element being dragged. */
    draggedElement: null,

    /** @type {string[]} Current widget ID order. Mutated during reorder. */
    widgetOrder: [...initialOrder],

    /** @type {boolean} Whether edit/reorder mode is active. */
    enabled: false,

    /** @type {boolean} Whether a touch drag is in progress. */
    isTouchDragging: false,

    /** @type {HTMLElement|null} Clone element used for touch drag visual feedback. */
    touchClone: null,

    /** @type {number|null} Index of the keyboard-focused item in grab mode. */
    keyboardGrabbedIndex: null,

    /** @type {string} Screen reader announcement text. */
    announceMessage: '',

    /** @type {string[]|null} Order shown while dragging; null when not previewing. */
    previewOrder: null,

    /** @type {string|null} ID of the widget being dragged (mouse or touch). */
    draggedId: null,

    /** @type {number} Timestamp until which preview moves are ignored (animation in flight). */
    previewLockedUntil: 0,

    /** @type {{x: number, y: number}|null} Pointer position at the last preview move. */
    lastPreviewPoint: null,

    /** @type {boolean} Whether the current drag ended in a drop that commits the preview. */
    dropCommitted: false,

    /** @type {number|null} Timer that retries a move skipped while an animation was running. */
    previewRetryTimer: null,

    /**
     * Lifecycle: runs automatically when Alpine initializes this component.
     * Binds global touch-move and touch-end handlers (passive: false for
     * preventDefault to work on iOS).
     */
    init() {
        this._boundTouchMove = this.touchMove.bind(this);
        this._boundTouchEnd = this.touchEnd.bind(this);

        // Listen for edit mode changes from Livewire
        this._boundEditModeChanged = (event) => {
            this.setEnabled(event.detail.enabled);
        };
        globalThis.addEventListener('edit-mode-changed', this._boundEditModeChanged);
    },

    /**
     * Lifecycle: runs when the component is destroyed.
     * Cleans up any leftover touch clone and global listeners.
     */
    destroy() {
        clearTimeout(this.previewRetryTimer);
        this.cleanupTouchClone();
        document.removeEventListener('touchmove', this._boundTouchMove);
        document.removeEventListener('touchend', this._boundTouchEnd);
        globalThis.removeEventListener('edit-mode-changed', this._boundEditModeChanged);
    },

    /**
     * Enable or disable sorting mode.
     *
     * @param {boolean} state
     */
    setEnabled(state) {
        this.enabled = state;
        if (!state) {
            this.dragEnd();
            this.cancelKeyboardGrab();
        }
    },

    /**
     * Reset the widget order from external data (e.g., after Livewire refresh).
     *
     * @param {string[]} newOrder
     */
    resetOrder(newOrder) {
        this.widgetOrder = [...newOrder];
    },

    // -------------------------------------------------------------------------
    // Live drag preview
    // -------------------------------------------------------------------------

    /**
     * Inline style for an item: its CSS grid `order` while a preview is shown.
     *
     * @param {string} widgetId
     * @returns {Object}
     */
    previewStyle(widgetId) {
        if (!this.previewOrder) {
            return {};
        }

        return { order: this.previewOrder.indexOf(widgetId) };
    },

    /**
     * Begin previewing a drag of the given widget.
     *
     * @param {string} widgetId
     */
    startPreview(widgetId) {
        this.draggedId = widgetId;
        this.previewOrder = [...this.widgetOrder];
        this.previewLockedUntil = 0;
        this.lastPreviewPoint = null;
        this.dropCommitted = false;
    },

    /**
     * Move the dragged widget into the slot of the widget under the pointer.
     *
     * Ignored while the previous move is still animating, and until the
     * pointer has moved a little since that move, so a widget sliding under a
     * stationary pointer can't bounce the order back and forth.
     *
     * @param {string|null} targetId - Widget currently under the pointer.
     * @param {number} x - Pointer clientX.
     * @param {number} y - Pointer clientY.
     */
    movePreview(targetId, x, y) {
        if (!this.previewOrder || !targetId || targetId === this.draggedId) {
            return;
        }

        const lockRemaining = this.previewLockedUntil - performance.now();

        if (lockRemaining > 0) {
            // Mid-slide, hit testing still sees widgets at their old positions,
            // so this target may be stale. Once the slide finishes, look again
            // at what is really under the pointer (the browser may send no
            // further drag events if the pointer stays still).
            clearTimeout(this.previewRetryTimer);
            this.previewRetryTimer = setTimeout(() => {
                this.movePreview(this.findDropTarget(x, y)?.dataset.widgetId ?? null, x, y);
            }, lockRemaining + 10);
            return;
        }

        if (this.lastPreviewPoint && Math.hypot(x - this.lastPreviewPoint.x, y - this.lastPreviewPoint.y) < 8) {
            return;
        }

        const from = this.previewOrder.indexOf(this.draggedId);
        const to = this.previewOrder.indexOf(targetId);

        if (from < 0 || to < 0 || from === to) {
            return;
        }

        const nextOrder = [...this.previewOrder];
        nextOrder.splice(from, 1);
        nextOrder.splice(to, 0, this.draggedId);

        this.lastPreviewPoint = { x, y };
        this.applyPreview(nextOrder);
        this.announce(`Widget will move to position ${to + 1} of ${nextOrder.length}.`);
    },

    /**
     * Show a new preview order, animating every item from where it was (FLIP).
     *
     * @param {string[]|null} nextOrder - Order to show, or null to restore the saved order.
     */
    applyPreview(nextOrder) {
        const duration = 200;
        const items = [...this.$root.querySelectorAll('[data-widget-id]')];
        const before = new Map(items.map((el) => [el, el.getBoundingClientRect()]));

        this.previewOrder = nextOrder;
        this.previewLockedUntil = performance.now() + duration;

        this.$nextTick(() => {
            for (const el of items) {
                const from = before.get(el);
                const to = el.getBoundingClientRect();
                const dx = from.left - to.left;
                const dy = from.top - to.top;

                if (dx === 0 && dy === 0) {
                    continue;
                }

                el.animate(
                    [{ transform: `translate(${dx}px, ${dy}px)` }, { transform: 'translate(0, 0)' }],
                    { duration, easing: 'cubic-bezier(0.2, 0, 0, 1)' }
                );
            }
        });
    },

    /**
     * Save the previewed order. The preview stays applied until Livewire has
     * re-rendered the widgets in that order, so nothing flickers back.
     */
    commitPreview() {
        // A drop bubbles from the tile to the grid; only the first one counts.
        if (!this.previewOrder || this.dropCommitted) {
            return;
        }

        clearTimeout(this.previewRetryTimer);
        this.previewRetryTimer = null;

        const changed = this.previewOrder.some((id, i) => id !== this.widgetOrder[i]);
        this.dropCommitted = true;

        if (!changed) {
            this.previewOrder = null;
            return;
        }

        const position = this.previewOrder.indexOf(this.draggedId);
        this.widgetOrder = [...this.previewOrder];
        this.announce(`Widget moved to position ${position + 1} of ${this.widgetOrder.length}.`);
        this.persistOrder(() => {
            this.previewOrder = null;
        });
    },

    /**
     * Abandon the preview and slide everything back to the saved order.
     */
    cancelPreview() {
        if (!this.previewOrder) {
            return;
        }

        this.applyPreview(null);
        this.announce('Move cancelled.');
    },

    /**
     * End-of-drag bookkeeping shared by mouse and touch.
     */
    finishPreview() {
        clearTimeout(this.previewRetryTimer);
        this.previewRetryTimer = null;

        if (!this.dropCommitted) {
            this.cancelPreview();
        }

        this.draggedId = null;
        this.lastPreviewPoint = null;
        this.dropCommitted = false;
    },

    // -------------------------------------------------------------------------
    // Bindable attribute objects for x-bind
    // -------------------------------------------------------------------------

    /**
     * Attributes for the sortable container element.
     * Apply with `x-bind="sortableContainer"` on the grid/flex wrapper.
     */
    get sortableContainer() {
        return {
            'role': 'list',
            'aria-label': 'Reorderable widget list',
            // Accept drops in the gaps between widgets too, so the drop
            // commits whatever the preview is showing.
            '@dragover.prevent': () => {},
            '@drop.prevent': () => { this.drop(); },
        };
    },

    /**
     * Returns bindable attributes for each sortable item.
     * Apply with `x-bind="sortableItem(index)"`.
     *
     * @param {number} index - The positional index of this widget.
     * @returns {Object} Attribute map for x-bind.
     */
    /**
     * Returns dynamic class object for a sortable item.
     * Separated from sortableItem() to avoid x-bind spread `:class`
     * conflicting with Livewire morphing (which stringifies objects
     * as [object Object]).
     *
     * @param {number} index
     * @returns {Object} Class map for Alpine :class binding.
     */
    sortableItemClasses(index) {
        return {
            // The dragged tile stays in the grid as the placeholder for where it will land.
            'opacity-50 ring-2 ring-primary ring-offset-2 bg-primary/10': this.draggedIndex === index,
            'ring-2 ring-accent scale-105 shadow-lg': this.keyboardGrabbedIndex === index,
            'cursor-grab': this.enabled && this.draggedIndex === null && this.keyboardGrabbedIndex === null,
            'cursor-grabbing': this.draggedIndex === index,
            'transition-all duration-300 ease-out': true,
        };
    },

    sortableItem(index) {
        return {
            'role': 'listitem',
            'tabindex': this.enabled ? '0' : '-1',
            ':draggable': String(this.enabled),
            ':aria-grabbed': this.keyboardGrabbedIndex === index ? 'true' : 'false',
            '@dragstart': (event) => { this.dragStart(event, index); },
            '@dragover.prevent': (event) => { this.dragOver(event, index); },
            '@dragenter.prevent': (event) => { this.dragEnter(event, index); },
            '@dragleave': (event) => { this.dragLeave(event, index); },
            '@drop.prevent': (event) => { this.drop(event, index); },
            '@dragend': () => { this.dragEnd(); },
            '@touchstart.passive': (event) => { this.touchStart(event, index); },
            '@keydown': (event) => { this.keyDown(event, index); },
        };
    },

    // -------------------------------------------------------------------------
    // HTML5 Drag API handlers
    // -------------------------------------------------------------------------

    /**
     * Handle dragstart: store the dragged index and set transfer data.
     *
     * @param {DragEvent} event
     * @param {number} index
     */
    dragStart(event, index) {
        if (!this.enabled) {
            event.preventDefault();
            return;
        }

        this.draggedIndex = index;
        this.draggedElement = event.currentTarget ?? event.target;
        this.startPreview(this.draggedElement.dataset.widgetId);

        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/plain', String(index));

        if (event.dataTransfer.setDragImage && this.draggedElement) {
            const rect = this.draggedElement.getBoundingClientRect();
            event.dataTransfer.setDragImage(
                this.draggedElement,
                event.clientX - rect.left,
                event.clientY - rect.top
            );
        }

        this.announce(`Picked up widget ${index + 1} of ${this.widgetOrder.length}. Use drag to reorder.`);
    },

    /**
     * Handle dragover: allow drop and update visual target indicator.
     *
     * @param {DragEvent} event
     * @param {number} index
     */
    dragOver(event, index) {
        if (this.draggedIndex === null) {
            return;
        }

        event.dataTransfer.dropEffect = 'move';
        this.movePreview(event.currentTarget?.dataset?.widgetId ?? this.widgetOrder[index], event.clientX, event.clientY);
    },

    /**
     * Handle dragenter for additional visual feedback.
     *
     * @param {DragEvent} event
     * @param {number} index
     */
    dragEnter(event, index) {
        // Targeting happens in dragOver, which also carries the pointer position.
    },

    /**
     * Handle dragleave: clear drop target if leaving this element.
     *
     * @param {DragEvent} event
     * @param {number} index
     */
    dragLeave(event, index) {
        // Nothing to clear: the preview keeps its last order until drop or cancel.
    },

    /**
     * Handle drop anywhere in the grid: commit the previewed order.
     */
    drop() {
        if (this.draggedIndex === null) {
            return;
        }

        this.commitPreview();
    },

    /**
     * Handle dragend: clean up all drag state and visual classes.
     */
    dragEnd() {
        if (this.draggedId !== null) {
            this.finishPreview();
        }

        this.draggedIndex = null;
        this.draggedElement = null;
    },

    // -------------------------------------------------------------------------
    // Touch event handlers (mobile/tablet)
    // -------------------------------------------------------------------------

    /**
     * Handle touchstart: begin a touch-based drag after a brief hold.
     *
     * A 200ms delay prevents accidental drags during normal scrolling.
     *
     * @param {TouchEvent} event
     * @param {number} index
     */
    touchStart(event, index) {
        if (!this.enabled) {
            return;
        }

        const touch = event.touches[0];
        this._touchStartX = touch.clientX;
        this._touchStartY = touch.clientY;
        this._touchIndex = index;
        this._touchElement = event.currentTarget;
        this._touchMoved = false;

        this._touchTimer = setTimeout(() => {
            this.beginTouchDrag(index, touch);
        }, 200);

        document.addEventListener('touchmove', this._boundTouchMove, { passive: false });
        document.addEventListener('touchend', this._boundTouchEnd, { passive: false });
    },

    /**
     * Activate touch dragging: create a visual clone and mark state.
     *
     * @param {number} index
     * @param {Touch} touch
     */
    beginTouchDrag(index, touch) {
        this.isTouchDragging = true;
        this.draggedIndex = index;
        this.startPreview(this._touchElement.dataset.widgetId);

        this.triggerHapticFeedback();

        this.createTouchClone(this._touchElement, touch.clientX, touch.clientY);

        this.announce(`Picked up widget ${index + 1}. Move finger to reorder, lift to drop.`);
    },

    /**
     * Handle touchmove: move the clone and detect drop targets.
     *
     * @param {TouchEvent} event
     */
    touchMove(event) {
        if (!this.isTouchDragging && this._touchTimer) {
            const touch = event.touches[0];
            const deltaX = Math.abs(touch.clientX - this._touchStartX);
            const deltaY = Math.abs(touch.clientY - this._touchStartY);

            if (deltaX > 10 || deltaY > 10) {
                clearTimeout(this._touchTimer);
                this._touchTimer = null;
                this.cleanupTouchListeners();
                return;
            }
        }

        if (!this.isTouchDragging) {
            return;
        }

        event.preventDefault();

        const touch = event.touches[0];
        this.moveTouchClone(touch.clientX, touch.clientY);

        const targetElement = this.findDropTarget(touch.clientX, touch.clientY);
        this.movePreview(targetElement?.dataset.widgetId ?? null, touch.clientX, touch.clientY);
    },

    /**
     * Handle touchend: perform the drop if we were dragging.
     *
     * @param {TouchEvent} event
     */
    touchEnd(event) {
        clearTimeout(this._touchTimer);
        this._touchTimer = null;

        if (this.isTouchDragging) {
            event.preventDefault();

            // Lifting the finger inside the grid commits the preview; outside it cancels.
            const touch = event.changedTouches[0];
            if (touch && this.findDropTarget(touch.clientX, touch.clientY)) {
                this.commitPreview();
            }

            this.cleanupTouchClone();
            this.isTouchDragging = false;
            this.dragEnd();
        }

        this.cleanupTouchListeners();
    },

    /**
     * Create a floating clone of the dragged element for touch feedback.
     *
     * @param {HTMLElement} element - The original element being dragged.
     * @param {number} x - Initial clientX position.
     * @param {number} y - Initial clientY position.
     */
    createTouchClone(element, x, y) {
        this.cleanupTouchClone();

        const rect = element.getBoundingClientRect();
        const clone = element.cloneNode(true);

        clone.style.position = 'fixed';
        clone.style.left = `${rect.left}px`;
        clone.style.top = `${rect.top}px`;
        clone.style.width = `${rect.width}px`;
        clone.style.height = `${rect.height}px`;
        clone.style.zIndex = '9999';
        clone.style.pointerEvents = 'none';
        clone.style.opacity = '0.85';
        clone.style.transform = 'scale(1.03) rotate(1deg)';
        clone.style.boxShadow = '0 10px 25px rgba(0,0,0,0.15)';
        clone.style.transition = 'none';
        clone.setAttribute('aria-hidden', 'true');

        this._touchOffsetX = x - rect.left;
        this._touchOffsetY = y - rect.top;

        document.body.appendChild(clone);
        this.touchClone = clone;
    },

    /**
     * Move the touch clone to follow the finger position.
     *
     * @param {number} x - Current clientX.
     * @param {number} y - Current clientY.
     */
    moveTouchClone(x, y) {
        if (!this.touchClone) {
            return;
        }

        this.touchClone.style.left = `${x - this._touchOffsetX}px`;
        this.touchClone.style.top = `${y - this._touchOffsetY}px`;
    },

    /**
     * Remove the touch clone from the DOM.
     */
    cleanupTouchClone() {
        if (this.touchClone?.parentNode) {
            this.touchClone.remove();
        }
        this.touchClone = null;
    },

    /**
     * Remove global touch event listeners.
     */
    cleanupTouchListeners() {
        document.removeEventListener('touchmove', this._boundTouchMove);
        document.removeEventListener('touchend', this._boundTouchEnd);
    },

    /**
     * Find the sortable item element under the given touch coordinates.
     * Uses `document.elementsFromPoint` and walks up to find
     * `[data-widget-id]` ancestors.
     *
     * @param {number} x
     * @param {number} y
     * @returns {HTMLElement|null}
     */
    findDropTarget(x, y) {
        if (this.touchClone) {
            this.touchClone.style.display = 'none';
        }

        const elements = document.elementsFromPoint(x, y);

        if (this.touchClone) {
            this.touchClone.style.display = '';
        }

        for (const el of elements) {
            const widgetEl = el.closest('[data-widget-id]');
            if (widgetEl) {
                return widgetEl;
            }
        }

        return null;
    },

    /**
     * Get the sort index from a widget element's position in the order array.
     *
     * @param {HTMLElement} element
     * @returns {number|null}
     */
    getIndexFromElement(element) {
        const widgetId = element.dataset.widgetId;
        if (!widgetId) {
            return null;
        }

        const index = this.widgetOrder.indexOf(widgetId);
        return index >= 0 ? index : null;
    },

    /**
     * Attempt to trigger device haptic feedback on supported browsers.
     */
    triggerHapticFeedback() {
        if (navigator.vibrate) {
            navigator.vibrate(50);
        }
    },

    // -------------------------------------------------------------------------
    // Keyboard accessibility
    // -------------------------------------------------------------------------

    /**
     * Handle keyboard events on sortable items.
     *
     * - Tab: normal focus navigation (not intercepted)
     * - Enter/Space: toggle grab mode on the focused widget
     * - Arrow Up/Left: move grabbed widget up one position
     * - Arrow Down/Right: move grabbed widget down one position
     * - Escape: cancel the current keyboard grab
     *
     * @param {KeyboardEvent} event
     * @param {number} index
     */
    keyDown(event, index) {
        if (!this.enabled) {
            return;
        }

        switch (event.key) {
            case 'Enter':
            case ' ':
                event.preventDefault();
                if (this.keyboardGrabbedIndex === null) {
                    this.startKeyboardGrab(index);
                } else if (this.keyboardGrabbedIndex === index) {
                    this.finishKeyboardGrab();
                } else {
                    this.reorder(this.keyboardGrabbedIndex, index);
                    this.announce(`Widget moved to position ${index + 1}.`);
                    this.keyboardGrabbedIndex = null;
                }
                break;

            case 'ArrowUp':
            case 'ArrowLeft':
                event.preventDefault();
                if (this.keyboardGrabbedIndex !== null) {
                    this.keyboardMoveUp();
                }
                break;

            case 'ArrowDown':
            case 'ArrowRight':
                event.preventDefault();
                if (this.keyboardGrabbedIndex !== null) {
                    this.keyboardMoveDown();
                }
                break;

            case 'Escape':
                event.preventDefault();
                this.cancelKeyboardGrab();
                break;
        }
    },

    /**
     * Activate keyboard grab mode for the given index.
     *
     * @param {number} index
     */
    startKeyboardGrab(index) {
        this.keyboardGrabbedIndex = index;
        this.announce(
            `Widget ${index + 1} of ${this.widgetOrder.length} grabbed. ` +
            'Use arrow keys to move, Enter to drop, Escape to cancel.'
        );
    },

    /**
     * Complete the keyboard grab at the current position (no-op reorder).
     */
    finishKeyboardGrab() {
        const index = this.keyboardGrabbedIndex;
        this.keyboardGrabbedIndex = null;
        this.announce(`Widget dropped at position ${index + 1}.`);
    },

    /**
     * Cancel the keyboard grab and restore original order announcement.
     */
    cancelKeyboardGrab() {
        if (this.keyboardGrabbedIndex !== null) {
            this.keyboardGrabbedIndex = null;
            this.announce('Reorder cancelled.');
        }
    },

    /**
     * Move the keyboard-grabbed widget up by one position.
     */
    keyboardMoveUp() {
        if (this.keyboardGrabbedIndex <= 0) {
            this.announce('Already at the top.');
            return;
        }

        const newIndex = this.keyboardGrabbedIndex - 1;
        this.reorder(this.keyboardGrabbedIndex, newIndex);
        this.keyboardGrabbedIndex = newIndex;
        this.announce(`Moved to position ${newIndex + 1} of ${this.widgetOrder.length}.`);

        this.$nextTick(() => {
            this.focusWidgetAtIndex(newIndex);
        });
    },

    /**
     * Move the keyboard-grabbed widget down by one position.
     */
    keyboardMoveDown() {
        if (this.keyboardGrabbedIndex >= this.widgetOrder.length - 1) {
            this.announce('Already at the bottom.');
            return;
        }

        const newIndex = this.keyboardGrabbedIndex + 1;
        this.reorder(this.keyboardGrabbedIndex, newIndex);
        this.keyboardGrabbedIndex = newIndex;
        this.announce(`Moved to position ${newIndex + 1} of ${this.widgetOrder.length}.`);

        this.$nextTick(() => {
            this.focusWidgetAtIndex(newIndex);
        });
    },

    /**
     * Focus the DOM element for the widget at the given index.
     *
     * @param {number} index
     */
    focusWidgetAtIndex(index) {
        const widgetId = this.widgetOrder[index];
        if (!widgetId) {
            return;
        }

        const el = this.$root.querySelector(`[data-widget-id="${widgetId}"]`);
        if (el) {
            el.focus();
        }
    },

    // -------------------------------------------------------------------------
    // Reorder logic and Livewire communication
    // -------------------------------------------------------------------------

    /**
     * Move a widget from one index to another and notify Livewire.
     *
     * @param {number} fromIndex
     * @param {number} toIndex
     */
    reorder(fromIndex, toIndex) {
        if (fromIndex === toIndex) {
            return;
        }

        if (fromIndex < 0 || fromIndex >= this.widgetOrder.length) {
            return;
        }

        if (toIndex < 0 || toIndex >= this.widgetOrder.length) {
            return;
        }

        const item = this.widgetOrder.splice(fromIndex, 1)[0];
        this.widgetOrder.splice(toIndex, 0, item);

        this.persistOrder();
    },

    /**
     * Send the current widget order to the Livewire component.
     * Calls `$wire.reorderWidgets(order)` which the DashboardEditor
     * component should implement.
     */
    persistOrder(onSettled = null) {
        if (wire && typeof wire.reorderWidgets === 'function') {
            const request = wire.reorderWidgets([...this.widgetOrder]);

            if (onSettled) {
                Promise.resolve(request).finally(onSettled);
            }
        } else if (onSettled) {
            onSettled();
        }
    },

    // -------------------------------------------------------------------------
    // Accessibility announcements
    // -------------------------------------------------------------------------

    /**
     * Set a message for screen readers via a live region.
     *
     * @param {string} message
     */
    announce(message) {
        this.announceMessage = message;
        setTimeout(() => {
            this.announceMessage = '';
        }, 3000);
    },
}); }

import { Controller } from '@hotwired/stimulus'

export default class extends Controller {
    static values = {
        collapsed: { type: Boolean, default: false },
    }

    connect() {
        console.log('[accordion] connect', this.element, 'collapsedValue=', this.collapsedValue)

        this.headerEl = this.element.querySelector('.box-header')
        this.bodyEl = this.element.querySelector('.box-body')

        console.log('[accordion] header/body', !!this.headerEl, !!this.bodyEl)

        if (!this.headerEl || !this.bodyEl) return

        this.headerEl.style.cursor = 'pointer'
        this.boundToggle = this.toggle.bind(this)
        this.headerEl.addEventListener('click', this.boundToggle)

        if (this.collapsedValue) {
            console.log('[accordion] collapsing now')
            this.collapse()
        }
    }

    disconnect() {
        if (this.headerEl && this.boundToggle) {
            this.headerEl.removeEventListener('click', this.boundToggle)
        }
    }

    toggle(e) {
        const t = e.target
        const tag = (t?.tagName || '').toLowerCase()
        if (['input', 'select', 'textarea', 'button', 'a', 'label'].includes(tag)) return

        if (this.element.classList.contains('is-collapsed')) {
            this.expand()
        } else {
            this.collapse()
        }
    }

    collapse() {
        this.element.classList.add('is-collapsed')
        this.bodyEl.style.display = 'none'
    }

    expand() {
        this.element.classList.remove('is-collapsed')
        this.bodyEl.style.display = ''
    }
}
@if($interactive ?? false)
    <style>
        #receipt-print-controls {
            position: sticky;
            top: 12px;
            z-index: 10;
            max-width: 720px;
            margin: 16px auto;
            padding: 20px;
            border: 1px solid #cbd5e1;
            border-radius: 12px;
            background: #fff;
            color: #0f172a;
            font: 400 14px/1.4 Arial, sans-serif;
            box-shadow: 0 4px 16px rgba(0, 0, 0, .08);
        }
        #receipt-print-controls h2 { margin: 0 0 8px; font-size: 20px; color: #0f172a; }
        #receipt-print-controls p { margin: 8px 0 12px; }
        #receipt-print-controls button { font: inherit; cursor: pointer; border-radius: 8px; }
        #receipt-print-controls button:focus-visible { outline: 2px solid #0d9488; outline-offset: 3px; }
        #receipt-print-controls button:disabled { opacity: .5; cursor: wait; }
        #receipt-print-controls button[hidden] { display: none; }
        #receipt-print-controls .print-primary {
            display: flex; align-items: center; justify-content: center; gap: 8px;
            width: 100%; min-height: 48px; padding: 12px 16px; border: 0;
            background: #0d9488; color: #fff; font-weight: 700;
        }
        #receipt-print-controls .print-primary:hover { background: #0f766e; }
        #receipt-print-controls .print-secondary {
            min-height: 40px; padding: 8px 12px; margin: 0 8px 12px 0;
            background: #fff; color: #334155; border: 1px solid #cbd5e1;
        }
        #receipt-print-controls [role="alert"] { color: #b91c1c; }
        @media print { #receipt-print-controls { display: none !important; } }
        @media (max-width: 480px) {
            #receipt-print-controls { top: 0; margin: 8px 0; padding: 16px; }
        }
    </style>
    <section id="receipt-print-controls" class="no-print" aria-label="Print customer and company receipts" data-initial-copy="{{ $receiptCopy ?? 'customer' }}">
        <h2>Print receipts</h2>
        <strong role="status" data-print-status>1 of 2: Customer receipt</strong>
        <p data-print-help>Set Copies to 1 in each print dialog. Print the customer receipt first, then the company receipt.</p>
        <p role="alert" data-print-error hidden>Unable to open printing. Please try again.</p>
        <button type="button" class="print-secondary" data-reprint="customer" hidden>Reprint customer receipt</button>
        <button type="button" class="print-secondary" data-reprint="company" hidden>Reprint company receipt</button>
        <button type="button" class="print-primary" data-print-action>
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M6 9V3h12v6M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2M6 14h12v8H6z"/></svg>
            <span data-print-action-label>Print customer receipt</span>
        </button>
        <noscript>Use your browser's Print command to print the labelled copy shown below.</noscript>
    </section>
    <script>
        (() => {
            const setup = () => {
                const panel = document.getElementById('receipt-print-controls');
                const primary = panel.querySelector('[data-print-action]');
                const primaryLabel = panel.querySelector('[data-print-action-label]');
                const status = panel.querySelector('[data-print-status]');
                const help = panel.querySelector('[data-print-help]');
                const error = panel.querySelector('[data-print-error]');
                const retries = panel.querySelectorAll('[data-reprint]');
                let step = panel.dataset.initialCopy === 'company' ? 'company' : 'customer';
                let receiptCopy = step;
                let pending = null;
                let finished = false;

                const render = () => {
                    document.querySelectorAll('[data-receipt-copy]').forEach(label => {
                        label.textContent = receiptCopy === 'customer' ? 'Customer copy' : 'Company copy';
                    });
                    status.textContent = finished ? 'Receipt printing finished' : step === 'complete' ? 'Finish receipt printing' : step === 'customer' ? '1 of 2: Customer receipt' : '2 of 2: Company receipt';
                    help.textContent = finished ? 'You can close this page. Reprint either receipt below if needed.' : step === 'complete' ? 'Check that both receipts printed correctly. Reprint either copy below if needed.' : step === 'company' ? 'Next, print one company receipt. Set Copies to 1. Reprint the customer receipt below if needed.' : 'Print one customer receipt, then continue to the company receipt. Set Copies to 1.';
                    primary.querySelector('svg').style.display = step === 'complete' ? 'none' : '';
                    primaryLabel.textContent = pending ? 'Print dialog open…' : step === 'complete' ? 'Finish' : step === 'customer' ? 'Print customer receipt' : 'Print company receipt';
                    primary.disabled = pending !== null;
                    primary.hidden = finished;
                    retries.forEach(button => {
                        button.disabled = pending !== null;
                        button.hidden = button.dataset.reprint === 'customer' ? step === 'customer' : step !== 'complete';
                    });
                };

                const print = copy => {
                    if (pending !== null) return;
                    pending = copy;
                    receiptCopy = copy;
                    error.hidden = true;
                    render();
                    try {
                        window.print();
                    } catch {
                        pending = null;
                        error.hidden = false;
                        render();
                    }
                };

                window.addEventListener('afterprint', () => {
                    if (pending === null) return;
                    if (pending === 'company') step = 'complete';
                    else if (step === 'customer') step = 'company';
                    pending = null;
                    receiptCopy = 'company';
                    render();
                });
                primary.addEventListener('click', () => {
                    if (step === 'complete') { finished = true; render(); }
                    else print(step);
                });
                retries.forEach(button => button.addEventListener('click', () => print(button.dataset.reprint)));
                render();
            };
            if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', setup, { once: true });
            else setup();
        })();
    </script>
@endif

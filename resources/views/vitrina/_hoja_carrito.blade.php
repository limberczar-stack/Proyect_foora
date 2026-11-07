@php
    /** @var bool $abierto */
@endphp

<dialog id="hoja-carrito" aria-labelledby="hoja-carrito-titulo" data-hoja class="modal hoja m-0 mt-auto w-full max-w-none rounded-t-card bg-surface p-0 text-ink shadow-float backdrop:bg-transparent sm:m-auto sm:max-w-lg sm:rounded-card">
    <div class="flex max-h-[90dvh] flex-col">
        <div class="flex touch-none justify-center pb-1 pt-2.5 sm:hidden" data-hoja-asa aria-hidden="true">
            <span class="h-1 w-10 rounded-full bg-control"></span>
        </div>

        <header class="flex touch-none items-center justify-between gap-4 px-5 pb-3 pt-1 sm:touch-auto sm:pt-4" data-hoja-asa>
            <h2 id="hoja-carrito-titulo" class="text-lg font-bold tracking-tight">Tu carrito</h2>
            <div class="-mr-2 flex items-center gap-1">
                <x-button variant="ghost" size="sm" class="text-danger-ink" data-carrito-vaciar>Vaciar</x-button>
                <x-button variant="ghost" size="icon" data-hoja-cerrar aria-label="Cerrar"><x-icon name="x" /></x-button>
            </div>
        </header>

        <div class="flex-1 overflow-y-auto overscroll-contain px-5 pb-4">
            <div class="flex flex-col items-start gap-3 rounded-control border border-dashed border-control px-4 py-5" data-carrito-vacio hidden>
                <p class="text-sm text-muted">Tu carrito está vacío. Elige algo de la carta para empezar.</p>
                <x-button size="sm" data-hoja-cerrar>Ver la carta</x-button>
            </div>

            <div class="mb-3 flex flex-col items-start gap-3 rounded-control bg-danger-bg/50 p-4" role="alert" data-carrito-error hidden>
                <p class="flex items-start gap-2 text-sm text-ink">
                    <x-icon name="alert" class="mt-0.5 text-danger-ink" />
                    <span data-carrito-error-texto></span>
                </p>
                <x-button size="sm" icon="refresh" data-carrito-reintentar>Reintentar</x-button>
            </div>

            <ul class="flex flex-col divide-y divide-rule" data-carrito-lineas></ul>
        </div>

        <footer class="border-t border-rule px-5 pb-[max(1rem,env(safe-area-inset-bottom))] pt-3" data-carrito-pie>
            @unless ($abierto)
                <p class="mb-2 text-[0.8125rem] text-warning-ink">Este local está cerrado ahora.</p>
            @endunless
            <div class="flex items-baseline justify-between gap-3">
                <span class="text-sm font-medium">Subtotal</span>
                <span class="num text-lg font-bold" data-carrito-subtotal></span>
            </div>
            <p class="mb-3 text-xs text-muted">Sin incluir el costo de delivery.</p>
            <button type="button" disabled data-carrito-continuar class="press flex h-12 w-full cursor-pointer items-center justify-center rounded-control bg-brand-700 px-4 text-[0.9375rem] font-semibold text-white hover:bg-brand-800 disabled:cursor-not-allowed disabled:bg-brand-200 disabled:text-brand-900">Continuar con el pedido</button>
            <p class="mt-1.5 text-center text-xs text-muted" data-carrito-nota hidden></p>
        </footer>
    </div>
</dialog>

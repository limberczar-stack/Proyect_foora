<dialog id="hoja-producto" aria-labelledby="hoja-producto-titulo" data-hoja class="modal hoja m-0 mt-auto w-full max-w-none rounded-t-card bg-surface p-0 text-ink shadow-float backdrop:bg-transparent sm:m-auto sm:max-w-lg sm:rounded-card">
    <div class="flex max-h-[90dvh] flex-col">
        <div class="flex touch-none justify-center pb-1 pt-2.5 sm:hidden" data-hoja-asa aria-hidden="true">
            <span class="h-1 w-10 rounded-full bg-control"></span>
        </div>

        <header class="flex touch-none items-start justify-between gap-4 px-5 pb-3 pt-1 sm:touch-auto sm:pt-4" data-hoja-asa>
            <h2 id="hoja-producto-titulo" class="min-w-0 text-lg font-bold leading-snug tracking-tight" data-detalle-nombre></h2>
            <x-button variant="ghost" size="icon" data-hoja-cerrar aria-label="Cerrar" class="-mr-2 -mt-1.5">
                <x-icon name="x" />
            </x-button>
        </header>

        <div class="flex-1 overflow-y-auto overscroll-contain px-5 pb-4" data-detalle-cuerpo>
            <img alt="" data-detalle-imagen class="mb-3 aspect-[16/9] w-full rounded-control object-cover" hidden>
            <p class="text-sm leading-relaxed text-muted" data-detalle-descripcion></p>
            <p class="mt-1 text-sm font-medium text-brand-700" data-detalle-meta></p>

            <div data-detalle-variantes></div>
            <div data-detalle-grupos></div>

            <div class="mt-5">
                <div class="flex items-baseline justify-between gap-3">
                    <label for="detalle-observacion" class="text-sm font-semibold">Observaciones <span class="font-normal text-muted">(opcional)</span></label>
                    <span class="num text-xs text-muted" data-detalle-contador>0/200</span>
                </div>
                <textarea id="detalle-observacion" rows="2" maxlength="200" data-detalle-observacion placeholder="Ej.: sin ají, por favor"
                    class="mt-2 w-full rounded-control border border-line bg-surface px-3 py-2.5 text-base text-ink placeholder:text-faint focus:border-brand-600 focus:outline-none focus-visible:outline-2 focus-visible:outline-offset-0 focus-visible:outline-brand-600/30 sm:text-sm"></textarea>
            </div>
        </div>

        <footer class="border-t border-rule px-5 pb-[max(1rem,env(safe-area-inset-bottom))] pt-3">
            <p class="mb-2 text-sm font-medium text-danger-ink" role="alert" data-detalle-error hidden></p>
            <div class="flex items-center gap-3">
                <div class="flex shrink-0 items-center gap-1" role="group" aria-label="Cantidad">
                    <button type="button" class="vitrina-paso press" data-detalle-menos aria-label="Quitar uno">−</button>
                    <output class="num w-8 text-center text-base font-semibold" data-detalle-cantidad aria-live="polite">1</output>
                    <button type="button" class="vitrina-paso press" data-detalle-mas aria-label="Agregar uno">+</button>
                </div>
                <button type="button" data-detalle-agregar class="press flex h-12 min-w-0 flex-1 cursor-pointer items-center justify-center gap-2 rounded-control bg-brand-700 px-4 text-[0.9375rem] font-semibold text-white hover:bg-brand-800">
                    <span>Agregar</span>
                    <span class="num" data-detalle-total></span>
                </button>
            </div>
        </footer>
    </div>
</dialog>

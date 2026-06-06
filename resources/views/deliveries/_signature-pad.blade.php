@props([
    'inputName' => 'receiver_signature_data',
    'oldValue' => '',
])

<div class="signature-pad space-y-2" data-signature-pad>
    <label class="block text-sm font-medium text-gray-700" for="{{ $inputName }}-canvas">
        Tanda Tangan Penerima <span class="text-rose-600">*</span>
    </label>
    <div class="rounded-lg border border-gray-300 bg-white shadow-inner">
        <canvas
            id="{{ $inputName }}-canvas"
            data-signature-canvas
            class="block h-40 w-full touch-none cursor-crosshair rounded-lg"
            aria-label="Area tanda tangan penerima"
        ></canvas>
    </div>
    <div class="flex flex-wrap items-center gap-2">
        <button
            type="button"
            data-signature-clear
            class="inline-flex items-center rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2"
        >
            Bersihkan Tanda Tangan
        </button>
        <p class="text-xs text-gray-500">Gunakan jari atau stylus pada perangkat sentuh.</p>
    </div>
    <input
        type="hidden"
        name="{{ $inputName }}"
        value="{{ old($inputName, $oldValue) }}"
        data-signature-input
    >
    @error($inputName)
        <p class="text-sm text-rose-600">{{ $message }}</p>
    @enderror
</div>

@once
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            document.querySelectorAll('[data-signature-pad]').forEach((wrapper) => {
                    const canvas = wrapper.querySelector('[data-signature-canvas]');
                    const hiddenInput = wrapper.querySelector('[data-signature-input]');
                    const clearButton = wrapper.querySelector('[data-signature-clear]');
                    const form = wrapper.closest('form');

                    if (!canvas || !hiddenInput || !form) {
                        return;
                    }

                    const ctx = canvas.getContext('2d');
                    let drawing = false;
                    let hasStroke = false;

                    const resizeCanvas = () => {
                        const ratio = window.devicePixelRatio || 1;
                        const rect = canvas.getBoundingClientRect();
                        canvas.width = Math.floor(rect.width * ratio);
                        canvas.height = Math.floor(rect.height * ratio);
                        ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
                        ctx.lineWidth = 2;
                        ctx.lineCap = 'round';
                        ctx.lineJoin = 'round';
                        ctx.strokeStyle = '#111827';
                    };

                    const getPoint = (event) => {
                        const rect = canvas.getBoundingClientRect();
                        const source = event.touches ? event.touches[0] : event;
                        return {
                            x: source.clientX - rect.left,
                            y: source.clientY - rect.top,
                        };
                    };

                    const startDraw = (event) => {
                        drawing = true;
                        const point = getPoint(event);
                        ctx.beginPath();
                        ctx.moveTo(point.x, point.y);
                        event.preventDefault();
                    };

                    const draw = (event) => {
                        if (!drawing) {
                            return;
                        }
                        const point = getPoint(event);
                        ctx.lineTo(point.x, point.y);
                        ctx.stroke();
                        hasStroke = true;
                        event.preventDefault();
                    };

                    const stopDraw = () => {
                        drawing = false;
                    };

                    const clearSignature = () => {
                        ctx.clearRect(0, 0, canvas.width, canvas.height);
                        hiddenInput.value = '';
                        hasStroke = false;
                    };

                    const syncHiddenInput = () => {
                        if (hasStroke) {
                            hiddenInput.value = canvas.toDataURL('image/png');
                        }
                    };

                    resizeCanvas();
                    window.addEventListener('resize', () => {
                        const previous = hasStroke ? canvas.toDataURL('image/png') : '';
                        resizeCanvas();
                        if (previous) {
                            const image = new Image();
                            image.onload = () => {
                                ctx.drawImage(image, 0, 0, canvas.getBoundingClientRect().width, canvas.getBoundingClientRect().height);
                                hasStroke = true;
                            };
                            image.src = previous;
                        }
                    });

                    canvas.addEventListener('mousedown', startDraw);
                    canvas.addEventListener('mousemove', draw);
                    canvas.addEventListener('mouseup', stopDraw);
                    canvas.addEventListener('mouseleave', stopDraw);
                    canvas.addEventListener('touchstart', startDraw, { passive: false });
                    canvas.addEventListener('touchmove', draw, { passive: false });
                    canvas.addEventListener('touchend', stopDraw);

                    clearButton?.addEventListener('click', clearSignature);

                    form.addEventListener('submit', (event) => {
                        syncHiddenInput();
                        if (!hiddenInput.value) {
                            event.preventDefault();
                            hiddenInput.setCustomValidity('Tanda tangan penerima wajib diisi.');
                            hiddenInput.reportValidity();
                        } else {
                            hiddenInput.setCustomValidity('');
                        }
                    });
            });
        });
    </script>
@endonce

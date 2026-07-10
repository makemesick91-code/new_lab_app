@props([
    'name' => 'product_id',
    'id' => null,
    'products' => collect(),
    'selected' => null,
    'placeholder' => 'Cari kode atau nama produk…',
    'emptyLabel' => 'Pilih produk',
    'required' => false,
    'disabled' => false,
    'class' => '',
    'allowEmpty' => true,
    'multiple' => false,
    'alpineName' => null,
    'model' => null,
    'onSelect' => null,
    'notFoundLabel' => 'Produk tidak ditemukan.',
    'clearLabel' => 'Hapus pilihan produk',
])

@php
    $inputId = $id ?? 'product-select-'.str()->uuid();
    $selectedValue = old($name, $selected);
    $catalog = collect($products)->map(function ($product) {
        $code = (string) ($product->code ?? '');
        $label = trim(collect([$code ?: null, (string) ($product->name ?? '')])->filter()->implode(' - '));

        return [
            'id' => (string) $product->id,
            'code' => $code,
            'name' => (string) ($product->name ?? ''),
            'label' => $label !== '' ? $label : (string) ($product->name ?? $code),
        ];
    })->values()->all();

    $initialSelected = $multiple
        ? (is_array($selectedValue) ? array_map('strval', $selectedValue) : [])
        : (filled($selectedValue) ? (string) $selectedValue : '');

    $alpineConfig = [
        'products' => $catalog,
        'selected' => $initialSelected,
        'placeholder' => $placeholder,
        'emptyLabel' => $emptyLabel,
        'allowEmpty' => $allowEmpty,
        'required' => $required,
        'disabled' => $disabled,
        'multiple' => $multiple,
    ];
@endphp

    @php
        $initWatchers = [];
        if ($model) {
            $initWatchers[] = 'selectedId = String('.$model.' ?? \'\')';
            $initWatchers[] = 'const product = products.find((entry) => String(entry.id) === String(selectedId)); if (product) { query = product.label; }';
            $initWatchers[] = '$watch(\'selectedId\', value => { '.$model.' = value; })';
            $initWatchers[] = '$watch(() => '.$model.', value => {
                const next = String(value ?? \'\');
                if (next !== String(selectedId)) {
                    selectedId = next;
                    const matched = products.find((entry) => String(entry.id) === next);
                    query = matched?.label ?? \'\';
                }
            })';
        }
        if ($onSelect) {
            $initWatchers[] = '$watch(\'selectedId\', () => { '.$onSelect.' })';
        }
    @endphp

<div
    {{ $attributes->merge(['class' => 'relative '.$class]) }}
    x-data="searchableProductSelect(@js($alpineConfig))"
    @if (count($initWatchers) > 0)
        x-init="{{ implode('; ', $initWatchers) }}"
    @endif
    @click.outside="closeDropdown()"
>
    @if ($multiple)
        <template x-for="selectedTag in selectedTags()" :key="selectedTag.id">
            <input type="hidden" name="{{ $name }}" :value="selectedTag.id">
        </template>
    @elseif ($alpineName)
        <input type="hidden" :name="{!! $alpineName !!}" :value="selectedId" @if ($required) :required="required && !selectedId" @endif>
    @else
        <input type="hidden" name="{{ $name }}" :value="selectedId" @if ($required) :required="required && !selectedId" @endif>
    @endif

    <div class="relative">
        <input
            id="{{ $inputId }}"
            type="text"
            autocomplete="off"
            :placeholder="multiple ? @js($placeholder) : (selectedId && !open ? '' : @js($placeholder))"
            :value="multiple ? query : (open ? query : (products.find(p => String(p.id) === String(selectedId))?.label ?? query))"
            @input="onInput($event)"
            @focus="openDropdown()"
            @keydown="onKeydown($event)"
            :disabled="disabled"
            class="block w-full rounded-lg border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500 disabled:bg-gray-50 disabled:text-gray-500"
            @if ($required && ! $multiple) :required="required && !selectedId" @endif
        >
        @if ($allowEmpty && ! $multiple)
            <button
                type="button"
                class="absolute inset-y-0 right-0 flex items-center pr-2 text-gray-400 hover:text-gray-600"
                x-show="selectedId && !disabled"
                @click.stop="clearSelection()"
                tabindex="-1"
                aria-label="{{ $clearLabel }}"
            >
                <span aria-hidden="true">&times;</span>
            </button>
        @endif
    </div>

    <template x-if="multiple && selectedTags().length">
        <div class="mt-2 flex flex-wrap gap-2">
            <template x-for="selectedTag in selectedTags()" :key="'tag-' + selectedTag.id">
                <span class="inline-flex items-center gap-1 rounded-full bg-brand-50 px-2.5 py-1 text-xs font-medium text-brand-800">
                    <span x-text="selectedTag.label"></span>
                    <button type="button" class="text-brand-600 hover:text-brand-800" @click="removeSelected(selectedTag.id)" :disabled="disabled">&times;</button>
                </span>
            </template>
        </div>
    </template>

    <div
        x-show="open && filtered().length > 0"
        x-cloak
        class="absolute z-20 mt-1 max-h-60 w-full overflow-auto rounded-lg border border-gray-200 bg-white py-1 shadow-lg"
        role="listbox"
    >
        <template x-for="(product, index) in filtered()" :key="product.id">
            <button
                type="button"
                class="block w-full px-3 py-2 text-left text-sm hover:bg-brand-50"
                :class="{ 'bg-brand-50 text-brand-800': index === activeIndex }"
                @mousedown.prevent="select(product)"
                @mouseenter="activeIndex = index"
                x-text="product.label"
                role="option"
                :aria-selected="index === activeIndex"
            ></button>
        </template>
    </div>

    <p x-show="open && filtered().length === 0" x-cloak class="absolute z-20 mt-1 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm text-gray-500 shadow-lg">
        {{ $notFoundLabel }}
    </p>
</div>

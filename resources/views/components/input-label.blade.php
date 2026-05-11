@props(['value'])

<label {{ $attributes->merge(['class' => 'eh-input-label']) }}>
    {{ $value ?? $slot }}
</label>

@props(['disabled' => false])

<input @disabled($disabled) {{ $attributes->merge(['class' => 'eh-text-input']) }}>

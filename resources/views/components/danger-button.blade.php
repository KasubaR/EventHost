<button {{ $attributes->merge(['type' => 'submit', 'class' => 'eh-danger-button']) }}>
    {{ $slot }}
</button>
